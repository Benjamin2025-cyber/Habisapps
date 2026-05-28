# Module 3 - Accounting & Financial Architecture  
Implementation Reference (from actual code)

## 1) Scope and entry points

Module 3 logic is exposed through:

- `routes/api/v1/accounting.php`
- `routes/api/v1/regulatory_reporting.php`

Main controller/workflow surfaces:

- Account products: `AccountProductController`
- Ledger accounts: `LedgerAccountController`
- EMF catalog: `EmfRegulatoryAccountController`
- EMF mapping: `EmfLedgerAccountMappingController`
- Operation codes: `OperationCodeController`
- Operation account mappings: `OperationAccountMappingController`
- Journal entries lifecycle: `JournalEntryWorkflow` (via `JournalEntryController`)
- Journal lines: `JournalLineController`
- Balances/statements/movements: `AccountingBalanceWorkflow` (via `AccountingBalanceController`)
- Holds and hold release: `AccountHoldController` + `ReleaseAccountHold`
- Reporting runs: `ReportRunController`
- Regulatory source/report-definition/report review-submission: `RegulatoryReportingWorkflow`

---

## 2) Account Product workflow

### API

- `GET /api/v1/account-products`
- `POST /api/v1/account-products`
- `GET /api/v1/account-products/{accountProduct}`
- `PATCH /api/v1/account-products/{accountProduct}`
- `DELETE /api/v1/account-products/{accountProduct}` (archive)

### Implemented behavior

- Product code uniqueness is enforced per agency scope (`code + agency_id`).
- Product currency is normalized to uppercase (e.g., `xaf` -> `XAF`).
- Product can reference a ledger account only if that ledger account is:
  - existing,
  - active,
  - in the same agency scope as the product.
- Deletion is soft/business archive (`status=archived`), not hard delete.
- Access is policy-controlled with agency scoping for non platform-admin users.

### Confirmed by tests

- `tests/Feature/Api/Module3AccountingProductTest.php`
  - creation, duplicate rejection, update, archive
  - active account-product enforcement when opening customer accounts

---

## 3) Ledger Account workflow

### API

- `GET /api/v1/ledger-accounts`
- `POST /api/v1/ledger-accounts`
- `GET /api/v1/ledger-accounts/{ledgerAccount}`
- `PATCH /api/v1/ledger-accounts/{ledgerAccount}`
- `DELETE /api/v1/ledger-accounts/{ledgerAccount}` (archive)

### Implemented behavior

- In this implementation slice, a ledger account must be attached to an agency.
- Parent-child hierarchy is supported with protections:
  - parent must exist,
  - parent must be in same agency scope,
  - no self-parent,
  - no cycle in ancestry.
- `normal_balance_side` is carried per account and used in trial-balance computations.
- Archive is status-based (`active|inactive|archived`) and policy-gated.

### Confirmed by tests

- `tests/Feature/Module3AccountingArchitectureTest.php`
  - agency-scope requirement
  - parent existence and persistence
  - creation/show behavior

---

## 4) EMF/COBAC catalog and mapping workflow

### 4.1 EMF regulatory account catalog

API:

- `GET /api/v1/emf-regulatory-accounts`
- `POST /api/v1/emf-regulatory-accounts`
- `PATCH /api/v1/emf-regulatory-accounts/{emfRegulatoryAccount}`
- `DELETE /api/v1/emf-regulatory-accounts/{emfRegulatoryAccount}` (archive)

Implemented behavior:

- Catalog entries are managed as records with `code`, `name`, optional class, parent link, status.
- Parent hierarchy supports cycle prevention.
- Archive is blocked when:
  - child EMF accounts exist, or
  - EMF->ledger mappings exist.

### 4.2 EMF account loader from regulatory source

API:

- `POST /api/v1/regulatory-sources/{sourcePublicId}/emf-accounts`

Implemented behavior:

- Bulk account payload import with parent linkage (`parent_code`).
- Duplicate codes are rejected.
- This is how official catalog data is loaded in-system from source documents.

### 4.3 Ledger-to-EMF mapping

API:

- `GET /api/v1/emf-ledger-account-mappings`
- `POST /api/v1/emf-ledger-account-mappings`
- `PATCH /api/v1/emf-ledger-account-mappings/{emfLedgerAccountMapping}`
- `DELETE /api/v1/emf-ledger-account-mappings/{emfLedgerAccountMapping}` (archive)

Implemented behavior:

- Mapping requires both sides active:
  - EMF regulatory account must be active.
  - Ledger account must be active.
- Duplicate mapping pair is rejected.
- Mapping stores reference integrity for regulatory reporting.

### Confirmed by tests

- `tests/Feature/Api/Module3AccountingProductTest.php`
- `tests/Feature/Api/RegulatoryReportingTest.php`

---

## 5) Operation code and posting mapping workflow

### API

- Operation code:
  - `GET/POST/PATCH/DELETE /api/v1/operation-codes`
- Operation account mapping:
  - `GET/POST/PATCH/DELETE /api/v1/operation-account-mappings`

### Implemented behavior

- Operation code must be active to accept new mappings.
- Mapping supports debit ledger, credit ledger, currency and rules metadata.
- If both debit and credit are set, they must belong to same agency.
- Operation code archive is blocked while non-archived mappings still exist.
- This is configuration-only in Module 3; no automatic posting from these mappings is triggered in this slice.

### Confirmed by tests

- `tests/Feature/Api/Module3AccountingProductTest.php`
  - cross-agency mapping rejection
  - archive blocking while mappings exist
  - no journal posting side effects

---

## 6) Journal lifecycle (maker-checker + posting + reversal)

### API

- Journal entries:
  - `GET/POST/PATCH/DELETE /api/v1/journal-entries`
  - `POST /api/v1/journal-entries/{journalEntry}/submit`
  - `POST /api/v1/journal-entries/{journalEntry}/approve`
  - `POST /api/v1/journal-entries/{journalEntry}/reject`
  - `POST /api/v1/journal-entries/{journalEntry}/post`
  - `POST /api/v1/journal-entries/{journalEntry}/reverse`
- Journal lines:
  - `GET/POST/PATCH/DELETE /api/v1/journal-lines`

### Status model

- Journal status progression:
  - `draft -> submitted -> approved -> posted`
- Alternative branches:
  - `submitted -> rejected`
  - `draft|submitted|rejected -> cancelled`
  - `posted -> reversal draft/approved/posted path (via reversal workflow)`

### Hard rules implemented

- Journal lines can only be added/edited/deleted on `draft` journals.
- Draft journal must have at least 2 lines before submission.
- Draft journal must be balanced before submission (`sum(debit) == sum(credit)`).
- Each line must have exactly one positive side:
  - either debit > 0 and credit = 0
  - or credit > 0 and debit = 0
- Ledger account on a line must be active and agency-compatible with journal.
- Approver/rejector must differ from maker/submitting user (maker-checker segregation).
- Posting locks the journal row in transaction and re-validates invariants.
- Reversal is only allowed from `posted` source entry and is handled by dedicated reversal service.

### Throttling

- Write-sensitive journal endpoints are protected by `throttle:journal.write`.

---

## 7) Balance, available balance, movements, statements

### API

- Ledger balance: `GET /api/v1/ledger-accounts/{ledgerAccount}/balance`
- Ledger movements: `GET /api/v1/ledger-accounts/{ledgerAccount}/movements`
- Customer balance: `GET /api/v1/customer-accounts/{customerAccount}/balance`
- Customer available balance: `GET /api/v1/customer-accounts/{customerAccount}/available-balance`
- Customer statement: `GET /api/v1/customer-accounts/{customerAccount}/statement`

### Implemented computation model

- Balance logic reads journal lines joined with posted journal entries.
- Available balance is balance minus active holds / hold effects.
- Statement/movement endpoints provide transactional history surfaces by account scope.
- Hold lifecycle updates are reflected through hold state changes and release workflow.

### Holds

- Holds can be created only on non-closed/non-archived customer accounts.
- Hold release uses dedicated application service (`ReleaseAccountHold`) and records actor/reason/reference.
- Hold records are archived via status update.

---

## 8) Regulatory reporting workflow

### 8.1 Regulatory source and report definition

API:

- `POST /api/v1/regulatory-sources`
- `POST /api/v1/report-definitions`

Implemented behavior:

- Regulatory source stores authority/reference/title/effective date/checksum.
- Report definition versions increment per `code`.
- EMF trial balance definitions require regulatory source linkage.
- Unsafe raw-table report definition payloads are rejected by validation policy.

### 8.2 Report run generation

API:

- `GET /api/v1/report-runs`
- `POST /api/v1/report-runs`
- `GET /api/v1/report-runs/{reportRun}`

Implemented behavior:

- Supported report types include:
  - `trial_balance`
  - `general_ledger`
  - `emf_trial_balance`
  - portfolio reporting views
- EMF trial-balance run is blocked if source snapshot is absent.
- EMF trial-balance run also checks unmapped posted ledger accounts; if missing mappings exist, run fails with details.
- Report summary is persisted on the run (`summary` JSON), with source-version snapshot for traceability.
- Trial-balance formula:
  - debit-normal accounts: `balance = debit - credit`
  - credit-normal accounts: `balance = credit - debit`

### 8.3 Review and submission (maker-checker)

API:

- `POST /api/v1/report-runs/{runPublicId}/review`
- `POST /api/v1/report-runs/{runPublicId}/submit`

Implemented behavior:

- Maker cannot self-review.
- Review is one-time (run locks once reviewed).
- Submission is blocked until approval exists.
- Submission records channel/reference/timestamp and submitter identity.
- Re-submission after success is rejected.

### 8.4 Mapping inspection gate

API:

- `GET /api/v1/regulatory-mapping-inspection/{operationCode}`

Implemented behavior:

- Returns readiness description for posting/mapping completeness by operation code, agency, currency.
- Explains blocking reason (missing op code, missing mapping, inactive or out-of-scope ledgers, etc.).

### Confirmed by tests

- `tests/Feature/Api/RegulatoryReportingTest.php`

---

## 9) Security, scoping, and audit trail

- Endpoints are under `auth:sanctum`.
- Access is policy and permission controlled (`viewAny/view/create/update/delete`, plus accounting/reporting permissions).
- Non platform-admin users are agency-scoped in listing and mutation paths.
- Security audit events are emitted for create/update/archive/submit/approve/reject/post/release/generate actions.

---

## 10) Evidence map (implementation files)

- Routing:
  - `routes/api/v1/accounting.php`
  - `routes/api/v1/regulatory_reporting.php`
- Core controllers/workflows:
  - `app/Http/Controllers/Api/V1/AccountProductController.php`
  - `app/Http/Controllers/Api/V1/LedgerAccountController.php`
  - `app/Http/Controllers/Api/V1/EmfRegulatoryAccountController.php`
  - `app/Http/Controllers/Api/V1/EmfLedgerAccountMappingController.php`
  - `app/Http/Controllers/Api/V1/OperationCodeController.php`
  - `app/Http/Controllers/Api/V1/OperationAccountMappingController.php`
  - `app/Application/JournalEntries/JournalEntryWorkflow.php`
  - `app/Http/Controllers/Api/V1/JournalLineController.php`
  - `app/Application/Accounting/AccountingBalanceWorkflow.php`
  - `app/Support/Accounting/AccountingBalanceCalculator.php`
  - `app/Http/Controllers/Api/V1/ReportRunController.php`
  - `app/Application/Reporting/RegulatoryReportingWorkflow.php`
  - `app/Application/Reporting/MappingCompletenessGate.php`
  - `app/Application/Accounting/ReleaseAccountHold.php`
- High-signal tests:
  - `tests/Feature/Module3AccountingArchitectureTest.php`
  - `tests/Feature/Api/Module3AccountingProductTest.php`
  - `tests/Feature/Api/RegulatoryReportingTest.php`
  - `tests/Unit/Application/Accounting/ReleaseAccountHoldTest.php`

---

## 11) Practical workflow overview (end-to-end)

1. Set up agency-scoped ledger chart (`ledger-accounts`) and account products (`account-products`).
2. Load EMF/COBAC catalog via regulatory source loader (`regulatory-sources/{id}/emf-accounts`).
3. Map local ledger accounts to EMF accounts (`emf-ledger-account-mappings`).
4. Configure operation codes and account mappings (`operation-codes`, `operation-account-mappings`).
5. Create draft journals + lines, then submit -> approve -> post.
6. Use balance/statement/movement endpoints for operational and client-level views.
7. Run trial balance and EMF reports; enforce mapping completeness gates.
8. Review and submit regulatory report runs with maker-checker separation.

