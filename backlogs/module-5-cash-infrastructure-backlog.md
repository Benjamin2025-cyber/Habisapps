# Module 5 Backlog: Cash Infrastructure Safe Slice

This backlog covers the safe implementation slice of stakeholder Module 5 from `stakeholderResources/definedModules.md` and `stakeholderResources/Database-Schema&Entity-Relationship-(ER)-Mapping.md`: currency denominations and minimal till setup/reference APIs.

Module 5 is only safe while it remains infrastructure and reference-data work. It must not implement deposits, withdrawals, teller transactions, teller session lifecycle, till opening/closing, till theoretical balance, cash reconciliation, manual journal posting, cash limits enforcement, or any ledger-impacting behavior until accounting posting and stakeholder formula decisions are approved.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.
- When Laravel provides a generator for an artifact, use the Laravel/Artisan command and record the exact command under the story.

## Implementation Status

Not started:

- [ ] DEV-0101
- [ ] DEV-0102
- [ ] DEV-0201
- [ ] DEV-0202
- [ ] SEC-0101
- [ ] DEV-0301
- [ ] DEV-0302

Verification to complete during implementation:

- [ ] `vendor/bin/pint --test`
- [ ] `vendor/bin/phpstan analyze`
- [ ] `php artisan test --filter=Module5CashInfrastructureTest`
- [ ] `php artisan test`
- [ ] `php artisan scramble:export`

Scaffolding and generator command log:

- [ ] Record all `php artisan make:*` commands used for models, controllers, requests, resources, policies, and tests.
- [ ] No Composer package installation is expected for this safe slice.

## Guiding Rules

- [ ] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually.
- [ ] Public APIs must expose `public_id`, denomination codes, till codes, and agency public IDs, not internal integer IDs.
- [ ] Every mutation must be authenticated, authorized, agency-scoped where applicable, and audit logged.
- [ ] Denominations are reference data only. They must not compute cash counts, reconciliation totals, or accepted tender policies beyond active/inactive validation.
- [ ] Tills are setup records only. They must not open sessions, close sessions, accept deposits, process withdrawals, calculate balances, or post ledger entries.
- [ ] The existing `tills` schema is the implementation boundary for this safe slice: `agency_id`, `code`, `name`, `type`, `status`, and optional `assigned_user_id`.
- [ ] ER-mapping fields not present in the current schema are deferred unless a dedicated design and migration backlog is approved: `gl_account_id`, `opening_balance`, `last_closing_balance`, `last_closing_date`, `daily_state`, `requires_denominations`, `nature`, `is_central_till`, `max_balance_limit`, and `max_withdrawal_limit`.
- [ ] Money-like denomination values must be stored and returned as integer minor units with explicit currency; no rounding or conversion is allowed.
- [ ] Agency users must never view or mutate tills outside their active agency unless explicitly granted cross-agency cash administration authority.
- [ ] Formula-dependent behavior must link back to `docs/domain/stakeholder-formula-questions.md` and remain unchecked until approved.

## Why This Safe Slice Is Next

- [ ] It is explicitly part of stakeholder Module 5: denominations and tills are listed as cash infrastructure.
- [ ] Existing foundation migrations already created structural tables for `denominations` and `tills`.
- [ ] The work is CRUD, lifecycle, authorization, audit, public contract, and agency-scope hardening.
- [ ] It prepares stable references for future teller sessions and reconciliation without implementing cash movement or formulas.
- [ ] It avoids the unsafe parts of the ER mapping: calculated balances, transaction posting, reconciliation differences, and denomination line totals.

## Epic 1: Currency Denomination Reference Data

- [ ] DEV-0101: Implement denomination model, resource, requests, policy, controller, and routes.

As a cash administrator, I want to manage accepted currency denominations so future cash-counting screens can reference stable denomination codes.

Acceptance criteria:

- [ ] `Denomination` model exists with ULID public route key.
- [ ] API supports create, list, show, update, activate/deactivate where safe.
- [ ] Request validation requires code, label/name, currency, value in minor units, type, and status.
- [ ] Denomination value must be positive integer minor units.
- [ ] Denomination code and value uniqueness follow the existing database constraints by currency.
- [ ] Responses expose public ID, code, label, value minor units, currency, type, status, and timestamps.
- [ ] No response exposes internal integer IDs.
- [ ] No endpoint computes cash count totals or reconciliation line totals.
- [ ] Mutations are audit logged with safe properties only.

Command log:

- [ ] Record exact Artisan commands here.

- [ ] DEV-0102: Add denomination API tests.

As a reviewer, I want executable proof that denomination APIs are safe reference-data endpoints only.

Acceptance criteria:

- [ ] Tests cover authorized create/list/show/update/lifecycle behavior.
- [ ] Tests cover duplicate code/value validation by currency.
- [ ] Tests cover invalid zero or negative values.
- [ ] Tests prove unauthenticated and unauthorized users cannot mutate denominations.
- [ ] Tests prove responses do not expose internal integer IDs.
- [ ] Tests prove no teller session, transaction, reconciliation, or journal record is created by denomination endpoints.

Command log:

- [ ] Record exact Artisan commands here.

## Epic 2: Minimal Till Setup Records

- [ ] DEV-0201: Implement minimal till model, resource, requests, policy, controller, and routes.

As a cash administrator, I want to register physical or logical tills inside an agency without enabling cash movement.

Acceptance criteria:

- [ ] `Till` model exists with ULID public route key.
- [ ] API supports create, list, show, update, activate/deactivate where safe.
- [ ] Request validation accepts only fields present in the current safe schema: agency reference when authorized, code, name, type, status, and assigned user reference.
- [ ] Till code uniqueness is enforced inside agency scope.
- [ ] Assigned user, when supplied, must be active staff in the same agency scope.
- [ ] Agency-scoped users can only create, view, and update tills in their active agency.
- [ ] Cross-agency cash administration, if allowed for platform roles, is explicit and tested.
- [ ] Responses expose public ID, agency public ID, code, name, type, status, assigned user public ID, and timestamps.
- [ ] No response exposes internal integer IDs.
- [ ] No endpoint opens a till, closes a till, starts a teller session, calculates balances, enforces cash limits, or posts ledger entries.
- [ ] Mutations are audit logged with safe properties only.

Command log:

- [ ] Record exact Artisan commands here.

- [ ] DEV-0202: Add minimal till API tests.

As a reviewer, I want executable proof that till setup APIs cannot be used as cash movement or session lifecycle endpoints.

Acceptance criteria:

- [ ] Tests cover authorized create/list/show/update/lifecycle behavior.
- [ ] Tests cover agency scoping and cross-agency denial for agency-scoped users.
- [ ] Tests cover assigned user same-agency validation.
- [ ] Tests cover duplicate till code rejection inside an agency.
- [ ] Tests prove unauthenticated and unauthorized users cannot mutate tills.
- [ ] Tests prove responses do not expose internal integer IDs.
- [ ] Tests prove no teller session, teller transaction, till reconciliation, reconciliation line, or journal record is created by till setup endpoints.

Command log:

- [ ] Record exact Artisan commands here.

## Epic 3: Security, Documentation, And Operational Readiness

- [ ] SEC-0101: Adversarial review of cash infrastructure APIs.

As a security reviewer, I want to prove this slice cannot become a backdoor for cash movement, cross-agency till access, or balance leakage.

Acceptance criteria:

- [ ] Cross-agency till access is denied for agency-scoped users.
- [ ] Denomination endpoints are protected from unauthorized mutation.
- [ ] Till setup endpoints do not accept deferred ER fields such as opening balance, closing balance, daily state, limits, or ledger account ID.
- [ ] API responses do not expose internal IDs, balance fields, ledger posting state, teller session state, or reconciliation state.
- [ ] Audit event properties remain safe and do not include internal integer IDs or sensitive data.
- [ ] Final adversarial review records any findings and fixes under this story.

Command log:

- [ ] No generator command expected unless adding a documentation or test artifact.

- [ ] DEV-0301: Update API documentation and operational runbook.

As an operator, I want the cash infrastructure endpoints documented with clear non-cash-movement boundaries.

Acceptance criteria:

- [ ] Scramble/OpenAPI export includes denomination and till setup endpoints.
- [ ] Operational docs explain that denominations and tills are setup/reference records only.
- [ ] Docs explicitly state that teller sessions, deposits, withdrawals, journal posting, cash limits, opening/closing balances, and reconciliation remain unavailable in this safe slice.
- [ ] Docs link blocked formula/calculation areas to `docs/domain/stakeholder-formula-questions.md` and `docs/domain/formula-guardrails.md`.

Command log:

- [ ] Record exact commands here.

- [ ] DEV-0302: Run final verification gates.

As a maintainer, I want the safe slice merged only after static analysis, tests, formatting, and API export pass.

Acceptance criteria:

- [ ] `vendor/bin/pint --test` passes.
- [ ] `vendor/bin/phpstan analyze` passes.
- [ ] `php artisan test --filter=Module5CashInfrastructureTest` passes.
- [ ] `php artisan test` passes.
- [ ] `php artisan scramble:export` passes.
- [ ] This backlog is updated with completed stories, command logs, and adversarial review notes.

Command log:

- [ ] Record exact commands here.

## Explicitly Deferred From This Safe Slice

- [ ] Teller session opening, closing, and lifecycle transitions.
- [ ] Teller deposits and withdrawals.
- [ ] Cash receipts and event-number generation for cash movement.
- [ ] Manual journal posting or ledger-authoritative journal entries.
- [ ] Till theoretical balance, actual balance, opening balance, closing balance, or balance difference calculations.
- [ ] Denomination count line totals.
- [ ] Till reconciliation creation, approval, difference handling, or adjustment posting.
- [ ] Cash limit enforcement for maximum till balance or maximum withdrawal.
- [ ] Linking tills to ledger accounts through `gl_account_id` until accounting/till posting design is approved.
- [ ] Any balance, movement, reconciliation, rounding, posting, report, fee, interest, penalty, loan, repayment, or portfolio calculation.

## Open Questions Before Full Module 5 Completion

- [ ] Confirm accepted XAF denominations and whether denominations can be deactivated historically.
- [ ] Confirm final till type vocabulary, central till semantics, and whether central tills are per agency or institution-wide.
- [ ] Confirm whether every till must link to a ledger account and which account classes are valid.
- [ ] Confirm whether a teller can have more than one active till/session.
- [ ] Confirm whether a till can have more than one active teller/session.
- [ ] Confirm opening balance, theoretical balance, actual balance, and reconciliation difference formulas.
- [ ] Confirm cash limit enforcement semantics and approval override requirements.
- [ ] Confirm teller transaction posting workflow and idempotency requirements.
- [ ] Confirm manual journal approval workflow for cash-originated operations.

## Completion Gate

- [ ] All non-deferred stories are checked.
- [ ] Every completed story has evidence in tests, docs, or code review notes.
- [ ] All command logs are filled in for generated artifacts.
- [ ] `vendor/bin/pint --test` passes.
- [ ] `vendor/bin/phpstan analyze` passes.
- [ ] `php artisan test` passes.
- [ ] `php artisan scramble:export` passes.
- [ ] Final adversarial review finds no unresolved high or medium risks introduced by the cash infrastructure work.
