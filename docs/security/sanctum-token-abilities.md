# Sanctum Token Ability Strategy

Date: 2026-05-04

## Current Minted Abilities

Configured in `config/security.php`:

- `access-api`

## Decision

Token abilities are currently **descriptive/deferred**, not authoritative.

Authorization is enforced through:

- `auth:sanctum`
- roles and permissions
- policies
- Form Request `authorize()` methods
- route-specific rate limiters

## Reasoning

The current API issues staff-user tokens for the same first-party API surface. Enforcing fine-grained abilities now would require a full token issuance redesign for staff console, machine, and future service-to-service tokens.

## Required Future Work Before Authoritative Abilities

- Define token classes: staff console, automation, service-to-service.
- Define ability names per high-risk route group.
- Change token issuance to mint least-privilege abilities.
- Add `abilities` or `ability` middleware to high-risk routes.
- Add denial tests for tokens missing required abilities.

Until then, do not treat a token ability as sufficient business authorization.
