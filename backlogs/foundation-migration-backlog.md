# Foundation Migration Backlog

This backlog covers implementation work that is safe before stakeholder formula decisions are finalized. It intentionally avoids implementing interest, penalty, repayment allocation, available-balance, till-difference, or reporting formulas.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.

## Guiding Rules

- [x] Build structural facts, lifecycle records, references, audit trails, and immutable accounting foundations.
- [x] Do not make calculated balances, interest, penalties, taxes, insurance, repayment allocation, PAR, or collection metrics authoritative.
- [x] Any formula-dependent field must be documented as a generated snapshot/projection and must be protected by the formula engine/policy gate.
- [x] Public API resources must expose `public_id` or business references, not internal integer `id`.
- [x] Every state-changing financial or audit-sensitive API must be idempotent, authorized, and audited.
- [x] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually as needed.

## Epic 0: Existing Migration Cleanup

- [x] DEV-0001: Remove or neutralize unused email password reset migration.

As a developer, I want the migration set to match the phone/OTP password reset model so future engineers are not confused by unused email token infrastructure.

Acceptance criteria:

- [x] The default `password_reset_tokens` table is either removed before the baseline is finalized or documented as intentionally unused.
- [x] No runtime password reset flow depends on email token rows.
- [x] Existing phone/OTP password reset tests continue to pass.
- [x] Migration rollback remains valid.

- [x] DEV-0002: Add missing down migration for activity log table.

As a developer, I want every custom migration to roll back cleanly so local/test environments remain reliable.

Acceptance criteria:

- [x] `activity_log` migration has a `down()` method.
- [x] `php artisan migrate:fresh --env=testing` succeeds.
- [x] Full test suite passes after fresh migration.

- [x] DEV-0003: Harden document and reference foundation migration.

As a developer, I want the existing document/reference tables to have clear constraints before dependent modules use them.

Acceptance criteria:

- [x] `documents` has a unique constraint or equivalent uniqueness guard for stored `disk` + `path`.
- [x] `reference_sequences.key` is reviewed for clarity; if renamed, application code and tests are updated.
- [x] Current document upload/archive and reference reservation tests pass.

- [x] SEC-0001: Review migration cleanup for auth confusion and rollback safety.

As a security reviewer, I want auth-related schema to be unambiguous so stale tables do not invite unsafe fallback flows.

Acceptance criteria:

- [x] No unused password reset table is presented as an active auth mechanism.
- [x] Rollback paths do not drop unrelated data in the wrong order.
- [x] Security report notes no misleading auth migration artifacts remain.

## Epic 1: Administration And Agency Foundation

- [x] DEV-0101: Create agencies migration.

As a developer, I want agencies represented as first-class records so future staff, client, account, loan, and till records can be scoped consistently.

Acceptance criteria:

- [x] `agencies` includes internal `id`, external `public_id`, unique `code`, `name`, region/city/branch metadata, contact/address fields, `creation_date`, `status`, nullable `manager_id`, and timestamps.
- [x] `manager_id` references `users` and uses null-on-delete.
- [x] `code` is unique and indexed.
- [x] Deletion is restricted or documented as forbidden once dependent records exist.
- [x] No formula or financial balance field is added.

- [x] DEV-0102: Create staff agency assignments migration.

As a developer, I want staff-to-agency relationships to preserve assignment history instead of overwriting one field on users.

Acceptance criteria:

- [x] `staff_agency_assignments` links `user_id` and `agency_id`.
- [x] Assignment rows include `role_at_agency`, `starts_on`, nullable `ends_on`, `is_primary`, status, and timestamps.
- [x] A database constraint or application rule prevents overlapping active primary assignments for the same staff member.
- [x] Future agency-scope authorization can query active assignments efficiently.

- [x] DEV-0103: Create batch procedure and batch run migrations.

As a developer, I want daily and periodic processing records to exist before formula execution logic is implemented.

Acceptance criteria:

- [x] `batch_procedures` records code, name, description, schedule metadata, status, and timestamps.
- [x] `batch_runs` records procedure, business date, agency scope when applicable, status, started/finished timestamps, operator, idempotency key, summary payload, and failure reason.
- [x] Unique constraints prevent duplicate successful runs for the same procedure/business date/scope unless explicitly designed otherwise.
- [x] No financial calculation result is made authoritative by these migrations.

- [x] SEC-0101: Review agency-scope foundation.

As a security reviewer, I want agency scoping data to prevent cross-agency privilege escalation when APIs are added.

Acceptance criteria:

- [x] Agency identifiers are not guessable internal IDs in public APIs.
- [x] Assignment history cannot be silently overwritten.
- [x] Schema supports least-privilege checks for staff acting within one or more agencies.
- [x] Indexes support authorization checks without encouraging broad unscoped queries.

## Epic 2: CRM And KYC Foundation

- [x] DEV-0201: Create clients migration.

As a developer, I want customer identity records to be captured without embedding formula-dependent financial state.

Acceptance criteria:

- [x] `clients` includes internal `id`, external `public_id`, agency scope, client number/reference, name fields, date/place of birth, gender, contact details, address, occupation/employer, status, onboarding dates, KYC status, and timestamps.
- [x] Client reference is unique within the intended scope.
- [x] Sensitive fields are explicitly identified for later masking/encryption review.
- [x] No authoritative balance, arrears, score, or risk calculation field is added.

- [x] DEV-0202: Create client identity documents migration.

As a developer, I want identity evidence to be linked to clients and uploaded documents consistently.

Acceptance criteria:

- [x] `client_identity_documents` links client, document type, document number, issuing authority, issue/expiry dates, verification status, uploaded document record, and timestamps.
- [x] Document number uniqueness is scoped intentionally and documented.
- [x] Expiry and verification status can be audited later.
- [x] Deletion behavior preserves auditability.

- [x] DEV-0203: Create client guarantors migration.

As a developer, I want guarantor relationships modeled independently so loans can later reference eligible guarantors.

Acceptance criteria:

- [x] `client_guarantors` links a client to guarantor identity/contact information or another client record.
- [x] Relationship type, status, start/end dates, and verification status are captured.
- [x] Supporting document linkage is available.
- [x] No loan liability formula is calculated in this migration.

- [x] DEV-0204: Create client proxies or mandate holders migration.

As a developer, I want authorized representatives to be represented before account and transaction workflows are added.

Acceptance criteria:

- [x] Proxy records link client, representative identity/contact fields, mandate type, start/end dates, status, and supporting document.
- [x] Schema can support revoked and expired mandates.
- [x] Public APIs can expose public identifiers only.
- [x] No transaction authorization logic is implemented inside the migration.

- [x] SEC-0201: Review PII and document-linkage schema.

As a security reviewer, I want customer identity data storage to be deliberate and auditable.

Acceptance criteria:

- [x] PII fields are documented for masking/encryption/access-control follow-up.
- [x] Document links do not expose storage paths through public identifiers.
- [x] Schema supports verification audit trails without destructive edits.
- [x] Unique constraints do not accidentally leak identity existence through public APIs without rate limiting and authorization.

## Epic 3: Accounting Foundation

- [x] DEV-0301: Create ledger accounts migration.

As a developer, I want a chart-of-accounts foundation before transactions are implemented.

Acceptance criteria:

- [x] `ledger_accounts` includes internal `id`, external `public_id`, code, name, account type/class, parent account, normal balance side, status, agency applicability, and timestamps.
- [x] Account code uniqueness is enforced in the intended scope.
- [x] Parent-child relationships prevent invalid self-references.
- [x] No running balance field is made authoritative unless it is clearly documented as a projection.

- [x] DEV-0302: Create customer accounts migration.

As a developer, I want customer deposit/account containers to exist independently from balance formulas.

Acceptance criteria:

- [x] `customer_accounts` links client, agency, product/category reference placeholder, ledger account when appropriate, account number, opening date, status, and timestamps.
- [x] Account number is unique.
- [x] Closure metadata is supported without deleting history.
- [x] Balance fields are excluded or explicitly snapshot/projection-only.

- [x] DEV-0303: Create account holds migration.

As a developer, I want blocked/reserved amounts to be recorded as lifecycle facts without defining available-balance formulas yet.

Acceptance criteria:

- [x] `account_holds` links customer account, amount, reason/type, status, placed/released timestamps, placed/released by users, and reference metadata.
- [x] Amount uses integer minor units with currency metadata where required by project conventions.
- [x] Holds are auditable and cannot be silently overwritten.
- [x] Available balance is not calculated in this migration.

- [x] DEV-0304: Create journal entries and journal lines migrations.

As a developer, I want immutable double-entry accounting storage before financial operations are built.

Acceptance criteria:

- [x] `journal_entries` includes public reference, business date, posting date/time, agency scope, source module/type/id, status, description, created/posted/reversed by users, reversal link, idempotency key, and timestamps.
- [x] `journal_lines` includes journal entry, ledger account, debit/credit minor amounts, currency, customer account/loan references when applicable, line memo, and timestamps.
- [x] Constraints prevent both debit and credit being positive on the same line.
- [x] Constraints require at least one positive side per line.
- [x] Application-level validation story is noted for balanced entries before posting.

- [x] DEV-0305: Create sectors and sub-sectors migrations.

As a developer, I want economic activity classifications available for clients and loans without implementing reports yet.

Acceptance criteria:

- [x] `sectors` and `sub_sectors` include code, name, status, and timestamps.
- [x] Codes are unique.
- [x] Sub-sectors reference sectors.
- [x] No PAR, portfolio, or risk report calculation is implemented.

- [x] SEC-0301: Review accounting integrity foundation.

As a security reviewer, I want accounting tables to resist tampering and ambiguous posting behavior.

Acceptance criteria:

- [x] Journal records are designed for append/reversal rather than destructive mutation.
- [x] Idempotency keys prevent duplicate posting by retried requests.
- [x] Public references cannot reveal internal sequential IDs when exposed.
- [x] Schema supports authorization by agency and operator.

## Epic 4: Cash Operations Foundation

- [x] DEV-0401: Create denominations migration.

As a developer, I want banknote and coin denominations represented explicitly for teller and cashbox reconciliation.

Acceptance criteria:

- [x] `denominations` includes code, label, minor-unit value, currency, type, status, and timestamps.
- [x] Denomination value is positive.
- [x] Code/value uniqueness is enforced per currency.
- [x] No till-difference calculation is implemented.

- [x] DEV-0402: Create tills migration.

As a developer, I want cash storage points represented before teller operations are implemented.

Acceptance criteria:

- [x] `tills` links agency, code, name, type, status, assigned user when applicable, and timestamps.
- [x] Till code is unique within agency.
- [x] Assignment does not erase historical teller sessions.
- [x] No cash balance field is authoritative unless explicitly documented as a projection.

- [x] DEV-0403: Create teller sessions migration.

As a developer, I want teller operating sessions tracked before transaction flows exist.

Acceptance criteria:

- [x] `teller_sessions` links till, teller user, agency, business date, opened/closed timestamps, opening declaration, closing declaration, status, and timestamps.
- [x] A user/till cannot have overlapping active sessions unless intentionally allowed and documented.
- [x] Session lifecycle supports audit review.
- [x] Difference formulas are deferred to formula policy decisions.

- [x] DEV-0404: Create teller transactions migration.

As a developer, I want cash operation facts recorded without embedding posting formulas.

Acceptance criteria:

- [x] `teller_transactions` links teller session, transaction type, client/account/loan references when applicable, amount, currency, status, reference, idempotency key, and timestamps.
- [x] Transaction references are unique.
- [x] Posting to journal entries is represented by nullable linkage or later workflow, not calculated in migration.
- [x] Reversal/correction relationships are supported.

- [x] DEV-0405: Create till reconciliation and denomination line migrations.

As a developer, I want physical cash counts to be captured by denomination.

Acceptance criteria:

- [x] `till_reconciliations` links teller session, counted by user, counted at, status, notes, and timestamps.
- [x] `till_reconciliation_lines` links reconciliation, denomination, count, and optional declared amount snapshot.
- [x] Count cannot be negative.
- [x] Any computed difference is excluded or explicitly marked as generated snapshot/projection.

- [x] SEC-0401: Review cash-management schema.

As a security reviewer, I want cash tables to support non-repudiation and fraud investigation.

Acceptance criteria:

- [x] Cash sessions and transactions are traceable to staff, agency, till, and business date.
- [x] Denomination counts are immutable or versioned after approval.
- [x] Idempotency prevents duplicate cash operations.
- [x] Schema supports maker/checker review for sensitive cash changes.

## Epic 5: Credit Structure Without Formula Execution

- [x] DEV-0501: Create loan products migration.

As a developer, I want loan products to hold policy references without hardcoding formulas.

Acceptance criteria:

- [x] `loan_products` includes code, name, status, allowed term metadata, allowed repayment frequency metadata, required guarantee/collateral flags, and formula policy keys.
- [x] Formula policy keys reference configurable policy identifiers, not embedded calculations.
- [x] Product code is unique.
- [x] No interest, penalty, insurance, or schedule calculation is implemented in migration.

- [x] DEV-0502: Create loans migration.

As a developer, I want loan applications/accounts represented before loan calculation rules are finalized.

Acceptance criteria:

- [x] `loans` links client, agency, product, loan number, requested amount, approved principal when available, currency, application date, approval/disbursement/closure dates, status, purpose, sector/sub-sector, and timestamps.
- [x] Loan number is unique.
- [x] Formula-policy snapshot fields are available where needed for later reproducibility.
- [x] Outstanding balance, arrears, penalty, and PAR values are not authoritative fields.

- [x] DEV-0503: Create loan approval transition migration.

As a developer, I want loan decision history to be auditable.

Acceptance criteria:

- [x] `loan_status_transitions` records loan, from status, to status, actor, reason, notes, timestamp, and supporting document when applicable.
- [x] Approval history is append-only.
- [x] Schema supports maker/checker review.
- [x] No disbursement or repayment formulas are implemented.

- [x] DEV-0504: Create collateral and collateral item migrations.

As a developer, I want collateral facts represented before valuation and coverage formulas are finalized.

Acceptance criteria:

- [x] `collaterals` link client/loan where applicable, type, description, status, ownership metadata, valuation date, declared value minor units, currency, and timestamps.
- [x] Supporting documents can be linked.
- [x] Collateral status history can be audited or extended.
- [x] Coverage ratio calculations are deferred.

- [x] DEV-0505: Create loan schedule snapshot migration.

As a developer, I want future loan schedules to be stored as generated snapshots without deciding the generator yet.

Acceptance criteria:

- [x] `loan_schedule_snapshots` records loan, formula engine key/version, policy snapshot hash, generated by, generated at, status, and timestamps.
- [x] `loan_schedule_lines` records snapshot, installment number, due date, principal/interest/fees/insurance/tax minor-unit components, and status.
- [x] Schedule records are clearly marked as generated outputs.
- [x] No schedule generator is implemented until formula decisions are approved.

- [x] SEC-0501: Review credit schema before feature implementation.

As a security reviewer, I want credit tables to support auditability and avoid false financial authority before formulas are approved.

Acceptance criteria:

- [x] Loan records do not expose internal IDs through public APIs.
- [x] Approval transitions preserve actor and reason history.
- [x] Formula outputs are snapshots with engine/policy traceability.
- [x] Sensitive credit decisions can be audited for authorization and maker/checker compliance.

## Cross-Cutting Completion Criteria

- [x] All new Laravel artifacts are scaffolded with Laravel/Artisan commands where Laravel provides them.
- [x] All new migrations have valid `up()` and `down()` methods.
- [x] Every table with public API exposure has an internal primary key and a public/business identifier.
- [x] Every stateful table has timestamps unless intentionally excluded and documented.
- [x] Every money amount uses integer minor units and explicit currency where the amount can cross product/account boundaries.
- [x] Foreign keys define intentional delete behavior.
- [x] Indexes support expected authorization, lookup, and audit queries.
- [x] Formula-dependent values are omitted, deferred, or marked as generated snapshots/projections.
- [x] `php artisan migrate:fresh --env=testing` succeeds.
- [x] `php artisan test` passes.
- [x] Security reviewer stories are completed before targeted feature implementation begins.
