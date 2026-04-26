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
- `agency_id`

The base intentionally stores `agency_code` and `agency_name` as nullable metadata only. It does not implement agencies, branch hierarchies, portfolios, or multi-agency authorization yet.

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

- Staff assignment to an agency, portfolio, or supervisor remains domain-specific and must be implemented with the administration module.
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

Authenticated:

- `POST /api/v1/logout`
- `GET /api/v1/staff-users`
- `POST /api/v1/staff-users`
- `GET /api/v1/staff-users/{staffUser}`
- `PATCH /api/v1/staff-users/{staffUser}`
- `PATCH /api/v1/staff-users/{staffUser}/status`
- `PUT /api/v1/staff-users/{staffUser}/roles`
