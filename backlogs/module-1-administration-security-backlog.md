# Module 1 Backlog: Administration & System Security

This backlog covers stakeholder Module 1 from `stakeholderResources/definedModules.md`: staff identity, authentication, access security, agency structure, and operational automation. It is intentionally limited to administration and security foundations. It must not implement customer KYC, accounting postings, teller operations, loan workflows, or formula-dependent batch execution.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.

Completion note (2026-05-16): this original administration/security backlog is superseded for final Module 1 scope by
`backlogs/module-1-administration-completion-backlog.md`.

## Guiding Rules

- [x] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually as needed.
- [x] Composer must be used for package installation; package config and migrations must be published through Laravel/vendor publish commands where provided.
- [x] Public APIs must expose public IDs or business references, not internal integer IDs.
- [x] Every state-changing administration API must be authenticated, authorized, idempotent where retryable, and audit logged.
- [x] Agency-scoped users must never manage resources outside their current active agency unless explicitly granted platform-level authority.
- [x] Security-sensitive flows must use generic error responses where detailed errors would leak account or staff existence.
- [x] Batch work in this module is registry and run tracking only until stakeholder formula policies are approved.

## Epic 1: Staff Identity, Authentication, And OTP

- [x] DEV-0101: Implement staff-only authentication foundation.

As a developer, I want staff authentication to be phone/password based with activation controls so the API has a reusable secure identity foundation before business modules are added.

Acceptance criteria:

- [x] Staff users authenticate through the API using phone number and password.
- [x] Pending staff cannot log in before activation.
- [x] Active verified staff receive Sanctum API tokens.
- [x] Login rejects unknown fields and oversized credentials.
- [x] Login rate limiting is enforced without allowing a victim phone number to be locked by attacker-controlled attempts.
- [x] Logout revokes the current access token.

- [x] DEV-0102: Implement activation and password reset OTP flows.

As a developer, I want OTP challenges to activate accounts and reset passwords without storing plaintext OTPs.

Acceptance criteria:

- [x] Activation OTPs are hashed at rest.
- [x] OTPs are single-use and expire.
- [x] OTP verification enforces max attempts atomically.
- [x] OTP resend is rate limited.
- [x] Password reset OTP requests use generic responses.
- [x] Password reset verifies OTP, updates password, and revokes existing tokens.
- [x] OTP deliveries support multiple channels so the same challenge can be delivered through configured channels.

- [x] SEC-0101: Review authentication and OTP abuse resistance.

As a security reviewer, I want staff authentication to resist enumeration, brute force, replay, token leakage, and stale account activation.

Acceptance criteria:

- [x] Login and activation endpoints are rate limited.
- [x] OTP codes are never stored in plaintext.
- [x] OTP delivery records store masked or hashed destinations, not full sensitive destinations.
- [x] Token responses are not persisted by idempotency replay for login.
- [x] Suspended staff cannot continue using old tokens.
- [x] Authentication error responses do not leak unnecessary account state.

## Epic 2: Staff User Administration

- [x] DEV-0201: Implement staff user creation.

As a platform or agency administrator, I want to create staff accounts with secure activation so users can be onboarded without sharing passwords manually.

Acceptance criteria:

- [x] Authorized users can create staff users.
- [x] Created staff starts in pending verification state.
- [x] Activation OTP is generated through the OTP service.
- [x] Multi-channel delivery records are created according to configured channels.
- [x] Agency managers can only create staff inside their current agency.
- [x] Staff without `users.create` permission cannot create staff.
- [x] Staff creation is audit logged.

- [x] DEV-0202: Implement staff listing, view, and profile update.

As an administrator, I want to list and update staff records according to my authority scope.

Acceptance criteria:

- [x] Platform administrators can list platform-visible staff.
- [x] Agency managers can list only staff in their current active agency.
- [x] Staff listing uses active staff assignment, not stale cached agency fields.
- [x] Agency managers cannot view or update staff in another agency.
- [x] Staff profile updates preserve agency-scope rules.
- [x] Unauthenticated staff routes return clean JSON without debug details.

- [x] DEV-0203: Implement staff role and status management.

As an administrator, I want to update staff roles and statuses without allowing privilege escalation or accidental platform lockout.

Acceptance criteria:

- [x] Platform administrators can update staff roles.
- [x] Non-platform administrators cannot grant `platform-admin`.
- [x] Non-platform administrators cannot suspend platform administrators.
- [x] The only active platform administrator cannot be demoted or suspended.
- [x] Suspended staff have existing tokens revoked.
- [x] Role and status changes are audit logged.

- [x] DEV-0204: Implement explicit staff assignment management API.

As an administrator, I want to assign, transfer, and end staff agency assignments as first-class records instead of relying only on profile updates.

Acceptance criteria:

- [x] Routes are scaffolded through Laravel/Artisan-supported commands where applicable.
- [x] API supports listing a staff member's assignment history.
- [x] API supports assigning staff to an agency with role-at-agency, start date, primary flag, and status.
- [x] API supports ending an assignment with end date and reason.
- [x] API supports transferring primary assignment from one agency to another without deleting history.
- [x] Database constraints and application validation prevent overlapping active primary assignments.
- [x] Agency managers can manage assignments only within their current agency and cannot grant platform authority.
- [x] Assignment changes are audit logged with actor, target staff, agency, and reason.
- [x] Tests cover cross-agency denial, primary assignment replacement, assignment history preservation, and token/session implications.

- [x] SEC-0201: Review staff administration privilege boundaries.

As a security reviewer, I want staff management APIs to prevent horizontal access, role escalation, and lockout of privileged operators.

Acceptance criteria:

- [x] Cross-agency staff list/show/update/assignment attempts are denied.
- [x] Platform role grants require active platform administrator authority.
- [x] Staff cannot assign themselves new roles or agency authority unless an explicit reviewed permission allows it.
- [x] Suspended/deactivated staff cannot authenticate or continue using existing tokens.
- [x] Audit logs do not expose plaintext OTPs, passwords, full destinations, or sensitive internal IDs.
- [x] Tests prove assignment authority is based on active assignment records.

## Epic 3: Agency Structure Administration

- [x] DEV-0301: Implement agency schema foundation.

As a developer, I want agencies represented as first-class records so staff, clients, accounts, loans, tills, and batch runs can be scoped consistently.

Acceptance criteria:

- [x] `agencies` includes `id`, `public_id`, unique `code`, `name`, region/city/branch metadata, contact/address fields, creation date, status, nullable manager, and timestamps.
- [x] Agency code is unique.
- [x] Agency deletion is restricted once dependent records exist.
- [x] Staff and financial foundation tables can reference agencies safely.

- [x] DEV-0302: Implement agency CRUD and lifecycle API.

As a platform administrator, I want to manage agencies through API endpoints before agency-scoped business modules depend on them.

Acceptance criteria:

- [x] Controllers, requests, resources, and tests are scaffolded through Laravel/Artisan commands where applicable.
- [x] API supports create, list, show, update, activate, suspend, and archive/deactivate actions.
- [x] API exposes `public_id`, `code`, `name`, metadata, manager public reference, and status without internal IDs.
- [x] Agency code is immutable after creation unless a separate reviewed correction workflow is implemented.
- [x] Agency status transitions are controlled and validated.
- [x] Agencies with dependent staff, clients, accounts, loans, tills, documents, or batch runs cannot be destructively deleted through API.
- [x] Agency changes are audit logged.
- [x] Tests cover authorization, uniqueness, status transitions, no internal ID exposure, and dependent-record deletion protection.

- [x] DEV-0303: Implement agency manager assignment rules.

As a platform administrator, I want to assign managers to agencies with clear authority and audit trail.

Acceptance criteria:

- [x] Only eligible active staff can be assigned as agency manager.
- [x] Manager must have an active assignment to the agency or the assignment is created through the same controlled workflow.
- [x] Replacing a manager preserves historical staff assignment records.
- [x] Manager assignment cannot create cross-agency authority accidentally.
- [x] Manager assignment and replacement are audit logged.
- [x] Tests cover invalid manager, cross-agency manager, replacement, and audit behavior.

- [x] SEC-0301: Review agency administration security.

As a security reviewer, I want agency APIs to enforce platform-only structural changes and prevent privilege expansion through agency metadata.

Acceptance criteria:

- [x] Only platform-authorized users can create, archive, or structurally change agencies.
- [x] Agency managers cannot create fake agencies or move themselves to another agency.
- [x] Agency manager assignment cannot grant platform roles.
- [x] Agency public IDs are used in API paths and responses.
- [x] Agency state changes are auditable and cannot erase prior dependent records.

## Epic 4: Role, Permission, And Access Policy Administration

- [x] DEV-0401: Establish role and permission seed baseline.

As a developer, I want real microfinance actor roles represented so permissions can be assigned deliberately.

Acceptance criteria:

- [x] Roles include platform, agency management, teller, loan, accounting, audit, and baseline staff actors.
- [x] `staff` is treated as a minimal compatibility role, not a broad operational actor.
- [x] Sensitive permissions such as users, roles, documents, audit, and references are assigned intentionally.
- [x] Role seeding is tested through current feature flows.

- [x] DEV-0402: Implement read-only role and permission catalog API.

As an administrator, I want to see available roles and permissions so user-management UIs do not hardcode access policy.

Acceptance criteria:

- [x] API lists roles with display labels, descriptions, and assignability metadata.
- [x] API lists permissions grouped by module.
- [x] API marks protected roles such as `platform-admin`.
- [x] API does not expose internal role/permission IDs.
- [x] Access to the catalog requires `roles.manage` or a reviewed read permission.
- [x] Tests cover authorization and response contract.

- [x] DEV-0403: Implement controlled role-permission management API.

As a platform administrator, I want to adjust role permissions through controlled workflows while preserving least privilege and auditability.

Acceptance criteria:

- [x] Only platform administrators or users with explicit `roles.manage` can mutate role permissions.
- [x] Protected permissions cannot be assigned to unsafe roles without explicit guardrails.
- [x] `platform-admin` cannot be stripped of the minimum permissions required to administer the system.
- [x] Role-permission changes invalidate Spatie permission cache.
- [x] Role-permission changes are audit logged with before/after snapshots.
- [x] Tests cover cache invalidation, protected permission denial, minimum platform admin permission preservation, and audit records.

- [x] SEC-0401: Review access policy management.

As a security reviewer, I want role and permission management to avoid accidental privilege escalation or permanent administrative lockout.

Acceptance criteria:

- [x] Protected roles and permissions are documented.
- [x] Non-platform roles cannot grant themselves platform-level authority.
- [x] Permission changes are traceable to an actor and timestamp.
- [x] The system prevents removing all active administrators' ability to recover access.
- [x] Tests prove a lower-privilege admin cannot create an equivalent of `platform-admin` through permission mutation.

## Epic 5: Batch Procedure Registry And Run Tracking

- [x] DEV-0501: Implement batch schema foundation.

As a developer, I want batch procedures and runs represented structurally before any end-of-day financial jobs are implemented.

Acceptance criteria:

- [x] `batch_procedures` stores code, name, description, timing, active status, and timestamps.
- [x] `batch_runs` stores procedure, business date, agency scope, status, started/finished timestamps, operator, idempotency key, summary, and failure reason.
- [x] Duplicate successful runs are prevented for the same procedure/date/scope.
- [x] Formula-dependent job execution is not implemented in schema foundation.

- [x] DEV-0502: Implement batch procedure registry API.

As an operations administrator, I want to register and manage batch procedure metadata so operational jobs can be governed before execution exists.

Acceptance criteria:

- [x] API supports create, list, show, update, activate, and deactivate batch procedures.
- [x] Procedure code is unique and immutable after creation unless a reviewed correction flow is implemented.
- [x] Procedure timing metadata is validated.
- [x] API does not execute formula-dependent jobs.
- [x] Procedure changes are audit logged.
- [x] Tests cover authorization, uniqueness, lifecycle, ordering metadata, and audit behavior.

- [x] DEV-0503: Implement batch run tracking API.

As an operations administrator, I want to record and inspect batch run attempts without implementing formula-dependent calculations.

Acceptance criteria:

- [x] API supports listing and showing batch runs.
- [x] API supports starting, marking success, and marking failure for non-formula placeholder procedures only.
- [x] API enforces idempotency for run creation/start attempts.
- [x] Duplicate successful run constraints are surfaced as clean validation errors.
- [x] Batch runs can be global or agency-scoped through the request scope provided at creation time.
- [x] Batch run changes are audit logged.
- [x] Tests cover duplicate successful run prevention, retryable pending/failed runs, agency scope, and idempotency.

- [x] SEC-0501: Review batch governance controls.

As a security reviewer, I want batch registry and run tracking to avoid fake operational completion, duplicate execution, or hidden failed jobs.

Acceptance criteria:

- [x] Only authorized operators can create or mutate procedure/run records.
- [x] Batch run status transitions are controlled.
- [x] Completion cannot be overwritten silently.
- [x] Formula-dependent procedures remain blocked until formula policies are approved.
- [x] Audit logs include actor, procedure, business date, scope, status change, and failure summary.

## Epic 6: Audit, Monitoring, And Production Readiness

- [x] DEV-0601: Implement security audit event browsing foundation.

As an auditor, I want to browse security audit events so sensitive administration actions can be reviewed.

Acceptance criteria:

- [x] Authorized auditors can browse audit events.
- [x] Staff without audit permission cannot browse audit events.
- [x] Audit responses are JSON API responses.
- [x] Audit browsing is protected by authentication and permission checks.

- [x] DEV-0602: Implement production readiness check foundation.

As a developer, I want deployment-critical security settings checked before production use.

Acceptance criteria:

- [x] Production readiness checker reports failures for unsafe local defaults.
- [x] Production readiness checker passes deployment-safe core settings.
- [x] Artisan command exists to run readiness checks.

- [x] DEV-0603: Expand audit coverage for all Module 1 mutations.

As a developer, I want every Module 1 mutation to emit consistent audit records so administrators and reviewers can reconstruct who changed what.

Acceptance criteria:

- [x] Agency create/update/status/manager changes emit audit records.
- [x] Staff assignment create/end/transfer changes emit audit records.
- [x] Role-permission changes emit audit records with before/after snapshots.
- [x] Batch procedure and run changes emit audit records.
- [x] Audit events include actor, subject, agency scope where relevant, request metadata, and safe summary payload.
- [x] Tests cover audit records for each mutation family.

- [x] DEV-0604: Update Scramble/OpenAPI documentation for Module 1 APIs.

As an API consumer, I want Module 1 endpoints documented accurately so clients do not infer behavior from implementation details.

Acceptance criteria:

- [x] Staff, agency, assignment, roles/permissions, batch, and audit endpoints are documented.
- [x] Request schemas document required fields, validation rules, and status values.
- [x] Response schemas exclude internal IDs and sensitive values.
- [x] Error documentation covers unauthorized, forbidden, validation, conflict/idempotency, and not found cases.
- [x] Scramble/OpenAPI generation succeeds.

- [x] SEC-0601: Execute Module 1 adversarial security review.

As a security reviewer, I want the completed Module 1 APIs reviewed before CRM, accounting, cash, or credit workflows depend on them.

Acceptance criteria:

- [x] Cross-agency access attempts are covered for every agency-scoped endpoint.
- [x] Role escalation attempts are covered for role, assignment, and staff management endpoints.
- [x] Account enumeration behavior is reviewed for auth and staff lookup flows.
- [x] Idempotency behavior is reviewed for retryable mutation endpoints.
- [x] Audit logs are reviewed for completeness and sensitive data leakage.
- [x] `vendor/bin/phpstan analyze` passes.
- [x] `php artisan test` passes.
- [x] Any findings are fixed or explicitly tracked with risk owner and target date.

## Not In Module 1

- [x] Client KYC profile implementation belongs to Module 2.
- [x] Customer accounts, chart of accounts APIs, postings, and balances belong to Module 3.
- [x] Loan product, approval, schedules, penalties, arrears, and portfolio transfer workflows belong to Module 4.
- [x] Tills, teller sessions, deposits, withdrawals, denominations, and cash reconciliation workflows belong to Module 5.
- [x] End-of-day jobs that compute balances, penalties, interest, reconciliation differences, reports, or portfolio metrics are implemented only in their owning modules after formula policy approval.
