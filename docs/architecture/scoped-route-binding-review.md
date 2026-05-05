# Scoped Route Binding Review

Date: 2026-05-04

## CRM/KYC Nested Routes

Reviewed nested routes:

- `clients/{client}/identity-documents/{identityDocument}`
- `clients/{client}/guarantors/{guarantor}`
- `clients/{client}/proxies/{proxy}`

Action taken:

- Added `Route::scopeBindings()` around CRM/KYC child routes in `routes/api/v1/auth.php`.
- Kept controller ownership checks as defense in depth.
- Added regression coverage in `PolicyAuthorizationHardeningTest::test_nested_crm_child_routes_fail_closed_for_wrong_parent_public_ids`.

Expected behavior:

- A valid child public ID from another client fails closed with `404`.
- Valid nested child URLs continue to resolve through the parent client relationship.

## Accounting Routes

Reviewed accounting routes:

- `ledger-accounts/{ledgerAccount}`
- `customer-accounts/{customerAccount}`
- `account-holds/{accountHold}`
- `journal-entries/{journalEntry}`
- `journal-lines/{journalLine}`

Decision:

- No nested accounting routes were introduced in this hardening pass.
- Existing accounting endpoints are flat, public-ID based, and already perform relationship integrity checks during mutations.
- Adding nested alternatives now would expand the API surface without a compatibility need.

Safety notes:

- Journal lines, account holds, and customer accounts remain public-ID based.
- Cross-scope relationship checks remain in controllers where the related records must be resolved from request payloads.
- Future nested accounting endpoints should use scoped bindings from the first release of those routes.
