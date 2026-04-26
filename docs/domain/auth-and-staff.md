# Authentication And Staff Model

This document turns stakeholder requirements for users, OTP, agencies, and staff management into implementation guidance.

## Current Foundation

The API currently supports:

- Sanctum bearer token authentication.
- Email/password login.
- Registration disabled by default.
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

Fields to add after confirming login policy:

- `public_id`
- `full_name` or retain `name` and add structured names only if required
- `phone_number`
- `phone_verified_at`
- `matricule`
- `gender`
- `birth_date`
- `birth_place`
- `job_title`
- `title_function`
- `agency_id`
- `portfolio_name`
- `status`
- `assignment_date`
- `supervisor_id`

## Login Identifier Decision

Before implementation, stakeholders must confirm one option:

- Email + password remains the login identifier.
- Phone + password becomes the login identifier.
- Either email or phone can be used.

Recommendation:

- Use phone number for OTP verification and operational contact.
- Keep email optional only if not needed by the institution.
- Avoid supporting multiple login identifiers until product requirements require it.

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
- Audit events for issuance, successful verification, exhausted attempts, and expiry.

Do not:

- Store plaintext OTP codes.
- Reuse OTPs across purposes.
- Allow unlimited resend or verify attempts.
- Reveal whether a phone number belongs to a staff user.

## Staff Creation

Public registration is not appropriate for this system.

Recommended flow:

1. Admin creates or invites staff.
2. Staff verifies phone via OTP.
3. Staff sets password or receives password reset invitation.
4. Staff is assigned agency, role, and supervisor.

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

