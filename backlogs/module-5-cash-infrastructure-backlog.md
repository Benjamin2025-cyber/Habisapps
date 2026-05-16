# Module 5 Backlog: Cash Infrastructure Safe Slice

This backlog covers the safe implementation slice of stakeholder Module 5 from `stakeholderResources/definedModules.md` and `stakeholderResources/Database-Schema&Entity-Relationship-(ER)-Mapping.md`: currency denominations and minimal till setup/reference APIs.

Module 5 is only safe while it remains infrastructure and reference-data work. It must not implement deposits, withdrawals, teller transactions, teller session lifecycle, till opening/closing, till theoretical balance, cash reconciliation, manual journal posting, cash limits enforcement, or any ledger-impacting behavior until accounting posting and stakeholder formula decisions are approved.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.
- When Laravel provides a generator for an artifact, use the Laravel/Artisan command and record the exact command under the story.

Completion note (2026-05-16): this original safe-slice backlog is superseded for full cash operations by
`backlogs/module-5-cash-operations-completion-backlog.md`. The safe-slice deferrals below are now either implemented
there or intentionally kept outside the approved zero-tolerance cash policy.

## Implementation Status

Implemented and verified:

- [x] DEV-0101
- [x] DEV-0102
- [x] DEV-0201
- [x] DEV-0202
- [x] SEC-0101
- [x] DEV-0301
- [x] DEV-0302

Verification to complete during implementation:

- [x] `vendor/bin/pint --test`
- [x] `vendor/bin/phpstan analyze`
- [x] `php artisan test --filter=Module5CashInfrastructureTest`
- [x] `php artisan test`
- [x] `php artisan scramble:export`

Scaffolding and generator command log:

- [x] `php artisan make:model Denomination`
- [x] `php artisan make:model Till`
- [x] `php artisan make:controller Api/V1/DenominationController`
- [x] `php artisan make:controller Api/V1/TillController`
- [x] `php artisan make:request StoreDenominationRequest`
- [x] `php artisan make:request UpdateDenominationRequest`
- [x] `php artisan make:request StoreTillRequest`
- [x] `php artisan make:request UpdateTillRequest`
- [x] `php artisan make:resource DenominationResource`
- [x] `php artisan make:resource DenominationCollection`
- [x] `php artisan make:resource TillResource`
- [x] `php artisan make:resource TillCollection`
- [x] `php artisan make:policy DenominationPolicy --model=Denomination`
- [x] `php artisan make:policy TillPolicy --model=Till`
- [x] `php artisan make:test Module5CashInfrastructureTest`
- [x] No Composer package installation is expected for this safe slice.

## Guiding Rules

- [x] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually.
- [x] Public APIs must expose `public_id`, denomination codes, till codes, and agency public IDs, not internal integer IDs.
- [x] Every mutation must be authenticated, authorized, agency-scoped where applicable, and audit logged.
- [x] Denominations are reference data only. They must not compute cash counts, reconciliation totals, or accepted tender policies beyond active/inactive validation.
- [x] Tills are setup records only. They must not open sessions, close sessions, accept deposits, process withdrawals, calculate balances, or post ledger entries.
- [x] The existing `tills` schema is the implementation boundary for this safe slice: `agency_id`, `code`, `name`, `type`, `status`, and optional `assigned_user_id`.
- [x] The original safe-slice deferred ER-mapping fields are now covered by the Module 5 completion backlog and stakeholder-complete migration: `gl_account_id` as `ledger_account_id`, balance fields in minor units, `daily_state`, `requires_denominations`, `nature`, `is_central_till`, max balance, max withdrawal, and currency.
- [x] Money-like denomination values must be stored and returned as integer minor units with explicit currency; no rounding or conversion is allowed.
- [x] Agency users must never view or mutate tills outside their active agency unless explicitly granted cross-agency cash administration authority.
- [x] Formula-dependent behavior must link back to `docs/domain/stakeholder-formula-questions.md` and remain unchecked until approved.

## Why This Safe Slice Is Next

- [x] It is explicitly part of stakeholder Module 5: denominations and tills are listed as cash infrastructure.
- [x] Existing foundation migrations already created structural tables for `denominations` and `tills`.
- [x] The work is CRUD, lifecycle, authorization, audit, public contract, and agency-scope hardening.
- [x] It prepares stable references for future teller sessions and reconciliation without implementing cash movement or formulas.
- [x] It avoids the unsafe parts of the ER mapping: calculated balances, transaction posting, reconciliation differences, and denomination line totals.

## Epic 1: Currency Denomination Reference Data

- [x] DEV-0101: Implement denomination model, resource, requests, policy, controller, and routes.

As a cash administrator, I want to manage accepted currency denominations so future cash-counting screens can reference stable denomination codes.

Acceptance criteria:

- [x] `Denomination` model exists with ULID public route key.
- [x] API supports create, list, show, update, activate/deactivate where safe.
- [x] Request validation requires code, label/name, currency, value in minor units, type, and status.
- [x] Denomination value must be positive integer minor units.
- [x] Denomination code and value uniqueness follow the existing database constraints by currency.
- [x] Responses expose public ID, code, label, value minor units, currency, type, status, and timestamps.
- [x] No response exposes internal integer IDs.
- [x] No endpoint computes cash count totals or reconciliation line totals.
- [x] Mutations are audit logged with safe properties only.

Command log:

- [x] See scaffolding and generator command log above.

- [x] DEV-0102: Add denomination API tests.

As a reviewer, I want executable proof that denomination APIs are safe reference-data endpoints only.

Acceptance criteria:

- [x] Tests cover authorized create/list/show/update/lifecycle behavior.
- [x] Tests cover duplicate code/value validation by currency.
- [x] Tests cover invalid zero or negative values.
- [x] Tests prove unauthenticated and unauthorized users cannot mutate denominations.
- [x] Tests prove responses do not expose internal integer IDs.
- [x] Tests prove no teller session, transaction, reconciliation, or journal record is created by denomination endpoints.

Command log:

- [x] `php artisan make:test Module5CashInfrastructureTest`

Adversarial review:

- [x] Confirmed denomination endpoints return only public IDs/reference fields and no internal integer IDs.
- [x] Confirmed denomination endpoints do not create teller sessions, teller transactions, till reconciliations, reconciliation lines, journal entries, or journal lines.
- [x] Confirmed invalid zero values, duplicate code, duplicate value, unauthenticated mutation, and unauthorized mutation fail closed.
- [x] Fixed the review finding that test authentication state could mask the intended validation path by switching the affected assertion to explicit Sanctum acting user state.

## Epic 2: Minimal Till Setup Records

- [x] DEV-0201: Implement minimal till model, resource, requests, policy, controller, and routes.

As a cash administrator, I want to register physical or logical tills inside an agency without enabling cash movement.

Acceptance criteria:

- [x] `Till` model exists with ULID public route key.
- [x] API supports create, list, show, update, activate/deactivate where safe.
- [x] Request validation accepts only fields present in the current safe schema: agency reference when authorized, code, name, type, status, and assigned user reference.
- [x] Till code uniqueness is enforced inside agency scope.
- [x] Assigned user, when supplied, must be active staff in the same agency scope.
- [x] Agency-scoped users can only create, view, and update tills in their active agency.
- [x] Cross-agency cash administration, if allowed for platform roles, is explicit and tested.
- [x] Responses expose public ID, agency public ID, code, name, type, status, assigned user public ID, and timestamps.
- [x] No response exposes internal integer IDs.
- [x] No endpoint opens a till, closes a till, starts a teller session, calculates balances, enforces cash limits, or posts ledger entries.
- [x] Mutations are audit logged with safe properties only.

Command log:

- [x] See scaffolding and generator command log above.

- [x] DEV-0202: Add minimal till API tests.

As a reviewer, I want executable proof that till setup APIs cannot be used as cash movement or session lifecycle endpoints.

Acceptance criteria:

- [x] Tests cover authorized create/list/show/update/lifecycle behavior.
- [x] Tests cover agency scoping and cross-agency denial for agency-scoped users.
- [x] Tests cover assigned user same-agency validation.
- [x] Tests cover duplicate till code rejection inside an agency.
- [x] Tests prove unauthenticated and unauthorized users cannot mutate tills.
- [x] Tests prove responses do not expose internal integer IDs.
- [x] Tests prove no teller session, teller transaction, till reconciliation, reconciliation line, or journal record is created by till setup endpoints.

Command log:

- [x] `php artisan make:test Module5CashInfrastructureTest`

Adversarial review:

- [x] Confirmed till endpoints accept only the current safe schema fields and reject deferred ER fields such as `opening_balance`.
- [x] Confirmed agency manager till access is scoped to the active agency and cross-agency show/update attempts fail closed.
- [x] Confirmed assigned users must be active staff in the till agency.
- [x] Fixed the review finding that cross-agency tests were still using the previous request header state by switching the affected assertions to explicit Sanctum acting user state.

## Epic 3: Security, Documentation, And Operational Readiness

- [x] SEC-0101: Adversarial review of cash infrastructure APIs.

As a security reviewer, I want to prove this slice cannot become a backdoor for cash movement, cross-agency till access, or balance leakage.

Acceptance criteria:

- [x] Cross-agency till access is denied for agency-scoped users.
- [x] Denomination endpoints are protected from unauthorized mutation.
- [x] Till setup endpoints do not accept deferred ER fields such as opening balance, closing balance, daily state, limits, or ledger account ID.
- [x] API responses do not expose internal IDs, balance fields, ledger posting state, teller session state, or reconciliation state.
- [x] Audit event properties remain safe and do not include internal integer IDs or sensitive data.
- [x] Final adversarial review records any findings and fixes under this story.

Command log:

- [x] No generator command expected; review findings were addressed in tests and controller typing.

- [x] DEV-0301: Update API documentation and operational runbook.

As an operator, I want the cash infrastructure endpoints documented with clear non-cash-movement boundaries.

Acceptance criteria:

- [x] Scramble/OpenAPI export includes denomination and till setup endpoints.
- [x] Operational docs explain that denominations and tills are setup/reference records only.
- [x] Docs explicitly state that teller sessions, deposits, withdrawals, journal posting, cash limits, opening/closing balances, and reconciliation remain unavailable in this safe slice.
- [x] Docs link blocked formula/calculation areas to `docs/domain/stakeholder-formula-questions.md` and `docs/domain/formula-guardrails.md`.

Command log:

- [x] Documentation added manually: `docs/domain/module-5-cash-infrastructure.md`.
- [x] Documentation updated manually: `docs/domain/cash-operations.md`.
- [x] `php artisan scramble:export`

- [x] DEV-0302: Run final verification gates.

As a maintainer, I want the safe slice merged only after static analysis, tests, formatting, and API export pass.

Acceptance criteria:

- [x] `vendor/bin/pint --test` passes.
- [x] `vendor/bin/phpstan analyze` passes.
- [x] `php artisan test --filter=Module5CashInfrastructureTest` passes.
- [x] `php artisan test` passes.
- [x] `php artisan scramble:export` passes.
- [x] This backlog is updated with completed stories, command logs, and adversarial review notes.

Command log:

- [x] `vendor/bin/pint --test`
- [x] `vendor/bin/phpstan analyze`
- [x] `php artisan test --filter=Module5CashInfrastructureTest`
- [x] `php artisan test`
- [x] `php artisan scramble:export`

Adversarial review:

- [x] Confirmed focused Module 5 tests cover authorization, agency scoping, duplicate protection, deferred-field rejection, no internal IDs, and no cash workflow side effects.
- [x] Confirmed static scan of Module 5 controllers/resources/requests does not expose or accept deferred balance, reconciliation, posting, teller transaction, or session fields except as negative test payloads.
- [x] Fixed PHPStan findings around nullable agency handling, mixed currency input handling, and Eloquent builder dynamic call style.
- [x] Verified final gates with `vendor/bin/pint --test`, `vendor/bin/phpstan analyze`, `php artisan test --filter=Module5CashInfrastructureTest`, `php artisan test`, and `php artisan scramble:export`.

## Explicitly Deferred From This Safe Slice

- [x] Teller session opening, closing, and lifecycle transitions are implemented in the completion backlog.
- [x] Teller deposits and withdrawals are implemented in the completion backlog.
- [x] Cash receipts and event-number generation for cash movement are implemented in the completion backlog.
- [x] Manual journal posting or ledger-authoritative journal entries are implemented through the approved journal workflow.
- [x] Till theoretical balance, actual balance, opening balance, closing balance, and balance difference calculations are implemented under the zero-tolerance policy.
- [x] Denomination count line totals are implemented for reconciliation.
- [x] Till reconciliation creation, approval, and difference denial are implemented; adjustment posting is intentionally excluded because the approved policy is zero tolerance.
- [x] Cash limit enforcement for maximum till balance or maximum withdrawal is implemented.
- [x] Tills link to cash ledger accounts through `ledger_account_id`.
- [x] Cash-owned balance, movement, reconciliation, rounding, posting, and report hooks are implemented where approved; fee, interest, penalty, loan, and repayment formulas remain with their owning modules.

## Open Questions Before Full Module 5 Completion

- [x] Confirm accepted XAF denominations and whether denominations can be deactivated historically.
- [x] Confirm final till type vocabulary, central till semantics, and whether central tills are per agency or institution-wide.
- [x] Confirm whether every till must link to a ledger account and which account classes are valid.
- [x] Confirm whether a teller can have more than one active till/session.
- [x] Confirm whether a till can have more than one active teller/session.
- [x] Confirm opening balance, theoretical balance, actual balance, and reconciliation difference formulas.
- [x] Confirm cash limit enforcement semantics and approval override requirements.
- [x] Confirm teller transaction posting workflow and idempotency requirements.
- [x] Confirm manual journal approval workflow for cash-originated operations.

## Completion Gate

- [x] All non-deferred stories are checked.
- [x] Every completed story has evidence in tests, docs, or code review notes.
- [x] All command logs are filled in for generated artifacts.
- [x] `vendor/bin/pint --test` passes.
- [x] `vendor/bin/phpstan analyze` passes.
- [x] `php artisan test` passes.
- [x] `php artisan scramble:export` passes.
- [x] Final adversarial review finds no unresolved high or medium risks introduced by the cash infrastructure work.
