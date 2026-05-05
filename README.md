# DRS Backend

Laravel API for the DRS document review and signing workflow.

## What This Backend Does

This backend powers the full document lifecycle:

- authentication
- user management
- document assignment and review
- comments
- signatures
- audit logging
- document storage mode configuration

The frontend is a separate app and talks to this backend over `/api`.

## Tech Stack

- PHP 8.2+
- Laravel 12
- SQLite for local development
- MySQL or PostgreSQL recommended for production
- API token auth using a custom bearer token middleware

## Project Structure

- `app/Http/Controllers/Api` - API controllers
- `app/Models` - Eloquent models
- `app/Policies` - authorization rules
- `app/Services` - shared services such as audit logging
- `database/migrations` - schema
- `database/seeders` - seeded demo data
- `routes/api.php` - API routes

## Local Setup

From the `backend/` directory:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

If you are using the bundled SQLite database, make sure this file exists:

```text
database/database.sqlite
```

If it does not exist yet, create it before running migrations.

## Environment Variables

These are the important environment variables for this project:

```env
APP_NAME=DRS
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
FILESYSTEM_DISK=local

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
```

For production, update the following as needed:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://your-domain.example`
- database connection settings
- file storage settings
- mail settings

## Seeded Demo Data

The default seeder creates:

- admin user
- regular user
- sample document
- document storage mode setting

Default credentials:

- admin: `admin` / `admin123`
- user: `user` / `user123`

The seeded sample document is assigned to the regular user.

## Authentication

This API uses a bearer token.

### Login

```http
POST /api/login
Content-Type: application/json
```

Body:

```json
{
  "username": "admin",
  "password": "admin123"
}
```

Response:

```json
{
  "token": "random-token",
  "user": {
    "id": "1",
    "username": "admin",
    "email": "admin@example.com",
    "role": "admin",
    "createdAt": "2026-04-14T00:00:00.000000Z"
  }
}
```

Use the token in every protected request:

```http
Authorization: Bearer your-token-here
Accept: application/json
```

### Session Endpoints

- `GET /api/me`
- `PUT /api/me`
- `POST /api/logout`

`PUT /api/me` lets a user update their own username, email, and optionally password.

## API Overview

All protected routes require the bearer token.

### Users

- `GET /api/users`
- `POST /api/users`
- `GET /api/users/{user}`
- `PUT /api/users/{user}`
- `DELETE /api/users/{user}`

Query parameters supported by `GET /api/users`:

- `page`
- `per_page`
- `search`
- `role`
- `all=1` for the full list

### Documents

- `GET /api/documents`
- `POST /api/documents`
- `GET /api/documents/{document}`
- `GET /api/documents/{document}/file`
- `POST /api/documents/{document}/acknowledge`
- `POST /api/documents/{document}/invite-signature`
- `POST /api/documents/{document}/reassign`
- `POST /api/documents/{document}/days`
- `POST /api/documents/{document}/status`
- `DELETE /api/documents/{document}`

The document file endpoint is protected by bearer-token auth, streams PDFs inline, and is intended for the assigned reviewer or an admin.

Query parameters supported by `GET /api/documents`:

- `page`
- `per_page`
- `search`
- `status`
- `storage_mode`
- `user_id`
- `all=1` for the full list

### Comments

- `GET /api/documents/{document}/comments`
- `POST /api/documents/{document}/comments`
- `DELETE /api/documents/{document}/comments/{comment}`

### Signatures

- `GET /api/documents/{document}/signatures`
- `POST /api/documents/{document}/signatures`

### Audit Logs

- `GET /api/audit-logs`
- `GET /api/audit-logs/{auditLog}`
- `DELETE /api/audit-logs`

### Storage Settings

- `GET /api/settings/document-storage-mode`
- `PUT /api/settings/document-storage-mode`

## Document Storage Modes

The backend supports three storage modes:

- `base64`
- `upload`
- `auto`

### Base64

Use inline file data.

Send:

- `file_data`
- optional `file_name`
- optional `file_type`
- optional `file_size`
- optional `content`

### Upload

Use multipart upload with a real file.

Send:

- `file` as `multipart/form-data`
- `file_name`
- `file_type`
- `file_size`
- optional `content`

### Auto

Auto prefers real file upload when a file is present and falls back to inline base64 when needed.

## Creating a Document

`POST /api/documents`

Required fields:

- `user_id`
- `title`
- `days_allowed`

Upload or base64 fields:

- `file` for multipart upload
- `file_data` for base64 payload

Text content:

- `content` is required only when the document has no file payload

Examples:

### Multipart upload

```http
POST /api/documents
Content-Type: multipart/form-data
Authorization: Bearer token
```

Fields:

- `user_id`
- `title`
- `content`
- `days_allowed`
- `file`
- `file_name`
- `file_type`
- `file_size`

### Base64 upload

```http
POST /api/documents
Content-Type: application/json
Authorization: Bearer token
```

Body:

```json
{
  "user_id": 2,
  "title": "Contract Review",
  "content": "",
  "days_allowed": 7,
  "file_name": "contract.pdf",
  "file_type": "application/pdf",
  "file_size": 120044,
  "file_data": "data:application/pdf;base64,JVBERi0xLjc..."
}
```

## Permission Model

High-level rules:

- admins can manage users
- admins can manage documents and audit logs
- users can update their own profile
- assigned reviewers can review, comment, acknowledge, and sign their own documents
- document review access is enforced by policy; other users receive 403 responses
- audit log access is restricted by policy

## Pagination and Filters

The API returns paginated responses for list endpoints.

Response shape:

```json
{
  "data": [],
  "meta": {
    "currentPage": 1,
    "perPage": 10,
    "total": 0,
    "lastPage": 0,
    "from": null,
    "to": null
  }
}
```

Use `page` and `per_page` to control pagination.

## Frontend Integration Notes

The frontend expects:

- `VITE_API_BASE_URL` pointing at this backend, usually `http://localhost:8000/api`
- bearer token storage in the browser
- JSON responses with `data` and optional `meta`
- document responses include `fileData` for PDF rendering; uploaded files are stored as base64 in the database and backfilled from storage if an older row is missing it

If the frontend and backend are hosted on different domains, make sure CORS is configured correctly.

## Testing

Run the backend test suite with:

```bash
php artisan test
```

The current suite covers:

- auth
- admin user management
- document workflow
- comments
- signatures
- storage settings
- profile and password updates
- authorization checks

## Deployment Checklist

Before deploying:

1. Set production environment values in `.env`
2. Run `composer install --no-dev --optimize-autoloader`
3. Generate or set `APP_KEY`
4. Configure the production database
5. Run `php artisan migrate --force`
6. Seed only if you want demo data in production
7. Ensure storage is writable
8. Configure mail if you want real notifications
9. Configure your web server to point at Laravel `public/`
10. Set up HTTPS

Recommended production commands:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

If you are using real file uploads:

- keep `FILESYSTEM_DISK=local` or configure a persistent production disk
- make sure the storage path survives deploys
- back up uploaded files separately from the database

## Web Server Notes

Point your web server document root to:

```text
backend/public
```

Make sure the server rewrites requests to Laravel’s `index.php`.

## Common Troubleshooting

### "Table does not exist"

Run migrations:

```bash
php artisan migrate
```

### "Unable to load dashboard"

Check:

- database connection
- token auth
- migrations
- seeded demo data

### File uploads fail

Check:

- `storage/app/private` is writable
- `app_settings` table exists
- `document_storage_mode` setting is initialized
- the selected mode matches the payload you are sending

### Login fails after deployment

Check:

- `APP_KEY`
- `APP_URL`
- database records for the seeded user
- the browser is sending the token back in `Authorization`

## Useful Commands

```bash
php artisan migrate
php artisan migrate:fresh --seed
php artisan test
php artisan tinker
```

## Related Frontend

The frontend lives in `../frontend`.

For a full end-to-end run:

1. start the backend
2. start the frontend
3. log in with the seeded admin or user account
4. verify documents, comments, signatures, audit logs, and storage settings

git remote add lhoyal https://github.com/EmmanuelElom/drs-backend.git
git push -u lhoyal main
