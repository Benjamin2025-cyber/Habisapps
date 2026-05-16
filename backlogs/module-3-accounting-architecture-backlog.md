# Module 3 Backlog: Accounting & Financial Architecture

This backlog covers the safe implementation slice of stakeholder Module 3 from `stakeholderResources/definedModules.md`: chart of accounts, customer account containers, sector/sub-sector references, account-hold lifecycle facts, and immutable journal storage scaffolding.

Module 3 is the next safe module after Module 2 only if it remains structural. It must not implement balance formulas, available-balance formulas, cash posting, deposit/withdrawal workflows, interest, fees, penalties, repayment allocation, reporting metrics, teller reconciliation, or any other formula-dependent behavior until `docs/domain/stakeholder-formula-questions.md` is answered and approved.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.

Completion note (2026-05-16): this original structural backlog is superseded for final accounting scope by
`backlogs/module-3-accounting-completion-backlog.md`. Safe-slice deferrals below are retained as historical context
and are checked when the completion backlog has implemented or explicitly assigned them to another owning module.

## Implementation Status

Implemented and verified:

- [x] DEV-0101, DEV-0102, SEC-0101
- [x] DEV-0201, DEV-0202, SEC-0201
- [x] DEV-0301, DEV-0302, SEC-0301
- [x] DEV-0401, DEV-0402, SEC-0401
- [x] DEV-0501, SEC-0501
- [x] DEV-0601, DEV-0602

Completion backlog resolution:

- [x] DEV-0502 is implemented in `backlogs/module-3-accounting-completion-backlog.md`.
- [x] Production decision: operational ledger accounts remain agency-scoped; institution-level EMF/COBAC references live in `emf_regulatory_accounts`.

Verification to complete during implementation:

- [x] `php artisan test --filter=Module3AccountingArchitectureTest`
- [x] `php artisan test --filter="Module1AdministrationTest|FoundationOperationsTest|StaffUserManagementTest|Module2CrmKycTest|Module3AccountingArchitectureTest"`
- [x] `vendor/bin/phpstan analyze`
- [x] `php artisan scramble:export`
- [x] `vendor/bin/pint --test`

Scaffolding and generator command log:

- [x] `php artisan make:model LedgerAccount`
- [x] `php artisan make:model CustomerAccount`
- [x] `php artisan make:model AccountHold`
- [x] `php artisan make:model JournalEntry`
- [x] `php artisan make:model JournalLine`
- [x] `php artisan make:model Sector`
- [x] `php artisan make:model SubSector`
- [x] `php artisan make:controller Api/V1/LedgerAccountController`
- [x] `php artisan make:controller Api/V1/CustomerAccountController`
- [x] `php artisan make:controller Api/V1/AccountHoldController`
- [x] `php artisan make:controller Api/V1/JournalEntryController`
- [x] `php artisan make:controller Api/V1/JournalLineController`
- [x] `php artisan make:controller Api/V1/SectorController`
- [x] `php artisan make:controller Api/V1/SubSectorController`
- [x] `php artisan make:request StoreLedgerAccountRequest`
- [x] `php artisan make:request UpdateLedgerAccountRequest`
- [x] `php artisan make:request StoreCustomerAccountRequest`
- [x] `php artisan make:request UpdateCustomerAccountRequest`
- [x] `php artisan make:request StoreAccountHoldRequest`
- [x] `php artisan make:request UpdateAccountHoldRequest`
- [x] `php artisan make:request ReleaseAccountHoldRequest`
- [x] `php artisan make:request StoreJournalEntryRequest`
- [x] `php artisan make:request UpdateJournalEntryRequest`
- [x] `php artisan make:request StoreJournalLineRequest`
- [x] `php artisan make:request UpdateJournalLineRequest`
- [x] `php artisan make:request StoreSectorRequest`
- [x] `php artisan make:request UpdateSectorRequest`
- [x] `php artisan make:request StoreSubSectorRequest`
- [x] `php artisan make:request UpdateSubSectorRequest`
- [x] `php artisan make:resource LedgerAccountResource`
- [x] `php artisan make:resource LedgerAccountCollection`
- [x] `php artisan make:resource CustomerAccountResource`
- [x] `php artisan make:resource CustomerAccountCollection`
- [x] `php artisan make:resource AccountHoldResource`
- [x] `php artisan make:resource AccountHoldCollection`
- [x] `php artisan make:resource JournalEntryResource`
- [x] `php artisan make:resource JournalEntryCollection`
- [x] `php artisan make:resource JournalLineResource`
- [x] `php artisan make:resource JournalLineCollection`
- [x] `php artisan make:resource SectorResource`
- [x] `php artisan make:resource SectorCollection`
- [x] `php artisan make:resource SubSectorResource`
- [x] `php artisan make:resource SubSectorCollection`
- [x] `php artisan make:policy LedgerAccountPolicy --model=LedgerAccount`
- [x] `php artisan make:policy CustomerAccountPolicy --model=CustomerAccount`
- [x] `php artisan make:policy AccountHoldPolicy --model=AccountHold`
- [x] `php artisan make:policy JournalEntryPolicy --model=JournalEntry`
- [x] `php artisan make:policy JournalLinePolicy --model=JournalLine`
- [x] `php artisan make:policy SectorPolicy --model=Sector`
- [x] `php artisan make:policy SubSectorPolicy --model=SubSector`
- [x] `php artisan make:test Module3AccountingArchitectureTest`
- [x] `php artisan make:migration add_public_id_to_journal_lines_table --table=journal_lines`

Completion backlog resolution:

- [x] Balance, movement, availability, posting, and accounting reporting are implemented in the completion backlog. Fee, interest, penalty, and repayment-allocation formulas remain with their owning loan/cash modules.
- [x] Chart-of-accounts taxonomy, account-product catalog, and manual journal approval policy are implemented in the completion backlog.

## Guiding Rules

- [x] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually as needed.
- [x] Each implementation story must record the exact Artisan commands used, for example `php artisan make:model`, `php artisan make:controller`, `php artisan make:request`, `php artisan make:policy`, `php artisan make:resource`, and `php artisan make:test`.
- [x] Do not hand-create Laravel models, controllers, requests, resources, policies, tests, seeders, or migrations when an Artisan generator exists.
- [x] Composer must be used for package installation; package config and migrations must be published through Laravel/vendor publish commands where provided.
- [x] Public APIs must expose `public_id`, account numbers, and business references, not internal integer IDs.
- [x] Every mutation must be authenticated, authorized, agency-scoped where applicable, idempotency-aware where retryable, and audit logged.
- [x] Agency users must never view or mutate records outside their active agency unless explicitly granted cross-agency accounting authority.
- [x] Accounting records must use integer minor units and explicit currency where money amounts are stored as facts.
- [x] No API may calculate, return, cache, or persist authoritative account balance, available balance, unavailable balance, movement total, trial balance, statement total, PAR, collection ratio, till difference, or report metric.
- [x] Journal entries and lines may be created only as draft/storage facts in this safe slice; posting to financial authority is blocked unless it only validates immutable double-entry structure and does not derive balances.
- [x] Customer accounts are containers only. Opening an account must not move cash, create ledger postings, or imply a balance.
- [x] Account holds are lifecycle facts only. They must not compute available balance.
- [x] Sector and sub-sector APIs are references only. They must not compute portfolio or risk reports.
- [x] Formula-dependent behavior must link back to `docs/domain/stakeholder-formula-questions.md` and remain unchecked until approved.

## Why Module 3 Is Next

- [x] It follows the engineering order in `docs/domain/modules.md`: Accounting & Financial Architecture comes after CRM/KYC and before Credit/Cash workflows depend on it.
- [x] Existing foundation migrations already created structural tables for `ledger_accounts`, `customer_accounts`, `account_holds`, `journal_entries`, `journal_lines`, `sectors`, and `sub_sectors`.
- [x] The safe work is mostly CRUD, lifecycle, authorization, audit, public contract, and integrity hardening.
- [x] It prepares stable account and ledger references for future Module 4 and Module 5 work without implementing formulas.

## Epic 1: Ledger Account Catalog

- [x] DEV-0101: Implement ledger account model, policy, resource, requests, and controller.

As an accounting administrator, I want to manage the chart-of-accounts catalog without creating postings or balances.

Acceptance criteria:

- [x] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [x] `LedgerAccount` model exists with ULID public route key.
- [x] API supports create, list, show, update, activate, deactivate, and archive where lifecycle semantics are safe.
- [x] Ledger account code, name, account class, account type, normal balance side, parent account, status, and agency applicability are represented.
- [x] Parent account references must be same agency scope; global/shared ledger accounts are deferred because the current agency-scoped database constraints require `agency_id`.
- [x] Parent-child relationships reject self-parenting and cycles.
- [x] Account code uniqueness is enforced in the intended agency scope.
- [x] Responses expose public IDs and business codes only, never internal IDs.
- [x] No endpoint returns balance, movement, statement, or posting totals.
- [x] Mutations are audit logged.

- [x] DEV-0102: Seed or expose safe starter account-class metadata.

As an implementer, I want stable account-class values so API clients do not invent incompatible chart-of-accounts classes.

Acceptance criteria:

- [x] Allowed account classes and normal balance sides are documented and validated.
- [x] If seeding is used, seeders are generated or updated through Laravel-supported commands where applicable.
- [x] Seeded values do not imply final PCG sign-off unless stakeholders approve.
- [x] Tests cover invalid class, invalid side, duplicate code, and lifecycle transitions.

- [x] SEC-0101: Review ledger account abuse paths.

As a security reviewer, I want the chart-of-accounts API to resist cross-agency account injection, hierarchy confusion, and destructive changes.

Acceptance criteria:

- [x] Agency-scoped users cannot create or mutate global accounts unless explicitly authorized.
- [x] Global-account users cannot accidentally mutate agency-local accounts without scope.
- [x] Parent account cycles are impossible through create/update.
- [x] Accounts referenced by customer accounts or journal lines cannot be destructively deleted.
- [x] Audit logs avoid leaking internal IDs.

## Epic 2: Customer Account Containers

- [x] DEV-0201: Implement customer account model, policy, resource, requests, and controller.

As an operations/accounting user, I want to open and maintain customer account containers for verified clients without moving money.

Acceptance criteria:

- [x] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [x] `CustomerAccount` model exists with ULID public route key.
- [x] API supports create/open, list, show, update metadata, suspend, reactivate where allowed, and close with reason/date.
- [x] Account creation requires an active, non-archived client in the same agency.
- [x] If policy requires verified KYC, unverified clients are rejected. If policy is unresolved, the backlog must explicitly choose the safer default and document it.
- [x] Account number is generated through the existing reference-number mechanism or validated as externally supplied with uniqueness controls.
- [x] Ledger account links must point to active, compatible ledger accounts in the same agency scope; global/shared ledger links are deferred pending a database design change.
- [x] Opening or closing an account does not create journal entries, teller transactions, cash movements, or balance rows.
- [x] Responses do not include accounting balance, available balance, unavailable balance, or movement totals.
- [x] Mutations are audit logged.

- [x] DEV-0202: Implement customer account listing and safe filters.

As an agency user, I want to find customer accounts by public account references without leaking cross-agency data.

Acceptance criteria:

- [x] List endpoints are bounded by pagination limits.
- [x] Filters support account number, status, client public ID, account type, and opened date range.
- [x] Search does not expose PII beyond the caller's permissions.
- [x] Agency users only see accounts in their active agency unless explicitly granted institution accounting read scope.
- [x] Tests cover cross-agency access, internal ID rejection, status filtering, and account-number uniqueness.

- [x] SEC-0201: Review customer account authority confusion.

As a security reviewer, I want account containers to avoid implying spendable balance or transaction authority.

Acceptance criteria:

- [x] API wording and response fields do not imply funds are available.
- [x] Account status changes do not post ledger entries.
- [x] A proxy from Module 2 cannot operate an account until account-specific mandate rules are implemented.
- [x] Archived/suspended clients cannot get new accounts.
- [x] Tests prove no journal entries, journal lines, teller transactions, or account holds are created by account-opening workflows unless explicitly requested by their own endpoints.

## Epic 3: Account Holds As Lifecycle Facts

- [x] DEV-0301: Implement account hold model, policy, resource, requests, and controller.

As an accounting user, I want to record that funds are reserved or released without calculating available balance.

Acceptance criteria:

- [x] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [x] `AccountHold` model exists with ULID public route key.
- [x] API supports create/place, list, show, release, cancel/archive where allowed.
- [x] Hold amount uses integer minor units and explicit currency.
- [x] Hold amount must be positive.
- [x] Hold currency must match the customer account currency if customer account currency exists; otherwise the limitation is documented.
- [x] Release requires actor, timestamp, and reason/reference.
- [x] Holds do not calculate or expose available balance.
- [x] Mutations are audit logged.

- [x] DEV-0302: Implement account hold safety rules.

As a future balance implementer, I want hold lifecycle facts to be unambiguous before available-balance formulas exist.

Acceptance criteria:

- [x] Active holds cannot be released twice.
- [x] Released/cancelled holds cannot be edited silently.
- [x] Holds on closed accounts are rejected unless explicit override policy exists.
- [x] Holds cannot reference accounts outside the actor's agency scope.
- [x] Tests prove no balance, ledger, or teller side effects.

- [x] SEC-0301: Review account hold abuse paths.

As a security reviewer, I want holds to resist hidden balance manipulation and cross-agency fund blocking.

Acceptance criteria:

- [x] Cross-agency hold placement and release are denied.
- [x] Negative, zero, overflow, decimal, and currency-invalid amounts are rejected.
- [x] Idempotency is applied or explicitly required for retryable hold placement.
- [x] Audit payloads record references without logging unnecessary PII.

## Epic 4: Journal Entry Storage And Double-Entry Integrity

- [x] DEV-0401: Implement draft journal entry and line model/resource APIs.

As an accountant, I want to stage manual journal entries as immutable draft facts before any posting workflow calculates balances.

Acceptance criteria:

- [x] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [x] `JournalEntry` and `JournalLine` models exist with explicit relationships.
- [x] API supports draft create, list, show, update draft description/lines before posting, submit for review if safe, and archive/cancel draft.
- [x] Draft line create/update requires exactly one positive side: debit or credit, never both and never neither.
- [x] Draft entries must be balanced before any status can move beyond draft.
- [x] Lines reference active ledger accounts compatible with journal agency scope.
- [x] Lines may reference customer account public IDs only if the account is in the same agency.
- [x] Journal APIs do not post balances, create cash movements, or compute statements.
- [x] Responses expose public references and line facts, not internal IDs.

- [x] DEV-0402: Implement journal reversal scaffolding without balance projection.

As an accountant, I want reversal records to preserve auditability without mutating historical entries.

Acceptance criteria:

- [x] Reversal creates a linked reversal draft or reversal record according to documented safe policy.
- [x] Original journal entries and lines are not destructively edited.
- [x] Reversal references the original public journal reference.
- [x] Reversal does not compute account balances.
- [x] Tests cover immutable original records, reversal link, and no balance projection.

- [x] SEC-0401: Review journal integrity and posting confusion.

As a security reviewer, I want journal storage to resist unbalanced entries, duplicate retries, hidden internal IDs, and unauthorized cross-agency posting.

Acceptance criteria:

- [x] Unbalanced drafts cannot be submitted.
- [x] Posted/final statuses cannot be forged through ordinary update endpoints.
- [x] Idempotency prevents duplicate draft creation where a retryable request supplies an idempotency key.
- [x] Cross-agency ledger-account and customer-account line references are denied.
- [x] No endpoint calls itself "posted" or "balance-affecting" unless explicitly approved.
- [x] Tests prove no customer account balances or projections are created or changed.

## Epic 5: Sector And Sub-Sector References

- [x] DEV-0501: Implement sector and sub-sector reference APIs.

As an administrator, I want to manage economic sector references used by clients and future loans without producing portfolio reports.

Acceptance criteria:

- [x] Models, controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands, then reviewed and adjusted manually.
- [x] `Sector` and `SubSector` models exist with ULID public route keys.
- [x] API supports create, list, show, update, activate, deactivate, and archive where safe.
- [x] Sector and sub-sector codes are unique in their intended scope.
- [x] Sub-sector records must reference active parent sectors unless explicitly updating lifecycle.
- [x] Responses expose public IDs and codes, not internal IDs.
- [x] No endpoint computes exposure, portfolio-at-risk, concentration, collection, or reporting metrics.
- [x] Mutations are audit logged.

- [x] DEV-0502: Integrate sector references safely with existing client metadata where approved.

As a future credit/reporting implementer, I want sector references available without implying report calculations.

Acceptance criteria:

- [x] Any client-sector link is metadata only and optional unless already approved.
- [x] Linking a sector to a client does not compute risk, loan eligibility, or portfolio metrics.
- [x] Tests prove invalid/deactivated sector references are rejected.

- [x] SEC-0501: Review reference-data abuse paths.

As a security reviewer, I want reference APIs to resist duplicate-code ambiguity and unauthorized taxonomy changes.

Acceptance criteria:

- [x] Baseline staff cannot mutate accounting/reference taxonomies.
- [x] Code updates are audited because codes may appear in reports and integrations.
- [x] Deactivation does not break historical records.
- [x] Tests cover duplicate codes, stale references, and authorization.

## Epic 6: Authorization, Documentation, And Operational Readiness

- [x] DEV-0601: Add Module 3 permissions and role assignments.

As a platform administrator, I want accounting permissions represented explicitly so financial architecture authority is not inferred from CRM or teller permissions.

Acceptance criteria:

- [x] Permissions exist for ledger account view/manage, customer account view/manage/close, account hold view/manage/release, journal view/manage/review/reverse, sector manage, and accounting audit view.
- [x] Role seeding grants least-privilege defaults to platform admin, accountant, auditor, agency manager, and baseline staff.
- [x] Teller, KYC, and loan roles do not receive accounting mutation permissions by default.
- [x] Permission catalog API groups Module 3 permissions clearly.
- [x] Tests cover seeded permission availability and default role grants.

- [x] DEV-0602: Add Module 3 API documentation and operational runbook.

As an API consumer and operator, I want accounting architecture behavior documented so teams do not infer balance or posting behavior that is not implemented.

Acceptance criteria:

- [x] Scramble/OpenAPI docs cover Module 3 endpoint request/response contracts.
- [x] Response schemas exclude internal IDs and formula-derived balances.
- [x] Operational docs explicitly state that account opening does not move cash, holds do not calculate available balance, and journal drafts do not create authoritative balances.
- [x] Docs list unresolved stakeholder decisions from `docs/domain/stakeholder-formula-questions.md`.
- [x] `php artisan scramble:export` passes.

## Not In Module 3 Safe Slice

- [x] Authoritative accounting balance calculation is implemented in the completion backlog.
- [x] Available balance or unavailable balance calculation is implemented in the completion backlog.
- [x] Debit/credit movement totals are implemented in accounting projections.
- [x] Trial balance and account statements are implemented; PAR, portfolio reports, and collection reports remain with credit/reporting modules.
- [x] Teller deposits, teller withdrawals, cash receipts, denominations, till sessions, and reconciliation differences belong to Module 5.
- [x] Loan product setup, loan schedules, disbursement, repayment, penalties, arrears, and portfolio transfer workflows belong to Module 4.
- [x] Interest, fee, tax, insurance, guarantee deposit, repayment allocation, and early settlement formulas belong to Module 4 policy and tests. XAF precision is approved.
- [x] End-of-day jobs that compute accounting balances and report metrics are implemented where owned by Module 3; cash, interest, penalties, and loan movements remain with their owning modules.

## Open Questions Before Full Module 3 Completion

- [x] Confirm final chart-of-accounts classes, account types, and normal balance sides.
- [x] Confirm whether customer account opening requires verified KYC in all cases.
- [x] Confirm account number format and whether it is generated internally or supplied by operations.
- [x] Confirm whether customer accounts can exist without a linked ledger account.
- [x] Confirm whether account holds require pre-checking funds before placement.
- [x] Confirm available-balance formula and whether holds reduce availability immediately.
- [x] Confirm journal draft, review, approval, posting, and reversal workflow vocabulary.
- [x] Confirm whether manual journal entries are allowed before teller/cash modules exist.
- [x] Confirm whether sector/sub-sector taxonomy follows a regulatory catalog.
- [x] Confirm production requirements for accounting audit exports and retention.
