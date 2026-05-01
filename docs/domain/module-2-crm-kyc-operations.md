# Module 2 CRM/KYC Operations

This runbook documents Module 2 behavior implemented by CRM/KYC endpoints in `api/v1`.

Module 2 is intentionally non-financial: it captures customer identity and KYC lifecycle records without creating accounts, posting ledgers, approving loans, or moving cash.

## Endpoint Surface

Client profiles:

- `GET /api/v1/clients`
- `POST /api/v1/clients`
- `GET /api/v1/clients/{client}`
- `PATCH /api/v1/clients/{client}`
- `PATCH /api/v1/clients/{client}/kyc-status`
- `GET /api/v1/clients/{client}/kyc-reviews`

Collection metadata (`collection_type`, `collection_frequency`, optional `collection_target_amount`, and `collection_agent_public_id`) is captured through `POST /api/v1/clients` and `PATCH /api/v1/clients/{client}` only.

Client identity documents:

- `GET /api/v1/clients/{client}/identity-documents`
- `POST /api/v1/clients/{client}/identity-documents`
- `GET /api/v1/clients/{client}/identity-documents/{identityDocument}`
- `PATCH /api/v1/clients/{client}/identity-documents/{identityDocument}`
- `PATCH /api/v1/clients/{client}/identity-documents/{identityDocument}/status`

Client guarantors:

- `GET /api/v1/clients/{client}/guarantors`
- `POST /api/v1/clients/{client}/guarantors`
- `GET /api/v1/clients/{client}/guarantors/{guarantor}`
- `PATCH /api/v1/clients/{client}/guarantors/{guarantor}`
- `PATCH /api/v1/clients/{client}/guarantors/{guarantor}/status`

Client proxies:

- `GET /api/v1/clients/{client}/proxies`
- `POST /api/v1/clients/{client}/proxies`
- `GET /api/v1/clients/{client}/proxies/{proxy}`
- `PATCH /api/v1/clients/{client}/proxies/{proxy}`
- `PATCH /api/v1/clients/{client}/proxies/{proxy}/status`

## KYC State Model

Client KYC statuses:

- `draft`
- `pending_review`
- `verified`
- `rejected`
- `suspended`
- `archived`

Allowed transitions:

- `draft` -> `pending_review`, `archived`
- `pending_review` -> `verified`, `rejected`, `suspended`, `archived`
- `rejected` -> `pending_review`, `archived`
- `verified` -> `suspended`, `archived`
- `suspended` -> `pending_review`, `archived`
- `archived` -> no transition

Operational rules:

- Direct client profile updates cannot set privileged KYC states.
- Verification requires at least one active, verified identity document (unless explicit override is used).
- Rejection requires a reason.
- `archived` KYC status also archives the client record state for downstream modules.
- Every status change records immutable KYC review history.

## Document Ownership And Private File Handling

- CRM/KYC records reference uploaded `documents` by `document_public_id`; internal IDs and storage paths are never exposed in API responses.
- Linked evidence must belong to the same agency and be active.
- KYC files are private media (`kyc_documents` collection on local private disk).
- No API endpoint in Module 2 returns a file download URL or temporary signed link.
- Archived evidence cannot satisfy verification preconditions.

Reference security policy: `docs/domain/kyc-media-security.md`.

## Restricted PII And Audit Expectations

Restricted fields (document numbers, direct contact identity fields) are permission-gated:

- Full PII views require explicit CRM/KYC authorization (for example `crm.pii.view`).
- Unauthorized users receive masked or reduced PII fields in resources.
- All CRM/KYC mutations are audit logged.
- Restricted PII reads are also audit logged when exposed to authorized actors.
- Audit payloads must avoid raw file paths and unnecessary high-risk values.

Reference classification: `docs/security/pii-data-classification.md`.

## API Contract And Error Semantics

OpenAPI is generated with Dedoc Scramble (`/docs/api`, `/docs/api.json`, `php artisan scramble:export`).

Module 2 endpoint documentation includes:

- Request schema from Form Requests.
- Response schema from API Resources/envelopes.
- Error responses for unauthorized, forbidden, validation failure, conflict-style duplicate checks, not found, and invalid transition/state actions.

## Explicit Module 2 Boundaries

Not part of Module 2:

- Customer account opening/closure and account balances.
- Ledger/journal posting and financial projections.
- Loan applications, schedules, disbursement, repayment allocation, penalties.
- Teller cash operations, till sessions, and reconciliation.
- Any formula-driven behavior blocked by stakeholder formula sign-off.

See `docs/domain/stakeholder-formula-questions.md` and `docs/domain/formula-guardrails.md`.

## Open Business Questions (Not Blocking Completed Module 2 Code)

- Final KYC vocabulary lock with stakeholders for production policy.
- Whether identity document numbers must be encrypted at rest before production.
- Whether maker-checker segregation is mandatory for every verification action.
- Final uniqueness scope for identity documents (global vs constrained).
- Final semantics for optional collection target amount metadata.
