# Frontend CRM PII And Customer Account Access Fix Strategy

Date: 2026-06-01

Source report: frontend team screenshot `/home/bayanga/Downloads/WhatsApp Image 2026-06-01 at 10.41.55.jpeg` and message:

> Un utilisateur autre que le platform admin, meme s'il a le droit de voir des clients, les informations arrivent avec des `******`. Le teller doit pouvoir voir le solde du compte des clients; presentement seul l'admin peut voir, le teller retourne 403. Sur la page retrait/depot, le frontend fetch le solde des comptes pour afficher.

This investigation is not limited to the teller role. The teller is the frontend symptom, but the same backend authorization defect affects every non-platform actor whose role or direct permissions grant customer-account read access.

## Current Evidence

### Screenshot evidence

The client payload includes unmasked public/scope fields but masks PII fields:

- `first_name`: `S******`
- `last_name`: `K*****`
- `father_name`: `C*****************`
- `phone_number`: `********1257`
- `email`: `c***@gmail.com`

This matches backend masking behavior, not missing database values.

### Backend evidence: client masking is deliberate

- `app/Http/Resources/ClientResource.php` sets `$showPii = $this->canViewPii($request)`.
- `ClientResource::canViewPii()` currently requires `crm.pii.view`.
- `crm.clients.view` alone is not enough to see unmasked names, phone, email, birth data, address, or family names.
- The resource emits `pii_redacted`, so the frontend can detect masking.
- `tests/Feature/Api/Module2CrmKycTest.php` currently asserts that `agency-manager` has `crm.clients.view` but not `crm.pii.view`, so the client is masked and `pii_redacted=true`.

### Backend evidence: teller role lacks client/account read permissions

`config/security.php` teller role currently has cash permissions but does not include:

- `crm.clients.view`
- `customer.accounts.view`
- `crm.pii.view`

So a default teller cannot reliably search clients/accounts unless custom permissions are assigned.

### Backend evidence: several non-admin roles are affected

The current role catalog has multiple non-platform roles with `customer.accounts.view`:

- `user-admin`
- `agency-manager`
- `kyc-officer`

These roles can pass `CustomerAccountPolicy::viewAny()` and list customer accounts, but they fail `CustomerAccountPolicy::view()` because `view()` currently returns `platform-admin` only.

The same failure also affects any user who receives `customer.accounts.view` as a direct permission or through a custom role.

Other roles have related CRM visibility but no customer-account read permission:

- `regional-manager` has `crm.clients.view` and institution CRM read scope, but no `customer.accounts.view`.
- `loan-officer` has `crm.clients.view`, but no `customer.accounts.view`.
- `accountant` has loan accounting permissions, but no CRM/customer-account read permission.
- `auditor` has institution CRM read scope and audit permissions, but no `customer.accounts.view`.

Those roles need explicit product-level grants if they are expected to search accounts or see balances. The fix must not assume teller is the only missing role.

### Backend evidence: account list and account show/balance are inconsistent for all non-admin account readers

- `app/Policies/CustomerAccountPolicy.php::viewAny()` allows `platform-admin` or `customer.accounts.view`.
- `CustomerAccountWorkflow::index()` scopes non-admin account lists to the actor's current agency.
- `CustomerAccountPolicy::view()` currently returns `platform-admin` only.
- `AccountingBalanceWorkflow::customerAccount()` and `customerAccountAvailable()` both call `$actor->cannot('view', $customerAccount)`.
- Therefore any non-admin can list same-agency customer accounts with `customer.accounts.view`, but receives `403` on `/customer-accounts/{id}`, `/balance`, `/available-balance`, and `/statement`.

This explains the frontend report and shows a broader defect: teller/deposit/withdrawal pages need balances, but every non-platform account reader is denied by the account `view` policy.

## Product Decision

The current model treats any user without `crm.pii.view` as unable to see even basic client identity. That is too strict for operational screens.

Adopt a two-tier client visibility model for all operational roles:

1. Operational identity/contact visibility for staff who need to identify and serve clients.
2. Full sensitive PII visibility for KYC/compliance/admin users.

Do not simply grant `crm.pii.view` to operational users. That would expose too much data, including birth/family/address fields, when many users only need enough information to identify the client and complete their workflow.

Adopt a customer-account access model that separates:

- Account record visibility: `customer.accounts.view`.
- Current/available balance lookup: `customer.accounts.balance.view`.
- Statement/movement history: a stricter statement permission, because transaction history is broader than the current balance needed by teller/front-office screens.

## Target Behavior

### Client payload behavior

For same-agency operational users with client access:

- Show unmasked operational fields required for front-office work:
  - `first_name`
  - `last_name`
  - `middle_name`
  - `phone_number`
  - `email`
  - `client_reference`
  - status/KYC status and non-sensitive operational metadata already visible today.
- Keep sensitive fields masked or null unless the user has full `crm.pii.view`:
  - `father_name`
  - `mother_name`
  - `date_of_birth`
  - `place_of_birth`
  - `gender`
  - home phone if not required operationally
  - addresses
  - occupation/employer/business address
  - identity-document details in their own resources.

For users with only `crm.clients.view` but without operational identity/contact permission, keep masking.

For `crm.pii.view`, preserve current full unmasked behavior.

### Customer account and balance behavior

Any same-agency operational user with the correct account permissions must be able to retrieve account data required for their workflow:

- `/api/v1/customer-accounts?client_public_id=...`
- `/api/v1/customer-accounts/{customerAccount}`
- `/api/v1/customer-accounts/{customerAccount}/balance?currency=XAF`
- `/api/v1/customer-accounts/{customerAccount}/available-balance?currency=XAF`

The permission model must remain narrow:

- No general ledger account balances unless separately permissioned.
- No cross-agency account data.
- No statement/movement history in this fix; that requires a separate statement-specific permission and implementation decision.
- No account mutation unless separately permissioned.

Default grants for this fix:

- `teller`: client search, operational identity/contact, account read, balance read.
- `agency-manager`: operational identity/contact, account read, balance read.
- `loan-officer`: operational identity/contact, account read, balance read, because loan workflows commonly need to identify customers and inspect account availability.
- `accountant`: operational identity/contact, account read, balance read, because repayments/disbursements require customer/account identification.
- `kyc-officer`: already has full client PII and account read; add balance read because the account-read role should not fail current/available balance endpoints.
- `user-admin`: keep legacy compatibility by adding operational identity/contact and balance read, because it already has client/account read.
- `regional-manager`, `compliance-officer`, and `auditor`: add operational identity/contact because they already have client read; do not add customer-account/balance access in this frontend fix because they do not currently have `customer.accounts.view`.

## Fix Backlog

### FB-PII-001: Introduce operational client identity permission

Severity: High

Implementation:

- Add a new permission, recommended name: `crm.clients.identity.view`.
- Grant it to roles that operationally need unmasked basic identity/contact:
  - `user-admin`
  - `agency-manager`
  - `regional-manager`
  - `loan-officer`
  - `accountant`
  - `teller`
  - `kyc-officer`
  - `compliance-officer`
  - `auditor`
- Keep `crm.pii.view` as the full sensitive PII permission.
- Update role catalog/protected-permission tests and seed/sync logic.

Acceptance criteria:

- `crm.clients.view` alone no longer implies full PII.
- `crm.clients.identity.view` shows operational identity/contact fields only.
- `crm.pii.view` still shows all currently unmasked client fields.
- Permission catalog and role sync expose the new permission.

Security criteria:

- The new permission does not expose birth/family/address/identity-document fields.
- Cross-agency scoping remains enforced by `ClientPolicy`.
- Operational identity reads remain normal CRM reads; full sensitive PII remains the only tier controlled by `crm.pii.view`.

### FB-PII-002: Update `ClientResource` to support two-tier masking

Severity: High

Implementation:

- Replace single `$showPii` branch with:
  - `$showOperationalIdentity = crm.clients.identity.view || crm.pii.view`
  - `$showFullPii = crm.pii.view`
- Return full names/phone/email for operational identity viewers.
- Keep sensitive fields controlled by full PII only.
- Replace or extend `pii_redacted` with clearer frontend flags:
  - `identity_redacted`
  - `sensitive_pii_redacted`
  - Keep `pii_redacted` during transition for backward compatibility.

Acceptance criteria:

- Agency manager or teller with `crm.clients.identity.view` sees unmasked first/last name and phone/email.
- Same user still receives null/masked birth/family/address fields unless they also have `crm.pii.view`.
- Existing frontend can distinguish genuinely missing values from redaction.
- Platform admin/full PII users continue seeing current full payload.

Security criteria:

- Tests prove operational identity permission does not expose full PII.
- Tests prove actor from another agency cannot view the client, masked or unmasked.

### FB-BAL-001: Fix `CustomerAccountPolicy::view()` same-agency read access

Severity: Critical

Implementation:

- Update `CustomerAccountPolicy::view()` to allow:
  - `platform-admin`; or
  - actor with `customer.accounts.view` and the same active agency as the customer account.
- Use `StaffAgencyScope` consistently, matching other agency-scoped policies.
- Keep `create`, `update`, and `delete` restricted as currently designed unless separate product scope says otherwise.
- Do not add institution-wide customer-account read scope in this frontend fix. That is a separate accounting/reporting access decision and should not be hidden inside the teller balance fix.

Acceptance criteria:

- Non-admin with `customer.accounts.view` can show same-agency account.
- Same user cannot show cross-agency account.
- Existing list behavior and response contract remain stable.
- Platform admin still has full access.

Security criteria:

- `viewAny` and `view` no longer conflict.
- Tests cover same-agency allowed and cross-agency forbidden.

### FB-BAL-002: Add narrow operational balance permission for account-reader screens

Severity: Critical

Implementation:

- Add a new permission, recommended name: `customer.accounts.balance.view`.
- Grant it to the operational roles that need current/available account balances:
  - `user-admin`
  - `agency-manager`
  - `teller`
  - `loan-officer`
  - `accountant`
  - `kyc-officer`
- Keep `regional-manager`, `auditor`, and `compliance-officer` unchanged for customer-account/balance access because they do not currently have `customer.accounts.view`.
- Update `AccountingBalanceWorkflow::customerAccount()` and `customerAccountAvailable()` authorization:
  - For account balance endpoints, require `customer.accounts.balance.view` plus account visibility/same-agency scope.
  - Keep customer statement endpoint stricter; this frontend issue only requires current and available balances, not movement history.
- Reuse the existing `/balance` and `/available-balance` endpoints; do not introduce a new endpoint for this fix.

Acceptance criteria:

- Teller can fetch same-agency customer account available balance for deposit/withdrawal page.
- User admin, agency manager, loan officer, accountant, and kyc officer can fetch same-agency customer account balances when their role includes `customer.accounts.balance.view`.
- Existing non-admin account readers no longer receive a `403` solely because `CustomerAccountPolicy::view()` is platform-admin-only.
- Operational account readers cannot fetch cross-agency balances.
- Operational account readers cannot fetch ledger account balances.
- Operational account readers cannot fetch statements through this fix.
- Balance response includes `currency`, `accounting_balance_minor`, `available_balance_minor`, `minimum_balance_minor`, `active_hold_amount_minor`, and `unavailable_amount_minor` as currently modeled.

Security criteria:

- Balance permission is separate from broad `ledger.accounts.view` and `accounting.audit.view`.
- Operational balance lookup remains separate from accounting report access through permission checks and endpoint-level authorization.

### FB-ROLE-001: Update default operational roles for customer lookup and balance workflows

Severity: Critical

Implementation:

Add these permissions to default `teller` role:

- `crm.clients.view`
- `crm.clients.identity.view`
- `customer.accounts.view`
- `customer.accounts.balance.view`

Add these permissions to default `loan-officer` role:

- `crm.clients.identity.view`
- `customer.accounts.view`
- `customer.accounts.balance.view`

Add these permissions to default `accountant` role:

- `crm.clients.view`
- `crm.clients.identity.view`
- `customer.accounts.view`
- `customer.accounts.balance.view`

Add these permissions to default `agency-manager` role:

- `crm.clients.identity.view`
- `customer.accounts.balance.view`

For `kyc-officer`:

- Add `crm.clients.identity.view` for semantic clarity, although `crm.pii.view` already implies unmasked client data.
- Add `customer.accounts.balance.view` because the role already has `customer.accounts.view`.

For `user-admin`:

- Add `crm.clients.identity.view` because the legacy role already has `crm.clients.view`.
- Add `customer.accounts.balance.view` because the legacy role already has `customer.accounts.view`.

For `regional-manager`, `compliance-officer`, and `auditor`:

- Add `crm.clients.identity.view`.
- Do not add `customer.accounts.view` or `customer.accounts.balance.view` in this fix.

Do not add by default:

- `crm.pii.view`
- `ledger.accounts.view`
- `accounting.audit.view`
- `journal.entries.view`
- account update/close permissions.

Acceptance criteria:

- Freshly seeded/synced operational roles can search client/account records and fetch balances required by their approved workflows.
- Teller, loan officer, accountant, and agency manager remain unable to access full sensitive PII unless they already have `crm.pii.view`.
- Operational roles remain unable to access cross-agency clients/accounts, ledger balances, and accounting reports unless separately permissioned.

Security criteria:

- Role sync tests prove each role receives only the narrow new permissions approved above.
- Regression tests prove operational roles still cannot access unrelated admin/accounting endpoints.

### FB-FE-001: Stabilize frontend contract for redaction flags

Severity: Medium

Implementation:

- Document `identity_redacted`, `sensitive_pii_redacted`, and transitional `pii_redacted` semantics.
- Frontend should not infer missing data from `null` on redacted fields.
- Frontend should show a clear restricted-data state if fields are redacted.

Acceptance criteria:

- Frontend can render client names and contact data for operational users.
- Frontend can still detect when sensitive fields are intentionally hidden.

## Tests Required

Use project-standard verification commands:

- Focused tests: `php artisan test --parallel --recreate-databases --filter ...`
- Full suite gate: `composer test`
- Static analysis: `vendor/bin/phpstan analyze`
- Formatting: `vendor/bin/pint --test`
- API contract after response fields/permissions change: `php artisan scramble:export --path=public/docs/api.json` or the project CI equivalent.

Required focused coverage:

- Client resource tests:
  - `crm.clients.view` only remains masked.
  - `crm.clients.identity.view` shows operational identity/contact only.
  - `crm.pii.view` shows full PII.
  - Cross-agency actor remains forbidden.
- Role matrix tests:
  - `user-admin`, `agency-manager`, `regional-manager`, `loan-officer`, `kyc-officer`, `compliance-officer`, and `auditor` have `crm.clients.identity.view`.
  - `teller` and `accountant` gain both `crm.clients.view` and `crm.clients.identity.view`.
  - `user-admin`, `agency-manager`, `teller`, `loan-officer`, `accountant`, and `kyc-officer` have `customer.accounts.balance.view`.
  - `regional-manager`, `compliance-officer`, and `auditor` do not gain `customer.accounts.view` or `customer.accounts.balance.view`.
  - `teller`, `loan-officer`, `accountant`, and `agency-manager` do not gain `crm.pii.view`, `ledger.accounts.view`, or `accounting.audit.view` unless already granted before this fix.
- Customer account tests:
  - Every role with `customer.accounts.view` can show same-agency customer accounts.
  - Every role with `customer.accounts.view` is denied cross-agency customer accounts.
  - Every role with `customer.accounts.balance.view` can fetch same-agency current and available balances.
  - Roles without `customer.accounts.balance.view` cannot fetch customer-account balances.
  - Operational roles cannot fetch ledger-account balances unless separately granted `ledger.accounts.view`.
  - Operational roles cannot fetch customer-account statements unless specifically granted.

## Implementation Order

1. Add permissions and role defaults.
2. Update `ClientResource` masking tiers.
3. Update `CustomerAccountPolicy::view()` scope logic.
4. Update balance authorization to use `customer.accounts.balance.view` for customer-account balance endpoints.
5. Add tests and update existing masking test expectations.
6. Export API docs if response fields or permission descriptions are exposed.

## Definition Of Done

- Operational users no longer see `******` for basic client identity/contact when their role needs it.
- Sensitive PII remains protected behind full `crm.pii.view`.
- Teller can fetch same-agency account balances required by withdrawal/deposit pages.
- Teller cannot access cross-agency accounts, ledger balances, full statements, or full PII by default.
- Tests and role sync prove the behavior.

## Adversarial Re-Review (2026-06-01)

This section supersedes stale "Current Evidence" statements that described pre-fix behavior. It documents what is now proven in code and what still needs explicit rollout control.

### Proven in code

- Two-tier client visibility is implemented in `ClientResource`:
  - `crm.clients.identity.view` unlocks operational identity/contact fields.
  - `crm.pii.view` unlocks full sensitive PII.
  - Redaction flags are emitted: `identity_redacted`, `sensitive_pii_redacted`, plus transitional `pii_redacted`.
- Customer account show policy is now same-agency for non-admin users with `customer.accounts.view`.
- Customer-account current/available balance endpoints require `customer.accounts.balance.view` plus account visibility.
- Statement endpoint remains stricter (`customer.accounts.statement.view`), so this fix does not silently expose movement history.
- Default role grants now include operational identity and/or balance permissions for the approved roles.

Verification evidence:

- `php artisan test tests/Feature/Api/Module1AdministrationTest.php --filter=test_operational_roles_receive_identity_and_balance_permissions` passes.
- `php artisan test tests/Feature/Api/Module2CrmKycTest.php --filter=test_client_visibility_is_two_tier_by_permission` passes.
- `php artisan test tests/Feature/Module3AccountingArchitectureTest.php --filter=test_operational_account_readers_can_view_accounts_and_balances_within_agency` passes.

### Residual risks and mandatory follow-ups

1. Frontend flag migration risk:
   If a frontend still keys only on `pii_redacted`, it may continue masking fields even when `identity_redacted=false`.
   Mandatory action: frontend clients must switch identity rendering to `identity_redacted`; keep `pii_redacted` only as transitional compatibility.

2. Role rollout risk for existing deployments:
   Seeded defaults are correct, but environments with custom roles or stale role-permission snapshots may not inherit new grants automatically.
   Mandatory action: run role/permission sync in each environment and verify affected operational roles have expected permissions.

3. Contract drift risk:
   Response payload now carries additional redaction flags and new permission semantics.
   Mandatory action: regenerate and publish API contract docs, then have frontend re-validate withdraw/deposit and client search flows.

### Hard decisions (closed)

- This fix is not teller-only; it intentionally covers all operational roles listed in this backlog.
- Operational identity visibility is separate from full PII and must remain separate.
- Balance visibility is separate from statement visibility and must remain separate.
