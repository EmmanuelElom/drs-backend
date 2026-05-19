Prompt 1 — Backend Contract Audit Before Coding

You are working on a Laravel backend for a secure document review and signature platform.

Before changing code, inspect the existing backend and frontend integration assumptions.

Reference these product requirements:
- The frontend currently uses localStorage for users, documents, savedDocuments, documentInvitations, comments, signatures, auditLogs, and sentEmails.
- The backend must replace all demo/localStorage-backed data with real API-backed persistence.
- Active frontend routes include /login, /signup, /dashboard, /documents, /review/:documentId, /sign/:documentId, /access, and /admin.
- Backend must support JWT auth, saved document library, document assignments, tokenized invitations, threaded comments, signatures, signature fields, audit logs, dashboard stats, protected file preview/download, and real notifications.

Task:
1. Review existing Laravel backend routes, controllers, models, migrations, policies, middleware, tests, seeders, mail classes, and config files.
2. Identify any existing implementation that overlaps with the required features.
3. Do not remove working legacy assignment/review/sign flows unless replacing them with compatible behavior.
4. Create an implementation checklist inside `backend/IMPLEMENTATION_BACKEND_FRONTEND_GAPS.md`.
5. The checklist must map each frontend localStorage key to the backend entity/API that will replace it.
6. The checklist must map each active frontend route to required backend endpoints.
7. Mark gaps as:
   - already supported
   - partially supported
   - missing
   - must be refactored
8. Include a proposed migration order that avoids breaking existing tests.

Testing:
- Run the existing backend test suite before making changes.
- Record current failing tests, if any, separately from new failures.
- Do not implement functional changes in this step.

Prompt 2 — Implement JWT Authentication End-to-End

Implement JWT authentication for the Laravel API.

Current backend uses a custom opaque bearer token. Replace it with JWT-based authentication while preserving role-based access.

Requirements:
1. Add and configure a Laravel-compatible JWT package.
2. Configure API guard/provider in `config/auth.php`.
3. Replace custom token middleware with JWT auth middleware.
4. Implement these endpoints:
   - POST /api/auth/register
   - POST /api/auth/login
   - POST /api/auth/refresh
   - POST /api/auth/logout
   - GET /api/auth/me
   - PUT /api/auth/me
5. Login must accept username and password.
6. Signup must create regular users only.
7. Admin users must only be created by admin endpoints or seeders.
8. Return a consistent JSON response:
   {
     "user": {
       "id": "...",
       "username": "...",
       "email": "...",
       "role": "user|admin",
       "createdAt": "ISO_DATE"
     },
     "token": "...",
     "tokenType": "Bearer",
     "expiresIn": seconds
   }
9. Logout must invalidate/revoke the active token according to the JWT package strategy.
10. Refresh must rotate or refresh tokens safely.
11. Expired or invalid tokens must return clear 401 JSON.
12. CORS must allow Authorization headers.
13. Remove frontend dependency on demo credentials as backend truth, but keep seed users for development only:
   - admin/admin123
   - user/user123

Security:
- Passwords must be hashed.
- Never return password fields.
- Rate-limit login and register endpoints.
- Audit login, logout, failed login, and registration events.

Testing:
Create feature tests for:
- register success
- duplicate username/email validation
- login success
- login invalid credentials
- me endpoint with valid JWT
- me endpoint with invalid/expired token
- profile update
- logout invalidates token
- refresh returns usable token
- admin role is preserved
- regular user cannot access admin-only endpoint

Run:
- php artisan test
- ensure all existing tests still pass or are intentionally updated.

Prompt 3 — Create Unified Backend Models and Migrations

Implement the backend data model required to replace frontend localStorage data.

Required entities:
1. users
2. documents
3. document_assignments
4. saved_documents or documents with library lifecycle fields
5. document_invitations
6. document_fields
7. comments
8. signatures
9. audit_logs
10. notification_events if needed for frontend/testing compatibility

Data requirements:

Documents:
- uuid/public identifier
- owner_id / created_by
- title/name
- file_name
- file_type
- file_size
- file_path
- file_disk
- optional file_data/base64 compatibility field only if already required
- content for text documents
- status: draft, saved, sent, in-review, reviewed, signed, completed, archived
- created_at, updated_at, archived_at, completed_at

Document assignments:
- document_id
- user_id
- assigned_by
- assigned_at
- expires_at
- days_allowed
- review_acknowledged
- acknowledged_at
- signature_invited
- signature_invited_at
- signature_completed
- signature_completed_at
- status: pending, in-review, reviewed, signed

Document invitations:
- document_id
- recipient_email
- recipient_name
- invited_by
- invited_at
- expires_at
- access_token_hash
- invitation_type: review, sign
- status: pending, viewed, completed, revoked, expired
- viewed_at
- completed_at
- revoked_at
- recipient_order
- permission flags: can_review, can_comment, can_sign
- signature_data nullable
- metadata JSON

Document fields:
- document_id
- invitation_id nullable
- assigned_recipient_email nullable
- field_type: signature
- page
- x
- y
- width
- height
- required boolean
- metadata JSON

Comments:
- document_id
- invitation_id nullable
- user_id nullable
- author_name
- author_email nullable
- selected_text nullable
- comment/body
- parent_comment_id nullable
- page nullable
- annotation_metadata JSON nullable
- resolved_at nullable

Signatures:
- document_id
- invitation_id nullable
- document_field_id nullable
- user_id nullable
- signer_name
- signer_email nullable
- signature_data
- signed_at
- ip_address
- metadata JSON

Audit logs:
- event_type
- action
- actor_id nullable
- actor_name
- target_user_id nullable
- document_id nullable
- invitation_id nullable
- details
- metadata JSON
- ip_address
- created_at

Implementation:
1. Create/update migrations safely.
2. Add Eloquent models and relationships.
3. Add factories where needed.
4. Add casts for booleans, datetimes, and JSON fields.
5. Add indexes for document owner, invitation token hash, recipient email, status, expiry, and audit log filtering.
6. Preserve existing data if migrations already exist; do not destructively drop tables unless explicitly safe in test/dev.

Testing:
- Migration test: fresh database migrates successfully.
- Model relationship tests.
- Factory creation tests.
- Ensure old tests still pass or are updated to new schema.
- Run php artisan test.

Prompt 4 — Implement Document Library API

Implement the backend API that replaces frontend `savedDocuments`.

Endpoints:
- GET /api/documents
- POST /api/documents
- GET /api/documents/{document}
- PUT /api/documents/{document}
- DELETE /api/documents/{document}
- POST /api/documents/{document}/archive
- POST /api/documents/{document}/upload
- GET /api/documents/{document}/preview
- GET /api/documents/{document}/download

Behavior:
1. GET /api/documents must support filters:
   - scope=library|assigned|sent|completed|archived|all
   - status
   - search
   - page/perPage
2. Regular users only see their own library documents, assigned documents, and sent invitations.
3. Admins can see all documents when scope=all.
4. POST /api/documents creates a draft/saved document but must not send invitations.
5. PUT updates metadata and signature fields only if the authenticated user owns the document or is admin.
6. DELETE must cascade/soft-delete related comments, fields, invitations, and assignment records according to current app rules.
7. Upload must support PDF and text-compatible files.
8. Validate MIME type, extension, and max size.
9. Store production files through Laravel storage.
10. Base64 compatibility may be returned only when needed by frontend migration, but backend storage must be source of truth.
11. Preview/download must enforce authorization server-side.
12. Download should be blocked or permission-controlled if frontend no-download posture requires it.
13. Responses must include frontend-friendly fields:
   - id
   - documentId
   - name/title
   - fileName
   - fileType
   - fileSize
   - createdBy
   - createdAt
   - updatedAt
   - status
   - signatureFields
   - permissions: canEdit, canReview, canComment, canSign, canDelete, canDownload

Audit:
- document_uploaded
- document_created
- document_updated
- document_deleted
- document_archived
- document_previewed
- document_downloaded

Testing:
Create feature tests for:
- create draft document
- list only current user’s documents
- admin can list all
- search/filter/pagination
- upload PDF validation
- reject invalid file
- preview requires authorization
- download requires authorization
- owner can update/delete
- non-owner cannot update/delete
- audit logs are written
- frontend response shape matches contract

Run php artisan test.


Prompt 5 — Implement Multi-Recipient Invitation Workflow

Implement the backend invitation workflow that replaces frontend `documentInvitations` and simulated access tokens.

Endpoints:
- POST /api/documents/{document}/invitations
- GET /api/documents/{document}/invitations
- POST /api/invitations/{invitation}/resend
- POST /api/invitations/{invitation}/revoke
- GET /api/access/{token}
- POST /api/access/{token}/review
- POST /api/access/{token}/comment
- POST /api/access/{token}/sign
- POST /api/access/{token}/complete

Authenticated invitation creation:
1. Only document owner or admin can create invitations.
2. Accept multiple recipients in one request.
3. Validate recipient emails.
4. Reject duplicate recipient emails in same request.
5. invitationType must be review or sign.
6. reviewPeriodDays must create expires_at.
7. For sign invitations, require at least one signature field on the document.
8. Create one invitation per recipient.
9. Generate a secure random access token.
10. Store only token hash in DB.
11. Return the raw token only once in the API response for email generation.
12. Send invitation email through queued mail job.
13. Do not duplicate the document unless absolutely required; use document_id relationship as backend truth.

Public access:
1. GET /api/access/{token} validates token hash.
2. Reject missing, invalid, expired, revoked, or completed token where appropriate.
3. Mark pending invitation as viewed on first successful access.
4. Return document metadata, fields, comments, invitation permissions, and safe preview URL/data needed by frontend.
5. Do not require JWT for public access.

Review flow:
1. Public reviewer must provide name before commenting/completing.
2. Allow adding comments.
3. Support threaded replies through parentCommentId.
4. Complete review and mark invitation completed.
5. Notify document owner.

Sign flow:
1. Public signer must provide name.
2. Accept signatureData as base64 PNG.
3. Apply signature to required signature fields.
4. Require agreement flag.
5. Require all required fields to be signed.
6. Mark invitation completed.
7. Store signature records linked to invitation and fields.
8. Notify document owner.

Audit:
- invitation_created
- invitation_sent
- invitation_viewed
- invitation_resent
- invitation_revoked
- public_comment_added
- review_completed
- signature_added
- signing_completed

Testing:
Create feature tests for:
- owner creates multi-recipient review invitations
- owner creates sign invitations with fields
- sign invitation fails without signature fields
- duplicate recipient rejected
- non-owner cannot invite
- public token access success
- invalid token returns 404/401-style JSON
- expired token rejected
- revoked token rejected
- first access marks viewedAt
- reviewer can comment
- threaded public comment works
- reviewer can complete review
- signer can sign required fields
- signer cannot complete without agreement
- signer cannot complete if required fields unsigned
- completion emails are queued
- invitation audit logs are written

Run php artisan test.


Prompt 6 — Implement Threaded Comments API

Implement threaded comments for authenticated and public invitation workflows.

Endpoints:
- GET /api/documents/{document}/comments
- POST /api/documents/{document}/comments
- PUT /api/comments/{comment}
- DELETE /api/comments/{comment}
- POST /api/access/{token}/comment must use the same comment service internally.

Requirements:
1. Comments must support:
   - documentId
   - userId nullable
   - invitationId nullable
   - username/authorName
   - selectedText
   - comment body
   - timestamp
   - parentCommentId
   - page
   - annotation metadata
2. Authenticated comments require JWT and document permission.
3. Public comments require valid invitation token and comment permission.
4. Parent comment must belong to the same document.
5. Only comment owner, invitation author, document owner, or admin can delete as appropriate.
6. Existing bug referencing undefined username during comment deletion must be fixed.
7. GET comments should return nested/threadable structure or flat list with parentCommentId, whichever frontend expects.
8. Include author metadata needed by UI.
9. Preserve current localStorage-compatible fields in API output:
   - id
   - documentId
   - userId
   - username
   - selectedText
   - comment
   - timestamp
   - parentCommentId

Audit:
- comment_added
- comment_updated
- comment_deleted
- public_comment_added

Testing:
Create tests for:
- authenticated user adds comment
- public invitee adds comment
- threaded reply works
- parent from another document rejected
- owner deletes own comment
- non-owner cannot delete comment
- admin can delete comment
- delete writes audit log with correct username
- list comments returns expected frontend shape

Run php artisan test.

Prompt 7 — Implement Signature Fields and Signature API

Implement signature field placement and signature persistence.

Endpoints:
- GET /api/documents/{document}/fields
- POST /api/documents/{document}/fields
- PUT /api/fields/{field}
- DELETE /api/fields/{field}
- POST /api/documents/{document}/signatures
- GET /api/documents/{document}/signatures

Signature fields:
1. Only document owner or admin can create/update/delete fields.
2. Fields must store percentage-based coordinates:
   - x
   - y
   - width
   - height
   - page
3. Support required flag.
4. Support assignment to recipient email or invitation.
5. Must preserve frontend SignatureField shape:
   - id
   - x
   - y
   - width
   - height
   - page
6. Validate coordinate ranges.
7. Reject invalid page numbers.

Signatures:
1. Authenticated signing requires JWT and permission.
2. Public signing uses invitation token workflow.
3. Store:
   - signatureData
   - signer name
   - signer user or recipient email
   - signedAt
   - ipAddress
   - linked invitation
   - linked document field where applicable
4. For legacy assignment flow:
   - POST /api/documents/{document}/signatures must mark assignment signatureCompleted.
   - Document status should become signed/completed when appropriate.
5. For invitation flow:
   - completing all required fields should complete invitation.
6. Return frontend-compatible signature shape:
   - id
   - documentId
   - userId
   - username
   - signatureData
   - signedAt
   - ipAddress

Audit:
- signature_field_created
- signature_field_updated
- signature_field_deleted
- signature_added
- signing_completed

Testing:
Create tests for:
- owner creates signature field
- non-owner cannot create field
- invalid coordinates rejected
- list fields returns frontend shape
- update/delete field authorization
- authenticated signer submits signature
- public signer submits signature through invitation
- required field enforcement
- assignment status becomes signed
- invitation status becomes completed
- audit logs are written

Run php artisan test.


Prompt 8 — Implement Legacy Assignment Workflow Compatibility

Implement backend support for the legacy `/documents`, `/review/:documentId`, and `/sign/:documentId` frontend flows.

Endpoints may include:
- GET /api/assignments
- POST /api/documents/{document}/assignments
- GET /api/assignments/{assignment}
- POST /api/assignments/{assignment}/acknowledge-review
- POST /api/assignments/{assignment}/invite-signature
- POST /api/assignments/{assignment}/complete-signature
- PUT /api/assignments/{assignment}/review-period
- POST /api/assignments/{assignment}/reassign

Requirements:
1. A user can view documents assigned to them.
2. Admin/document owner can assign a document to a user.
3. Assigned document response must match legacy frontend fields:
   - id
   - documentId
   - userId
   - username
   - assignedAt
   - expiresAt
   - daysAllowed
   - title
   - content
   - fileType
   - fileName
   - fileSize
   - reviewAcknowledged
   - acknowledgedAt
   - signatureInvited
   - signatureInvitedAt
   - signatureCompleted
   - signatureCompletedAt
   - status
   - assignedBy
4. A reviewer can acknowledge review completion.
5. Review acknowledgement must update status to reviewed.
6. Signature invitation flag must move document to ready-to-sign state.
7. Signature completion must mark status signed.
8. Expired review windows must be computed from expiresAt.
9. Comments must be available for assigned documents.
10. Secure PDF preview must work for assigned documents.
11. Do not break new library/invitation workflow.

Audit:
- document_assigned
- review_completed
- signature_invited
- signature_added
- document_reassigned
- review_period_updated

Testing:
Create tests for:
- user lists assigned documents
- user cannot see another user’s assignments
- admin assigns document
- review acknowledgement authorization
- review completion status update
- signature invite flag
- signature completion status
- expired review calculation
- reassignment resets review period
- comments remain linked
- frontend response shape is correct

Run php artisan test.

Prompt 9 — Implement Admin Dashboard, Users, Audit Logs, and Stats

Implement admin APIs required by both the simplified admin dashboard and richer admin dashboard.

Endpoints:
- GET /api/admin/dashboard
- GET /api/users
- POST /api/users
- GET /api/users/{user}
- PUT /api/users/{user}
- DELETE /api/users/{user}
- GET /api/audit-logs
- GET /api/audit-logs/{log}
- DELETE /api/audit-logs
- GET /api/audit-logs/export
- GET /api/settings/document-storage-mode
- PUT /api/settings/document-storage-mode

Admin user management:
1. Admin can list users.
2. Admin can create users with role user/admin.
3. Regular signup cannot create admin.
4. Admin can update users.
5. Admin can delete non-admin users.
6. Prevent deleting currently authenticated admin.
7. Decide whether deleting a user soft-deletes/cascades assignments; implement safely and document behavior.

Dashboard stats:
Return:
- totalUsers
- totalDocuments
- totalComments
- activeReviews
- expiredReviews
- completionRate
- averageCommentsPerDoc
- recentActivity
- totalRegularUsers
- totalAdmins
- pendingInvitations
- completedInvitations
- pendingSignatures
- completedSignatures

Audit logs:
1. Support pagination.
2. Support filters:
   - eventType/action
   - user
   - document
   - dateFrom/dateTo
   - search
3. Export audit logs as text or CSV.
4. Do not hard-cap export-grade access incorrectly.
5. Match frontend fields:
   - id
   - timestamp
   - action
   - performedBy
   - performedById
   - targetUser
   - targetUserId
   - documentTitle
   - documentId
   - details
   - ipAddress

Testing:
Create tests for:
- regular user cannot access admin endpoints
- admin lists users
- admin creates user
- admin deletes non-admin
- admin cannot delete self
- dashboard stats are accurate
- audit logs filter by action
- audit logs search works
- audit export works
- document storage setting can be read/updated by admin only

Run php artisan test.

Prompt 10 — Implement Real Email and Notification Flow

Replace frontend-only simulated email behavior with backend queued mail and notification events.

Requirements:
1. Implement queued mail for:
   - invitation email
   - invitation resend
   - review completed notification
   - signing completed notification
   - optional reminder email
   - admin/user creation notification where relevant
2. Email links must use tokenized public access:
   - frontend URL: /access?token=<raw-token>
3. Do not store raw token.
4. Mail templates must include:
   - document title
   - action type: review/sign
   - recipient name/email
   - expiration date
   - secure access link
   - sender/organization identity
5. Add notification_events table or equivalent if frontend needs to replace `sentEmails`.
6. Provide dev/testing endpoint only in local environment:
   - GET /api/dev/notification-events
   - DELETE /api/dev/notification-events
7. This endpoint replaces TestingHelper’s localStorage sentEmails during development.
8. Production must not expose dev notification endpoints.
9. Configure queue and document required worker command.
10. Ensure email sending failures are logged and retryable.

Testing:
- Use Mail::fake and Queue::fake.
- Test invitation email queued.
- Test completion email queued.
- Test resend queues email.
- Test notification event is stored in local/dev mode.
- Test dev endpoints are unavailable outside local/testing environment.
- Test email link contains raw token only in outbound mail, not DB.
- Run php artisan test.

Prompt 11 — Implement Secure File Preview and Storage Abstraction

Harden file handling and preview/download authorization.

Requirements:
1. Store uploaded documents using Laravel filesystem abstraction.
2. Support local disk for development and configurable cloud disk for production.
3. Do not expose raw public storage URLs for protected documents.
4. Preview/download must go through backend authorization or temporary signed URLs.
5. Public invitation preview must validate invitation token before serving file.
6. Authenticated preview must validate document ownership, assignment, invitation, or admin role.
7. Validate:
   - MIME type
   - extension
   - file size
   - PDF integrity where possible
8. Add audit logs for:
   - document_previewed
   - document_downloaded
   - public_document_accessed
9. Ensure base64 fallback is treated as compatibility/debug only, not source of truth.
10. Add rate-limiting for public access and download endpoints.
11. Add headers to reduce browser caching of protected files where appropriate.

Testing:
Create tests for:
- owner can preview
- assigned reviewer can preview
- public invite token can preview
- invalid token cannot preview
- revoked token cannot preview
- non-owner cannot preview
- unauthorized download blocked
- invalid upload rejected
- oversized upload rejected
- audit logs written
- no raw storage path is leaked in normal API response unless explicitly signed/temporary

Run php artisan test.

Prompt 12 — Replace Demo Data With Backend Seeders and API Truth

Remove reliance on frontend localStorage/demo data by providing backend seeders and API-compatible initial data.

Requirements:
1. Create development seeders for:
   - admin user: admin/admin123
   - regular user: user/user123
   - one sample document assigned to regular user
   - one saved library document for regular user
   - optional sample comments/signatures/audit logs
2. Seeders must only run when explicitly invoked.
3. Passwords must be hashed.
4. Seeded documents must use the same backend schema as real documents.
5. No production code should auto-create demo users on request.
6. Provide a backend endpoint or command only for local/testing reset if needed:
   - php artisan app:seed-demo-data
   - or local-only /api/dev/reset-demo-data
7. Ensure frontend can fetch all data from backend APIs instead of reading seeded localStorage.

Testing:
- Test seeders create expected records.
- Test seeded admin can login.
- Test seeded user can login.
- Test seeded assigned document appears in /api/assignments.
- Test seeded saved document appears in /api/documents?scope=library.
- Test demo reset endpoint/command is disabled outside local/testing.
- Run php artisan test.

Prompt 13 — Create Frontend Integration API Compatibility Layer

Create API response transformers/resources that match the current frontend data shapes while backend remains normalized.

Requirements:
1. Add Laravel API Resources for:
   - UserResource
   - DocumentResource
   - AssignmentResource
   - InvitationResource
   - CommentResource
   - SignatureResource
   - SignatureFieldResource
   - AuditLogResource
   - DashboardStatsResource
2. Each resource must return camelCase fields expected by frontend.
3. Include compatibility IDs:
   - id
   - documentId
   - invitationId where relevant
4. Convert timestamps to ISO 8601 UTC strings.
5. Include permission flags:
   - canEdit
   - canReview
   - canComment
   - canSign
   - canDelete
   - canDownload
6. Include pagination metadata for list endpoints.
7. Validation errors must return field-based JSON:
   {
     "message": "...",
     "errors": {
       "fieldName": ["..."]
     }
   }
8. Ensure frontend does not need to calculate lifecycle state from missing fields.
9. Preserve legacy fields for old components while adding new fields for the library/invitation workflow.

Testing:
- Snapshot or exact JSON structure tests for each resource.
- Test document list response shape.
- Test invitation response shape.
- Test public access response shape.
- Test assignment response shape.
- Test audit log response shape.
- Run php artisan test.

Prompt 14 — End-to-End Backend Test Coverage

Expand backend automated tests so every frontend-critical workflow is covered.

Required test suites:

Auth:
- register
- login
- refresh
- logout
- me
- role-based access
- failed login rate limit

Documents:
- create draft
- upload file
- list library
- view detail
- update metadata
- delete/archive
- preview/download authorization

Assignments:
- assign document
- list user assignments
- acknowledge review
- invite signature
- sign assigned document
- expiry behavior

Invitations:
- create multi-recipient invitations
- resend
- revoke
- public access
- expired token
- viewed transition
- review completion
- signing completion

Comments:
- authenticated comment
- public comment
- threaded reply
- delete authorization
- audit logging

Signatures:
- create fields
- update/delete fields
- sign required fields
- reject incomplete signing
- bind signatures to invitation/field

Admin:
- user CRUD
- dashboard stats
- audit logs filter/export
- storage setting

Notifications:
- invitation email queued
- completion email queued
- retry/failure behavior where practical

Security:
- non-owner access blocked
- public token cannot access unrelated document
- raw files not exposed
- invalid upload rejected
- CORS Authorization allowed

Implementation:
1. Use factories and seeders.
2. Use Mail::fake, Queue::fake, Storage::fake.
3. Tests must be deterministic.
4. Do not rely on frontend localStorage.
5. Ensure all tests pass with:
   php artisan test

Acceptance:
- Every endpoint used by the frontend has at least one success test and one authorization/validation failure test.
- No implementation is considered complete until tests pass.

Prompt 15 — Final Integration Verification and Demo Data Removal

Perform final backend integration verification against the frontend contract.

Tasks:
1. Confirm all localStorage-backed frontend entities now have backend API equivalents:
   - users
   - currentUser
   - documents
   - comments
   - signatures
   - auditLogs
   - documentInvitations
   - savedDocuments
   - sentEmails
2. Confirm all active frontend routes have backend support:
   - /login
   - /signup
   - /dashboard
   - /documents
   - /review/:documentId
   - /sign/:documentId
   - /access
   - /admin
3. Confirm no production flow depends on:
   - hardcoded demo users
   - browser localStorage as source of truth
   - simulated email queue
   - raw public file URLs
   - frontend-only access control
4. Confirm API response shapes are frontend-compatible.
5. Confirm JWT works after browser reload.
6. Confirm public invitation access works without JWT.
7. Confirm all document permissions are enforced server-side.
8. Confirm queue/mail configuration is documented.
9. Confirm migrations run cleanly on fresh database.
10. Confirm seeders work for local demo only.
11. Run:
   - composer install
   - php artisan migrate:fresh --seed
   - php artisan test
   - php artisan route:list
12. Produce a final report at:
   backend/FINAL_BACKEND_FRONTEND_INTEGRATION_REPORT.md

Report must include:
- implemented endpoints
- test results
- remaining risks, if any
- environment variables required
- queue worker command
- frontend integration notes
- removed/replaced demo data assumptions
- known non-production-only dev helpers

Recommended Implementation Order

Use the prompts in this exact sequence:

Audit before coding
JWT authentication
Data model/migrations
Document library
Invitations/public access
Threaded comments
Signature fields/signatures
Legacy assignment compatibility
Admin/dashboard/audit
Mail/notifications
Secure file handling
Seeders/demo replacement
API compatibility resources
Full test coverage
Final integration verification