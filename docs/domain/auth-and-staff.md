# Authentication And Staff Model

This document turns stakeholder requirements for users, OTP, agencies, and staff management into implementation guidance.

## Current Foundation

The API currently supports:

- Sanctum bearer token authentication.
- Staff-only phone/password login.
- Public registration removed from the API surface.
- Admin-created staff invitations.
- First activation through OTP verification before password login is allowed.
- OTP delivery records for multiple channels, currently SMS plus email when an email is available.
- Roles and permissions through Spatie Permission.
- Token expiration.
- Rate limiting.

## Stakeholder Requirements

The stakeholder resources add:

- Phone number as a central staff identity field.
- OTP verification with 6-digit codes.
- Staff profile data such as matricule, gender, agency, portfolio, status, and supervisor.
- Agency hierarchy and branch manager assignment.

## Recommended Staff User Model

Keep one `users` table for staff operators.

Implemented foundation fields:

- `public_id`
- `name`
- `phone_number`
- `phone_verified_at`
- `matricule`
- `job_title`
- `agency_id`
- `agency_code`
- `agency_name`
- `status`
- `invited_by_user_id`
- `activated_at`
- `last_login_at`

Deferred fields belong to targeted administration/HR implementation, not the reusable base:

- `gender`
- `birth_date`
- `birth_place`
- `title_function`
- `portfolio_name`
- `assignment_date`
- `supervisor_id`

The base now includes agencies and primary staff agency assignments because document access, staff administration, and future operational records require a real agency boundary from the start.

Decision:

- `staff_agency_assignments` is the authority for active agency membership.
- `users.agency_id` is a synchronized primary-agency cache for simple query scoping and document access.
- `agency_code` and `agency_name` are display compatibility fields derived from the agency when possible; they are not the long-term authorization source.
- Temporary assignment, transfer history, and cross-agency work must be represented through assignment records rather than rewriting historical operational records.

## Login Identifier Decision

Decision:

- Phone + password is the base login identifier.
- Email is optional contact metadata and an additional OTP delivery channel, not a login identifier.
- Multiple login identifiers are avoided until product requirements justify the extra account-recovery and enumeration risks.
- Staff must be active and phone-verified before login succeeds.

## OTP Rules

OTP implementation must include:

- Hashed OTP storage.
- Purpose field.
- Expiration timestamp.
- Single-use `used_at`.
- Attempt counter.
- Maximum attempt counter.
- Resend throttling.
- Rate limiting by phone, IP, and purpose.
- Generic failure messages.
- Delivery records per attempted channel with masked destination and destination hash.
- Audit events for issuance, successful verification, exhausted attempts, and expiry.

Do not:

- Store plaintext OTP codes.
- Reuse OTPs across purposes.
- Allow unlimited resend or verify attempts.
- Reveal whether a phone number belongs to a staff user.

## Staff Creation

Public registration is not appropriate for this system.

Implemented base flow:

1. Admin creates or invites staff.
2. API creates a pending staff user with no usable password.
3. API sends one activation OTP challenge across available channels.
4. Staff verifies OTP and sets password.
5. Staff can login only after activation.

Deferred flow:

- Portfolio assignment, supervisor assignment, and HR profile details remain domain-specific and must be implemented with the administration module.
- Primary agency assignment is part of the foundation and is represented by `staff_agency_assignments`.
- Password reset and risky-login MFA should reuse the same OTP challenge design with a separate `purpose`.

Required controls:

- Staff must not be able to activate themselves without an administrative or invite workflow.
- Suspended staff must lose API access immediately through token revocation or authorization checks.
- Staff agency reassignment must be audited because it changes data access scope.

## Authorization

Authorization must combine:

- Role/permission checks.
- Agency scope checks.
- Resource ownership/assignment checks.

Base role model:

- `staff` is the generic authenticated employee account class and grants no business authority by itself.
- `platform-admin` is institution-wide system administration and bootstrap access.
- `agency-manager` is agency-scoped staff administration and supervision.
- `regional-manager` is regional oversight and must be paired with explicit region scope before cross-agency write access is introduced.
- `teller` is cash/till operation staff.
- `loan-officer` is client and credit portfolio staff.
- `accountant` is ledger/accounting workflow staff.
- `auditor` is read-only oversight.
- `user-admin` is retained only as a legacy compatibility alias for agency-scoped user administration and should not be expanded.

Example:

- A branch manager may view loans in their agency.
- A credit officer may act only on loans assigned to them unless they have elevated permission.
- Accounting approval requires accounting role and correct workflow step.

## Token And Session Policy

Required controls:

- Tokens expire.
- Logout revokes the current token.
- Password reset or staff suspension should revoke all active tokens.
- Future device/session management should record token name, issuing IP, user agent, last used time, and revocation reason.

## Base API Surface

Unauthenticated:

- `POST /api/v1/login`
- `POST /api/v1/activate`
- `POST /api/v1/activation/resend`
- `POST /api/v1/password/otp`
- `POST /api/v1/password/reset`

Authenticated:

- `POST /api/v1/logout`
- `GET /api/v1/staff-users`
- `POST /api/v1/staff-users`
- `GET /api/v1/staff-users/{staffUser}`
- `PATCH /api/v1/staff-users/{staffUser}`
- `PATCH /api/v1/staff-users/{staffUser}/status`
- `PUT /api/v1/staff-users/{staffUser}/roles`
- `GET /api/v1/documents`
- `POST /api/v1/documents`
- `GET /api/v1/documents/{document}`
- `PATCH /api/v1/documents/{document}/archive`
- `POST /api/v1/reference-numbers`
- `GET /api/v1/audit-events`
