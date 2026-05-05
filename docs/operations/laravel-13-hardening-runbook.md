# Laravel 13 API Hardening Runbook

Date: 2026-05-04

## Authorization

- Resource authorization should live in policies or Form Request `authorize()` methods.
- Controllers may keep domain integrity checks that require resolved related records.
- Nested CRM/KYC child routes use scoped route bindings and retain controller ownership checks as defense in depth.

## Controller Authorization

Controller permission checks that were previously inline are now expressed through policies. Controller-local branches still handle scope selection and record-specific integrity decisions when that logic belongs beside the query or mutation.

Simple single-record persistence may remain in controllers, but any workflow that touches multiple models or invalidates shared state should live in a service/action class.

## Rate Limiters

Named route limiters:

- `document.upload`
- `client.create`
- `journal.write`
- `audit.browse`
- `reference.reserve`

Limiter keys use authenticated user public IDs when available and IP address otherwise.

## Logging And Exceptions

Production Docker default:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=stderr
LOG_LEVEL=info
```

Global exception context is intentionally limited to safe operational keys. Do not add request bodies, tokens, OTPs, passwords, raw phone numbers, document numbers, or internal integer IDs.

## Sanctum Token Abilities

Token abilities are currently descriptive/deferred. Authorization remains role/permission/policy based until token classes and ability naming are designed.

## Service Extraction

Extracted services:

- `App\Application\Crm\UpdateClientKycStatus`
- `App\Application\Accounting\ReleaseAccountHold`

Future orchestration should move into application services before being reused by jobs, commands, or integrations.

## Verification

Run before merging hardening changes:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyze
php artisan test
php artisan scramble:export
```
