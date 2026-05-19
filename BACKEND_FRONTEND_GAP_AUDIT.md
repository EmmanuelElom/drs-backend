# Backend Gap Audit for Frontend Live + JWT

Date: 2026-05-18

## Scope

This document audits the current `backend/` Laravel application against the active frontend feature set and lists the backend work required to make the frontend usable in a live environment with JWT-based authentication.

The active frontend scope is the source of truth. The legacy `frontend_old/` tree should not drive backend design unless a specific compatibility decision is made.

This audit is written against the active document, auth, admin, viewer, and testing components, including `DocumentLibrary`, `DocumentViewer`, `SecurePDFViewer`, `SignatureCanvas`, `SignatureCreationPopup`, `SignatureFieldPlacer`, `TestingHelper`, and `AuditLogs`.

## Executive Summary

The backend is a solid legacy base for a document review workflow, but it is not yet a complete backend for the current frontend.

The main blockers are:

- Authentication is not JWT-based. The API currently uses a custom opaque bearer token stored as a SHA-256 hash on the user row.
- There is no self-service registration endpoint.
- There is no public invitation/access-token workflow for unauthenticated review and signing.
- The data model only supports a single-assignee review/sign flow. It does not support saved drafts, document library ownership, multiple recipients, field placement, or invitation lifecycle tracking.
- Comments are not threaded.
- Signatures are not tied to invitation records or signature fields.
- The backend does not yet provide the dashboard stats and activity feeds the frontend expects.
- Email delivery, queue handling, and notification flows are not production-hardened for the live frontend flow.

In short: the backend already covers pieces of the older review workflow, but it still needs a broader document-lifecycle platform before the frontend can run live.

## What The Backend Already Has

The current backend already provides a useful foundation:

- User model and role support.
- Admin CRUD for users.
- Basic document CRUD.
- File upload and base64-backed document storage support.
- Document review acknowledgement.
- Signature submission for authenticated users.
- Comment storage for selected text.
- Audit logging.
- Global document storage mode setting.
- Policies for legacy review/sign permissions.
- Queueable invitation mail support.

That said, most of these features are shaped around a legacy "assign a document to a user" model, not the newer library/invitation/public-access model used by the frontend.

## Frontend Feature Coverage Matrix

| Frontend capability | Current backend status | Backend gap / required work |
|---|---|---|
| Login | Partial | Replace custom bearer token auth with JWT login and token lifecycle handling. |
| Signup | Missing | Add a register endpoint, validation, role defaults, and any account-creation rules. |
| Logout | Partial | JWT logout must revoke or blacklist the active token strategy, not just clear a hash. |
| Me/profile | Partial | Keep profile read/update, but make it work under JWT and align response shape to frontend state needs. |
| Role-aware access | Partial | Preserve admin vs standard user authorization in policies and route middleware. |
| Protected document dashboard | Partial | Add a backend source of truth for library/inbox/sent views, not just legacy assigned docs. |
| Saved document library | Missing | Add draft/save/list/update/archive lifecycle for documents owned by a user. |
| Document upload | Partial | Keep file upload, but standardize storage, validation, and preview metadata for production use. |
| Base64 upload fallback | Partial | Support only as a compatibility path; production should prefer file upload and storage abstraction. |
| Send invitations | Partial | Split save from send. Invitation creation must be separate from document creation. |
| Multi-recipient invitations | Missing | Add recipient records, invitation order, and per-recipient status tracking. |
| Public access via token | Missing | Add tokenized access routes for guests with expiry and revocation support. |
| Review document flow | Partial | Preserve review, but map it to invitation/document states used by the frontend. |
| Threaded comments | Missing | Add parent-child comment support and richer annotation metadata. |
| Signature capture | Partial | Bind signatures to invitations or fields, not only to authenticated users. |
| Signature field placement | Missing | Add backend storage for page coordinates, dimensions, recipient assignment, and required/optional state. |
| Document completion | Partial | Add explicit completion tracking for review and signature workflows. |
| Admin dashboard stats | Missing | Provide summary metrics and recent activity endpoints. |
| Audit logs | Partial | Expand filters, pagination, and export support for frontend admin use. |
| Document storage mode | Partial | Keep the setting, but align it with production file storage and preview behavior. |
| Secure PDF viewer | Partial | Serve files only through authorized endpoints or controlled signed URLs. |
| Email notifications | Partial | Add real invite, reminder, and completion mail flows with queue support. |
| Testing helper | Missing | Add dev-only seed/demo endpoints or fixtures if the frontend relies on them. |
| No-download/no-copy posture | Partial | Backend must not expose raw public files; client restrictions alone are not enough. |

## JWT Authentication Requirements

JWT is a hard requirement for the live frontend. The current API auth design is not enough because it stores a random token hash directly on `users` and checks it in custom middleware.

### Required JWT Work

- Add a JWT auth package and configure it for the API guard.
- Add the package dependency to `composer.json`.
- Define the JWT guard/provider setup in `config/auth.php`.
- Replace `AuthenticateApiToken` with JWT middleware/guard handling.
- Add `register`, `login`, `refresh`, `logout`, and `me` flows that return JWT-compatible responses.
- Define access token TTL and refresh token TTL.
- Decide how revocation will work.
- Emit role information in a secure way, but keep authorization checks server-side.
- Return consistent JSON payloads so the frontend can store the authenticated user and token cleanly.
- Verify that `config/cors.php` allows the `Authorization` header for the frontend origin.

### Token Lifecycle Requirements

- Login must issue a JWT access token.
- If refresh tokens are used, refresh must rotate tokens safely.
- Logout must invalidate the current session according to the chosen revocation strategy.
- Expired tokens must return clear 401 responses.
- The frontend must be able to restore auth state after reload using the token contract.

### Revocation Strategy

JWT by itself is stateless, so logout is not enough unless the backend also supports one of the following:

- A token blacklist.
- Refresh-token storage with server-side revocation.
- A user token version field that can invalidate older claims.

This matters because the frontend will expect logged-out users to lose access immediately, especially on admin routes.

### Migration Notes

- Existing users currently have `api_token_hash` values.
- A JWT cutover will likely force re-authentication for active sessions.
- If you want a soft migration, keep the old auth path temporarily behind a compatibility flag, but the frontend should stop depending on it as soon as JWT is live.

## Data Model Gaps

The current schema reflects a single-reviewer workflow. The frontend needs a more complete document lifecycle model.

### Users

- Current backend has `api_token_hash` and `api_token_last_used_at` fields.
- Those fields should be retired or relegated to migration compatibility.
- JWT auth should use a proper token strategy instead of storing long-lived opaque tokens on the user record.
- Role handling should stay explicit so admin-only routes remain protected.

### Documents

The current documents table is not enough for the frontend library and invitation flows.

Required changes:

- Add a clear owner/creator concept separate from reviewer/assignee.
- Add draft/saved states.
- Add sent/invited/completed/archived lifecycle states as needed.
- Add completion timestamps for both review and signature phases.
- Keep `file_path`, `file_disk`, `file_name`, `file_size`, and `file_type`, but treat them as production storage metadata.
- Retain `document_uuid` as the public-safe identifier.
- Add explicit links to invitations and document fields if the frontend uses them.

### Invitations / Recipients

There is no first-class invitation table today, but the frontend expects one.

Required invitation data should include:

- Document reference.
- Recipient name and email.
- Recipient role, such as reviewer or signer.
- Invitation token hash.
- Expiry date.
- Sent timestamp.
- Opened timestamp.
- Completed timestamp.
- Invite status.
- Recipient order for sequential signing, if needed.
- Permission flags for review, comment, and sign actions.

### Document Fields

The frontend includes signature field placement, so the backend needs persistent field records.

Required field data should include:

- Document reference.
- Page number.
- X and Y coordinates.
- Width and height.
- Field type.
- Required flag.
- Recipient or invitation assignment.
- Any field metadata the frontend needs to redraw the document.

### Comments

The current comment schema stores only selected text and the comment body.

Required enhancements:

- Add `parent_comment_id` for threaded replies.
- Add page or annotation metadata if the frontend wants to re-anchor comments in a PDF viewer.
- Add optional resolved status if the UI needs comment resolution.
- Add author metadata that can support both authenticated users and public invitees.

### Signatures

The signatures table is not yet rich enough for the live signing flow.

Required enhancements:

- Bind signatures to an invitation or document field.
- Store signer identity in a way that supports both authenticated users and public recipients.
- Add signature completion state.
- Track signature placement metadata if the frontend draws signatures on top of a PDF field.
- Keep IP address and signed timestamp.

### Audit Logs

The audit log table is useful, but the frontend admin experience needs more structured event data.

Recommended changes:

- Add a machine-readable event type.
- Add a metadata JSON payload for flexible UI rendering.
- Keep actor, target, document, IP, and timestamp data.
- Support pagination and filtering at the API layer.

## API Surface To Add Or Change

The route list in the next section is indicative. Exact naming can vary, but the backend must provide equivalent capabilities.

## Frontend Contract Assumptions

To integrate cleanly with the active frontend, the backend should standardize on these contract rules:

- Authenticated SPA requests use `Authorization: Bearer <jwt>`.
- Public review/sign access uses a separate invitation token, not JWT.
- Public and authenticated resources should both be addressable with stable UUID-style identifiers where possible.
- Document responses should include explicit permission flags such as `canEdit`, `canReview`, `canComment`, `canSign`, and `canDelete` when the frontend needs them.
- List endpoints should return pagination metadata and filtering support.
- Timestamps should be returned in a consistent ISO 8601 UTC format.
- Validation errors should be field-based so form components can map them directly.
- File access should be served through controlled API routes or expiring URLs, not raw storage exposure.
- Dashboard and viewer screens should receive enough metadata in one response to avoid excessive client-side guesswork.

### Authentication

- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/refresh`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `PUT /api/auth/me`

### Documents

- `GET /api/documents`
- `POST /api/documents` for save/draft creation.
- `GET /api/documents/{document}`
- `PUT /api/documents/{document}`
- `DELETE /api/documents/{document}`
- `POST /api/documents/{document}/send`
- `POST /api/documents/{document}/archive`
- `POST /api/documents/{document}/upload`
- `GET /api/documents/{document}/preview`
- `GET /api/documents/{document}/download`

### Invitations And Public Access

- `POST /api/documents/{document}/invitations`
- `GET /api/documents/{document}/invitations`
- `POST /api/invitations/{invitation}/resend`
- `POST /api/invitations/{invitation}/revoke`
- `GET /api/access/{token}`
- `POST /api/access/{token}/review`
- `POST /api/access/{token}/comment`
- `POST /api/access/{token}/sign`
- `POST /api/access/{token}/complete`

### Comments

- `GET /api/documents/{document}/comments`
- `POST /api/documents/{document}/comments`
- `PUT /api/comments/{comment}`
- `DELETE /api/comments/{comment}`

### Signatures And Fields

- `GET /api/documents/{document}/fields`
- `POST /api/documents/{document}/fields`
- `PUT /api/fields/{field}`
- `DELETE /api/fields/{field}`
- `POST /api/documents/{document}/signatures`
- `GET /api/documents/{document}/signatures`

### Admin And Reporting

- `GET /api/admin/dashboard`
- `GET /api/audit-logs`
- `GET /api/audit-logs/{log}`
- `GET /api/audit-logs/export`
- `GET /api/settings/document-storage-mode`
- `PUT /api/settings/document-storage-mode`
- `GET /api/users`
- `POST /api/users`
- `GET /api/users/{user}`
- `PUT /api/users/{user}`
- `DELETE /api/users/{user}`

### What The Existing API Needs To Change

- `DocumentController::store()` should not auto-send invitations when creating a draft.
- `DocumentController::show()` should return invitation, field, and state metadata the frontend needs.
- `SignatureController::store()` should be able to sign through the invitation/public flow, not only authenticated user flow.
- `CommentController` should support replies and richer annotation context.
- `AuditLogController` should not hard-cap "all" results at a small number if the frontend needs export-grade access.

## Notification And Email Gaps

The frontend expects real-world notifications, not simulated behavior.

Required backend mail/notification support:

- Invitation email for reviewers and signers.
- Completion notification when review or signing is finished.
- Optional reminder emails for pending invitations.
- Proper queue worker support for all mail jobs.
- Production mail transport configuration.

The current invitation mailer is a useful starting point, but the mail content and links need to reflect the new token-based access flow.

## Security And File Handling Gaps

The live backend should not rely on frontend-only security assumptions.

Required work:

- Do not expose raw storage URLs to unauthorized users.
- Serve protected documents through authenticated or token-validated endpoints.
- Use expiring signed URLs if direct file delivery is needed.
- Validate MIME type, extension, and size for uploads.
- Separate production storage from base64 demo handling.
- Log document access, downloads, and public token use.
- Rate-limit auth and invite endpoints.
- Consider watermarking or controlled preview delivery if the frontend requires stronger anti-exfiltration behavior.

## Dashboard And Activity Gaps

The frontend dashboard expects live numbers and recent activity, not only document detail endpoints.

Required backend outputs:

- Total documents.
- Drafts/saved documents.
- Pending review items.
- Pending signature items.
- Completed items.
- Overdue or expiring documents.
- Recent activity feed.
- Recent audit events.
- User-level and admin-level summaries if the UI shows both.

These should come from backend aggregation endpoints, not from frontend-side localStorage calculations.

## Testing Gaps

The current backend tests are useful but only cover the legacy workflow.

Missing test coverage should include:

- JWT login, refresh, logout, and invalid token behavior.
- Register/signup flow.
- Draft creation and document save/send separation.
- Multi-recipient invitation creation and access.
- Public token review, comment, and sign flows.
- Threaded comments.
- Signature field creation and completion.
- Dashboard stats endpoints.
- Audit log export and filtering.
- Production file upload and preview/download authorization.
- Notification mail dispatch and queue handling.

## Current Backend Files That Need Attention

These are the most relevant backend files to refactor or extend:

- `backend/routes/api.php`
- `backend/app/Http/Middleware/AuthenticateApiToken.php`
- `backend/app/Http/Controllers/Api/AuthController.php`
- `backend/app/Http/Controllers/Api/DocumentController.php`
- `backend/app/Http/Controllers/Api/CommentController.php`
- `backend/app/Http/Controllers/Api/SignatureController.php`
- `backend/app/Http/Controllers/Api/AuditLogController.php`
- `backend/app/Http/Controllers/Api/UserController.php`
- `backend/app/Http/Controllers/Api/DocumentStorageSettingController.php`
- `backend/app/Services/AuditLogger.php`
- `backend/app/Policies/DocumentPolicy.php`
- `backend/config/auth.php`
- `backend/config/cors.php`
- `backend/composer.json`
- `backend/bootstrap/app.php`
- `backend/app/Mail/DocumentInvitationMail.php`
- `backend/resources/views/emails/document-invitation.blade.php`
- `backend/resources/views/emails/document-invitation-text.blade.php`
- `backend/database/migrations/0001_01_01_000000_create_users_table.php`
- `backend/database/migrations/2026_04_14_000001_create_documents_table.php`
- `backend/database/migrations/2026_04_14_000002_create_comments_table.php`
- `backend/database/migrations/2026_04_14_000003_create_signatures_table.php`
- `backend/database/migrations/2026_04_14_000004_create_audit_logs_table.php`
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/tests/Feature/ApiWorkflowTest.php`
- `backend/tests/Unit/DocumentPolicyTest.php`

Also review any audit logging paths that still assume a `username` variable is in scope during comment deletion or similar actions; that is a bug that should be fixed before live rollout.

## Recommended Implementation Order

### Phase 1

- Replace the current custom bearer-token auth with JWT.
- Add registration and refresh/logout endpoints.
- Update auth middleware, guards, and frontend contract.
- Decide token revocation and rotation rules.

### Phase 2

- Introduce the missing document lifecycle and invitation schema.
- Separate save/draft from send/invite.
- Add recipient and field models.
- Add public token access.

### Phase 3

- Add threaded comments.
- Add signature completion tracking and field binding.
- Update invitation email templates and completion mail.
- Update policies so reviewer, signer, guest, and admin permissions are all explicit.

### Phase 4

- Add dashboard summary endpoints and richer audit APIs.
- Improve file delivery security.
- Add queue workers, mail transport, and production settings.

### Phase 5

- Expand automated tests for all live frontend flows.
- Remove or isolate demo-only routes and seed assumptions.
- Validate the final API contract against the frontend route map.

## Acceptance Criteria For "Frontend Live"

The backend can be considered ready for the current frontend only when all of the following are true:

- Users can register, log in, refresh, and log out using JWT.
- The frontend can load a live document library from the backend.
- A user can save a document as a draft and later send invitations separately.
- Invitations can target one or more recipients.
- Guests can open documents through a tokenized access flow without a backend account.
- Review comments support replies and are stored with enough metadata to re-render in the viewer.
- Signatures can be attached to invitation/field records and can complete a document lifecycle.
- Admins can view dashboard stats and audit history.
- Emails are sent by real backend jobs, not simulated locally.
- All document access is enforced server-side.
- The current frontend no longer depends on localStorage as its source of truth.

## Notes

- The current `api_token_hash` approach should be treated as legacy.
- The current direct review/sign link behavior in email is not enough for the newer tokenized access flow.
- `base64-check` should be treated as a debug-only route, not a production feature.
- Existing review-workflow tests are a good base, but they do not cover the current frontend scope.
