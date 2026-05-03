# Module 3 Backlog: Accounting & Financial Architecture

This backlog covers the safe implementation slice of stakeholder Module 3 from `stakeholderResources/definedModules.md`: chart of accounts, customer account containers, sector/sub-sector references, account-hold lifecycle facts, and immutable journal storage scaffolding.

Module 3 is the next safe module after Module 2 only if it remains structural. It must not implement balance formulas, available-balance formulas, cash posting, deposit/withdrawal workflows, interest, fees, penalties, repayment allocation, reporting metrics, teller reconciliation, or any other formula-dependent behavior until `docs/domain/stakeholder-formula-questions.md` is answered and approved.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.

## Implementation Status

Not started:

- [ ] DEV-0101, DEV-0102, SEC-0101
- [ ] DEV-0201, DEV-0202, SEC-0201
- [ ] DEV-0301, DEV-0302, SEC-0301
- [ ] DEV-0401, DEV-0402, SEC-0401
- [ ] DEV-0501, DEV-0502, SEC-0501
- [ ] DEV-0601, DEV-0602

Verification to complete during implementation:

- [ ] `php artisan test --filter=Module3AccountingArchitectureTest`
- [ ] `php artisan test --filter="Module1AdministrationTest|FoundationOperationsTest|StaffUserManagementTest|Module2CrmKycTest|Module3AccountingArchitectureTest"`
- [ ] `vendor/bin/phpstan analyze`
- [ ] `php artisan scramble:export`

Still pending stakeholder confirmation:

- [ ] All balance, movement, availability, posting, rounding, cash, reporting, fee, interest, penalty, and repayment-allocation formulas remain unresolved and intentionally unimplemented.
- [ ] Final chart-of-accounts taxonomy, account-product catalog, and manual journal approval policy require stakeholder/accounting sign-off before production use.

## Guiding Rules

- [ ] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually as needed.
- [ ] Each implementation story must record the exact Artisan commands used, for example `php artisan make:model`, `php artisan make:controller`, `php artisan make:request`, `php artisan make:policy`, `php artisan make:resource`, and `php artisan make:test`.
- [ ] Do not hand-create Laravel models, controllers, requests, resources, policies, tests, seeders, or migrations when an Artisan generator exists.
- [ ] Composer must be used for package installation; package config and migrations must be published through Laravel/vendor publish commands where provided.
- [ ] Public APIs must expose `public_id`, account numbers, and business references, not internal integer IDs.
- [ ] Every mutation must be authenticated, authorized, agency-scoped where applicable, idempotency-aware where retryable, and audit logged.
- [ ] Agency users must never view or mutate records outside their active agency unless explicitly granted cross-agency accounting authority.
- [ ] Accounting records must use integer minor units and explicit currency where money amounts are stored as facts.
- [ ] No API may calculate, return, cache, or persist authoritative account balance, available balance, unavailable balance, movement total, trial balance, statement total, PAR, collection ratio, till difference, or report metric.
- [ ] Journal entries and lines may be created only as draft/storage facts in this safe slice; posting to financial authority is blocked unless it only validates immutable double-entry structure and does not derive balances.
- [ ] Customer accounts are containers only. Opening an account must not move cash, create ledger postings, or imply a balance.
- [ ] Account holds are lifecycle facts only. They must not compute available balance.
- [ ] Sector and sub-sector APIs are references only. They must not compute portfolio or risk reports.
- [ ] Formula-dependent behavior must link back to `docs/domain/stakeholder-formula-questions.md` and remain unchecked until approved.

## Why Module 3 Is Next

- [ ] It follows the engineering order in `docs/domain/modules.md`: Accounting & Financial Architecture comes after CRM/KYC and before Credit/Cash workflows depend on it.
- [ ] Existing foundation migrations already created structural tables for `ledger_accounts`, `customer_accounts`, `account_holds`, `journal_entries`, `journal_lines`, `sectors`, and `sub_sectors`.
- [ ] The safe work is mostly CRUD, lifecycle, authorization, audit, public contract, and integrity hardening.
- [ ] It prepares stable account and ledger references for future Module 4 and Module 5 work without implementing formulas.

## Epic 1: Ledger Account Catalog

- [ ] DEV-0101: Implement ledger account model, policy, resource, requests, and controller.

As an accounting administrator, I want to manage the chart-of-accounts catalog without creating postings or balances.

Acceptance criteria:

- [ ] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [ ] `LedgerAccount` model exists with ULID public route key.
- [ ] API supports create, list, show, update, activate, deactivate, and archive where lifecycle semantics are safe.
- [ ] Ledger account code, name, account class, account type, normal balance side, parent account, status, and agency applicability are represented.
- [ ] Parent account references must be same agency scope or global-compatible according to documented rules.
- [ ] Parent-child relationships reject self-parenting and cycles.
- [ ] Account code uniqueness is enforced in the intended global/agency scope.
- [ ] Responses expose public IDs and business codes only, never internal IDs.
- [ ] No endpoint returns balance, movement, statement, or posting totals.
- [ ] Mutations are audit logged.

- [ ] DEV-0102: Seed or expose safe starter account-class metadata.

As an implementer, I want stable account-class values so API clients do not invent incompatible chart-of-accounts classes.

Acceptance criteria:

- [ ] Allowed account classes and normal balance sides are documented and validated.
- [ ] If seeding is used, seeders are generated or updated through Laravel-supported commands where applicable.
- [ ] Seeded values do not imply final PCG sign-off unless stakeholders approve.
- [ ] Tests cover invalid class, invalid side, duplicate code, and lifecycle transitions.

- [ ] SEC-0101: Review ledger account abuse paths.

As a security reviewer, I want the chart-of-accounts API to resist cross-agency account injection, hierarchy confusion, and destructive changes.

Acceptance criteria:

- [ ] Agency-scoped users cannot create or mutate global accounts unless explicitly authorized.
- [ ] Global-account users cannot accidentally mutate agency-local accounts without scope.
- [ ] Parent account cycles are impossible through create/update.
- [ ] Accounts referenced by customer accounts or journal lines cannot be destructively deleted.
- [ ] Audit logs avoid leaking internal IDs.

## Epic 2: Customer Account Containers

- [ ] DEV-0201: Implement customer account model, policy, resource, requests, and controller.

As an operations/accounting user, I want to open and maintain customer account containers for verified clients without moving money.

Acceptance criteria:

- [ ] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [ ] `CustomerAccount` model exists with ULID public route key.
- [ ] API supports create/open, list, show, update metadata, suspend, reactivate where allowed, and close with reason/date.
- [ ] Account creation requires an active, non-archived client in the same agency.
- [ ] If policy requires verified KYC, unverified clients are rejected. If policy is unresolved, the backlog must explicitly choose the safer default and document it.
- [ ] Account number is generated through the existing reference-number mechanism or validated as externally supplied with uniqueness controls.
- [ ] Ledger account links must point to active, compatible ledger accounts in the same agency/global scope.
- [ ] Opening or closing an account does not create journal entries, teller transactions, cash movements, or balance rows.
- [ ] Responses do not include accounting balance, available balance, unavailable balance, or movement totals.
- [ ] Mutations are audit logged.

- [ ] DEV-0202: Implement customer account listing and safe filters.

As an agency user, I want to find customer accounts by public account references without leaking cross-agency data.

Acceptance criteria:

- [ ] List endpoints are bounded by pagination limits.
- [ ] Filters support account number, status, client public ID, account type, and opened date range.
- [ ] Search does not expose PII beyond the caller's permissions.
- [ ] Agency users only see accounts in their active agency unless explicitly granted institution accounting read scope.
- [ ] Tests cover cross-agency access, internal ID rejection, status filtering, and account-number uniqueness.

- [ ] SEC-0201: Review customer account authority confusion.

As a security reviewer, I want account containers to avoid implying spendable balance or transaction authority.

Acceptance criteria:

- [ ] API wording and response fields do not imply funds are available.
- [ ] Account status changes do not post ledger entries.
- [ ] A proxy from Module 2 cannot operate an account until account-specific mandate rules are implemented.
- [ ] Archived/suspended clients cannot get new accounts.
- [ ] Tests prove no journal entries, journal lines, teller transactions, or account holds are created by account-opening workflows unless explicitly requested by their own endpoints.

## Epic 3: Account Holds As Lifecycle Facts

- [ ] DEV-0301: Implement account hold model, policy, resource, requests, and controller.

As an accounting user, I want to record that funds are reserved or released without calculating available balance.

Acceptance criteria:

- [ ] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [ ] `AccountHold` model exists with ULID public route key.
- [ ] API supports create/place, list, show, release, cancel/archive where allowed.
- [ ] Hold amount uses integer minor units and explicit currency.
- [ ] Hold amount must be positive.
- [ ] Hold currency must match the customer account currency if customer account currency exists; otherwise the limitation is documented.
- [ ] Release requires actor, timestamp, and reason/reference.
- [ ] Holds do not calculate or expose available balance.
- [ ] Mutations are audit logged.

- [ ] DEV-0302: Implement account hold safety rules.

As a future balance implementer, I want hold lifecycle facts to be unambiguous before available-balance formulas exist.

Acceptance criteria:

- [ ] Active holds cannot be released twice.
- [ ] Released/cancelled holds cannot be edited silently.
- [ ] Holds on closed accounts are rejected unless explicit override policy exists.
- [ ] Holds cannot reference accounts outside the actor's agency scope.
- [ ] Tests prove no balance, ledger, or teller side effects.

- [ ] SEC-0301: Review account hold abuse paths.

As a security reviewer, I want holds to resist hidden balance manipulation and cross-agency fund blocking.

Acceptance criteria:

- [ ] Cross-agency hold placement and release are denied.
- [ ] Negative, zero, overflow, decimal, and currency-invalid amounts are rejected.
- [ ] Idempotency is applied or explicitly required for retryable hold placement.
- [ ] Audit payloads record references without logging unnecessary PII.

## Epic 4: Journal Entry Storage And Double-Entry Integrity

- [ ] DEV-0401: Implement draft journal entry and line model/resource APIs.

As an accountant, I want to stage manual journal entries as immutable draft facts before any posting workflow calculates balances.

Acceptance criteria:

- [ ] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [ ] `JournalEntry` and `JournalLine` models exist with explicit relationships.
- [ ] API supports draft create, list, show, update draft description/lines before posting, submit for review if safe, and archive/cancel draft.
- [ ] Draft line create/update requires exactly one positive side: debit or credit, never both and never neither.
- [ ] Draft entries must be balanced before any status can move beyond draft.
- [ ] Lines reference active ledger accounts compatible with journal agency scope.
- [ ] Lines may reference customer account public IDs only if the account is in the same agency.
- [ ] Journal APIs do not post balances, create cash movements, or compute statements.
- [ ] Responses expose public references and line facts, not internal IDs.

- [ ] DEV-0402: Implement journal reversal scaffolding without balance projection.

As an accountant, I want reversal records to preserve auditability without mutating historical entries.

Acceptance criteria:

- [ ] Reversal creates a linked reversal draft or reversal record according to documented safe policy.
- [ ] Original journal entries and lines are not destructively edited.
- [ ] Reversal references the original public journal reference.
- [ ] Reversal does not compute account balances.
- [ ] Tests cover immutable original records, reversal link, and no balance projection.

- [ ] SEC-0401: Review journal integrity and posting confusion.

As a security reviewer, I want journal storage to resist unbalanced entries, duplicate retries, hidden internal IDs, and unauthorized cross-agency posting.

Acceptance criteria:

- [ ] Unbalanced drafts cannot be submitted.
- [ ] Posted/final statuses cannot be forged through ordinary update endpoints.
- [ ] Idempotency prevents duplicate draft creation where a retryable request supplies an idempotency key.
- [ ] Cross-agency ledger-account and customer-account line references are denied.
- [ ] No endpoint calls itself "posted" or "balance-affecting" unless explicitly approved.
- [ ] Tests prove no customer account balances or projections are created or changed.

## Epic 5: Sector And Sub-Sector References

- [ ] DEV-0501: Implement sector and sub-sector reference APIs.

As an administrator, I want to manage economic sector references used by clients and future loans without producing portfolio reports.

Acceptance criteria:

- [ ] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [ ] `Sector` and `SubSector` models exist with ULID public route keys.
- [ ] API supports create, list, show, update, activate, deactivate, and archive where safe.
- [ ] Sector and sub-sector codes are unique in their intended scope.
- [ ] Sub-sector records must reference active parent sectors unless explicitly updating lifecycle.
- [ ] Responses expose public IDs and codes, not internal IDs.
- [ ] No endpoint computes exposure, portfolio-at-risk, concentration, collection, or reporting metrics.
- [ ] Mutations are audit logged.

- [ ] DEV-0502: Integrate sector references safely with existing client metadata where approved.

As a future credit/reporting implementer, I want sector references available without implying report calculations.

Acceptance criteria:

- [ ] Any client-sector link is metadata only and optional unless already approved.
- [ ] Linking a sector to a client does not compute risk, loan eligibility, or portfolio metrics.
- [ ] Tests prove invalid/deactivated sector references are rejected.

- [ ] SEC-0501: Review reference-data abuse paths.

As a security reviewer, I want reference APIs to resist duplicate-code ambiguity and unauthorized taxonomy changes.

Acceptance criteria:

- [ ] Baseline staff cannot mutate accounting/reference taxonomies.
- [ ] Code updates are audited because codes may appear in reports and integrations.
- [ ] Deactivation does not break historical records.
- [ ] Tests cover duplicate codes, stale references, and authorization.

## Epic 6: Authorization, Documentation, And Operational Readiness

- [ ] DEV-0601: Add Module 3 permissions and role assignments.

As a platform administrator, I want accounting permissions represented explicitly so financial architecture authority is not inferred from CRM or teller permissions.

Acceptance criteria:

- [ ] Permissions exist for ledger account view/manage, customer account view/manage/close, account hold view/manage/release, journal view/manage/review/reverse, sector manage, and accounting audit view.
- [ ] Role seeding grants least-privilege defaults to platform admin, accountant, auditor, agency manager, and baseline staff.
- [ ] Teller, KYC, and loan roles do not receive accounting mutation permissions by default.
- [ ] Permission catalog API groups Module 3 permissions clearly.
- [ ] Tests cover seeded permission availability and default role grants.

- [ ] DEV-0602: Add Module 3 API documentation and operational runbook.

As an API consumer and operator, I want accounting architecture behavior documented so teams do not infer balance or posting behavior that is not implemented.

Acceptance criteria:

- [ ] Scramble/OpenAPI docs cover Module 3 endpoint request/response contracts.
- [ ] Response schemas exclude internal IDs and formula-derived balances.
- [ ] Operational docs explicitly state that account opening does not move cash, holds do not calculate available balance, and journal drafts do not create authoritative balances.
- [ ] Docs list unresolved stakeholder decisions from `docs/domain/stakeholder-formula-questions.md`.
- [ ] `php artisan scramble:export` passes.

## Not In Module 3 Safe Slice

- [ ] Authoritative accounting balance calculation.
- [ ] Available balance or unavailable balance calculation.
- [ ] Debit/credit movement totals.
- [ ] Trial balance, account statements, PAR, portfolio reports, or collection reports.
- [ ] Teller deposits, teller withdrawals, cash receipts, denominations, till sessions, or reconciliation differences.
- [ ] Loan product setup, loan schedules, disbursement, repayment, penalties, arrears, or portfolio transfer workflows.
- [ ] Interest, fee, tax, insurance, guarantee deposit, rounding, repayment allocation, or early settlement formulas.
- [ ] End-of-day jobs that compute balances, cash differences, report metrics, interest, penalties, or movements.

## Open Questions Before Full Module 3 Completion

- [ ] Confirm final chart-of-accounts classes, account types, and normal balance sides.
- [ ] Confirm whether customer account opening requires verified KYC in all cases.
- [ ] Confirm account number format and whether it is generated internally or supplied by operations.
- [ ] Confirm whether customer accounts can exist without a linked ledger account.
- [ ] Confirm whether account holds require pre-checking funds before placement.
- [ ] Confirm available-balance formula and whether holds reduce availability immediately.
- [ ] Confirm journal draft, review, approval, posting, and reversal workflow vocabulary.
- [ ] Confirm whether manual journal entries are allowed before teller/cash modules exist.
- [ ] Confirm whether sector/sub-sector taxonomy follows a regulatory catalog.
- [ ] Confirm production requirements for accounting audit exports and retention.
