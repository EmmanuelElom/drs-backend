# Backend Implementation Checklist

This checklist maps the active frontend feature set to the backend work needed to make the app live with JWT auth and API-backed persistence.

## LocalStorage To Backend Mapping

| Frontend key | Backend replacement | Status |
|---|---|---|
| `users` | `users` table + `/api/users` | Already supported |
| `currentUser` | JWT auth payload + `/api/auth/me` | Already supported |
| `documents` | `documents` table + `/api/documents` | Already supported |
| `savedDocuments` | `documents` table with `status=draft|saved` | Already supported |
| `documentInvitations` | `document_invitations` table + invitation APIs | Already supported |
| `comments` | `comments` table + threaded replies | Already supported |
| `signatures` | `signatures` table + signature fields | Already supported |
| `auditLogs` | `audit_logs` table + `/api/audit-logs` | Already supported |
| `sentEmails` | `notification_events` table + queued mail | Already supported |

## Frontend Route To Backend Map

| Frontend route | Required backend support | Status |
|---|---|---|
| `/login` | `POST /api/auth/login`, `GET /api/auth/me` | Already supported |
| `/signup` | `POST /api/auth/register` | Already supported |
| `/dashboard` | document library, dashboard stats, recent activity, notifications | Already supported |
| `/documents` | list/create/update/archive/search/filter docs | Already supported |
| `/review/:documentId` | assignment flow, comments, preview, acknowledge review | Already supported |
| `/sign/:documentId` | signatures, fields, completion tracking | Already supported |
| `/access` | public invitation token access flow | Already supported |
| `/admin` | admin dashboard, users, audit logs, settings | Already supported |

## Feature Status Legend

- `already supported`: backend already has the behavior and data contract
- `partially supported`: some pieces exist, but the live frontend still needs backend work
- `missing`: no usable backend support exists yet
- `must be refactored`: backend exists, but the shape is legacy-only and must change

## Feature Checklist

### Auth

- [x] Login endpoint exists
- [x] JWT access tokens
- [x] JWT refresh
- [x] JWT logout revocation
- [x] Register endpoint
- [x] Rate limiting for auth
- [x] Failed-login auditing

### Document Library

- [x] Draft/save documents
- [x] List/search/filter documents by scope
- [x] Update metadata
- [x] Archive documents
- [x] Secure preview/download
- [x] Upload validation and storage abstraction

### Invitations

- [x] Multi-recipient invites
- [x] Invite tokens
- [x] Public access without JWT
- [x] Invite resend/revoke
- [x] Invitation lifecycle tracking

### Comments

- [x] Threaded comments
- [x] Public comments via invite token
- [x] Annotation metadata

### Signatures

- [x] Signature field placement
- [x] Public signing via invitation token
- [x] Required field enforcement
- [x] Signature completion status

### Admin

- [x] Dashboard stats endpoint
- [x] Audit log filtering/export
- [x] User management under JWT
- [x] Storage mode settings

### Notifications

- [x] Queued invitation emails
- [x] Completion notifications
- [x] Dev/test notification event storage

## Proposed Migration Order

1. JWT auth and token versioning
2. Add document ownership/lifecycle columns
3. Create invitation, assignment, field, and notification tables
4. Extend comments, signatures, and audit logs
5. Update document library endpoints
6. Add public invitation routes
7. Add threaded comments
8. Add signature fields and signing flows
9. Add legacy assignment compatibility endpoints
10. Add admin dashboard and audit improvements
11. Add mail/notifications and secure file handling
12. Add seeders and demo-data command
13. Add API resources and response transformers
14. Expand tests to cover every frontend-critical flow

## Notes

- The backend should stop treating `api_token_hash` as the source of truth.
- Legacy assignment endpoints should remain available until the frontend fully switches to the new library/invitation flow.
- Public access must use invitation tokens, not JWT.
