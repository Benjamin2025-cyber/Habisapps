# Client Signature Backlog

## Decision

Client signatures must be treated as controlled account-operation evidence, not as a raw path on `customer_accounts`.

The approved implementation is document-backed account signature specimens:

- The uploaded signature, thumbprint, or scanned signature card remains a `documents` record.
- The account stores signature authority through `customer_account_signatures`.
- Signatures are account-scoped because banking staff verify withdrawals, mandates, and teller operations against the account being operated.
- Proxies and mandates may have their own signature specimens linked to the same account and proxy record.
- The API must expose signature metadata and document public IDs, never storage paths.

## Backlog

### 1. Signature Specimen Schema

Create `customer_account_signatures` with public IDs, agency scope, account, client, document, optional proxy, signature type, status, verification/revocation fields, capture date, and metadata.

Acceptance criteria:

- A signature specimen cannot cross agency boundaries for account, client, document, or proxy.
- A document can only back one signature specimen.
- Active primary-holder signature uniqueness is enforced per account.
- Supported statuses are `active`, `superseded`, `revoked`, and `archived`.
- Supported signature types include `primary_holder`, `joint_holder`, `proxy`, `mandate`, and `thumbprint`.

### 2. Signature Specimen API

Add account-nested endpoints to list, create, show, verify, and revoke signatures.

Acceptance criteria:

- Staff can retrieve all signature specimens for an account they are allowed to view.
- Creating a signature requires an active same-agency document in a signature category.
- Proxy/mandate signatures require an active, verified proxy tied to the same client/account scope.
- Verification records verifier and timestamp.
- Revocation records revoker, timestamp, and reason.
- API responses expose public IDs only.

### 3. Teller And Withdrawal Integration

Surface account signature status to operations that need client consent or signature verification.

Acceptance criteria:

- Teller withdrawal preparation can retrieve active account signatures through `GET /customer-accounts/{account}/signatures`.
- Withdrawal workflows record the checked signature specimen, checker, timestamp, and verification method on `teller_transactions`.
- Holder withdrawals require an active verified holder/thumbprint specimen.
- Proxy withdrawals require an active verified proxy/mandate specimen tied to the proxy mandate.
- Audit logs capture signature verification events without exposing file paths.

Status: implemented for cash withdrawals. Remaining future work is UI ergonomics for teller-side specimen preview and comparison.

### 4. Migration From Legacy Field

Deprecate `customer_accounts.signature_path`.

Acceptance criteria:

- New writes do not use `signature_path`.
- Existing values are audited and migrated into document-backed records only when a valid document can be attached.
- API resources do not expose `signature_path`.

### 5. Test Coverage

Add migration and API tests for the signature workflow.

Acceptance criteria:

- Tests cover create, list, verify, revoke, duplicate primary-holder rejection, cross-agency rejection, and proxy validation.
- Tests assert no raw storage paths are returned.
- PHPStan and focused Laravel tests pass for the touched surface.
