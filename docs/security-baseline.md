# Security Baseline

## Authentication defaults

- Sanctum bearer tokens are the default API authentication mechanism.
- Sanctum tokens must expire; the default bootstrap TTL is 60 minutes.
- Login and registration are rate-limited.
- Public registration is disabled by default and must be explicitly enabled for controlled environments.
- Personal access tokens currently receive the `access-api` ability by default.
- When feature-specific tokens appear, abilities must become explicit and least-privilege.

## Authorization defaults

- Route protection should use `auth:sanctum` first, then explicit authorization checks.
- Permissions are seeded through `RolesAndPermissionsSeeder`.
- The current bootstrap role is `platform-admin`, intended for initial operator access only.

## Audit defaults

- Audit logging is a requirement for state-changing finance modules.
- Until finance models exist, audit conventions are documented but not globally forced onto every model.
- Mutable business models introduced later should either use a shared audit trait or an equivalent explicit implementation.

## Secret and environment handling

- Secrets belong in environment configuration, never in source control.
- Error responses in production must stay generic.
- Config values should be consumed through Laravel config, not direct `env()` calls in application code.
- Bootstrap admin seeding is disabled by default and must not create known credentials in shared environments.

## Idempotency defaults

- POST/PATCH retries use `Idempotency-Key`.
- Idempotency records are persisted in `api_idempotency_keys`.
- Reusing a key with a different request fingerprint must return a conflict instead of executing the request.

## Deferred security work

- MFA, device/session management, key rotation workflows, and structured security event logging are not part of the current bootstrap.
- They become required before handling real customer or financial operations.
