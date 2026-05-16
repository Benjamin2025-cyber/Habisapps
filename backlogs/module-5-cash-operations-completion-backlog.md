# Module 5 Completion Backlog: Cash And Teller Operations

This backlog complements `backlogs/module-5-cash-infrastructure-backlog.md`. The existing implementation covers denominations and minimal till setup. This backlog covers full stakeholder Module 5 completion.

## Guiding Rules

- [x] Teller operations must require an open teller session.
- [x] Cash movements must be idempotent, agency-scoped, auditable, and tied to accounting entries.
- [x] Tills must expose and validate status, assigned user, limits, denomination requirements, agency, currency, and cash ledger mapping.
- [x] Reconciliation must distinguish posted theoretical balance, physical count, pending transactions, and differences.
- [x] No cash operation may bypass Module 3 posting controls.

## Epic 1: Till Configuration Completion

- [x] DEV-0101: Extend till API to support complete ER fields.
  - [x] Expose ledger account link, daily state, denomination requirement, nature, central till flag, max balance, max withdrawal, and currency.
  - [x] Validate ledger account compatibility.
  - [x] Tests cover invalid ledger mapping, limit validation, and response contract.

- [x] DEV-0102: Implement till assignment rules.
  - [x] Assign teller users to tills.
  - [x] Prevent invalid active multi-till assignments for the same teller.
  - [x] Tests cover active assignment constraints.

## Epic 2: Teller Session Lifecycle

- [x] DEV-0201: Implement teller session opening.
  - [x] Require active till, eligible teller, business date, opening declaration, and denomination count if required.
  - [x] Tests cover duplicate open session denial and opening count validation.

- [x] DEV-0202: Implement teller session closing.
  - [x] Require closing declaration and denomination count.
  - [x] Block close with unresolved pending transactions or posted theoretical-balance differences.
  - [x] Tests cover close and blocking paths. Supervisor carry-forward/approval remains outside this zero-tolerance default.

## Epic 3: Teller Transactions

- [x] DEV-0301: Implement cash deposit API.
  - [x] Record teller transaction, event number, customer account, depositor name/address, operation code, and accounting entry.
  - [x] Tests cover idempotency, account status/session state through the open-session contract, and journal linkage.

- [x] DEV-0302: Implement cash withdrawal API.
  - [x] Check account availability with the Module 3 available-balance calculator.
  - [x] Enforce max withdrawal and posted till balance limits.
  - [x] Tests cover insufficient availability, limits, session state, and journal linkage.

- [x] DEV-0303: Implement reversal/cancellation workflow.
  - [x] Link reversal to original teller transaction.
  - [x] Create reversal accounting entry through the existing journal reversal service.
  - [x] Tests cover original transaction reversal status and duplicate reversal denial.

## Epic 4: Manual Cash-Originated Journal Operations

- [x] DEV-0401: Implement operations diverses cash workflow.
  - [x] Use journal entries/lines with optional cash operation code mapping.
  - [x] Require balanced debit/credit and approval through the Module 3 journal workflow.
  - [x] Tests cover posting, rejection, and no unbalanced cash operation.

## Epic 5: Reconciliation And Billetage

- [x] DEV-0501: Implement till reconciliation API.
  - [x] Create reconciliation records and denomination lines.
  - [x] Compute line totals, actual balance, theoretical balance, and difference.
  - [x] Tests cover denomination count, active denomination validation, and totals.

- [x] DEV-0502: Implement difference handling.
  - [x] Enforce zero tolerance for posted differences per stakeholder response.
  - [x] Block reconciliation while pending teller transactions exist.
  - [x] Tests cover zero tolerance, pending transaction blocking, and shortage/overage denial. Adjustment posting is intentionally not implemented because zero tolerance is the approved default.

## Epic 6: Cash Batch Integration

- [x] DEV-0601: Integrate cash close with Module 1 batch execution.
  - [x] Detect open sessions, unresolved reconciliations, and pending cash transactions.
  - [x] Block agency day close until cash controls pass.
  - [x] Tests cover batch close blocking and successful close.

## Completion Gate

- [x] Full teller session lifecycle implemented.
- [x] Deposits/withdrawals create accounting entries and are idempotent.
- [x] Till reconciliation and closing workflow implemented.
- [x] Cash close integrates with batch execution.
- [x] `vendor/bin/phpstan analyse --memory-limit=1G` passes.
- [x] `vendor/bin/pint --test` passes.
- [x] `php artisan scramble:export` passes and exports `api.json`.
- [x] Focused post-format verification passes: `php artisan test tests/Feature/Module5CashInfrastructureTest.php --filter='teller_session|till_reconciliation|cash_close|cash_deposit|manual_cash_journal'` passes with 6 tests / 177 assertions.
- [x] Additional Module 5 coverage passes: `php artisan test tests/Feature/Module5CashInfrastructureTest.php --filter='denomination|till_setup|till_assignment|withdrawal|reverse|across_agencies'` passes with 7 tests / 128 assertions; withdrawal and reversal assertions are covered inside the cash-deposit workflow test.
- [x] Full Module 5 feature verification passes: `php artisan test tests/Feature/Module5CashInfrastructureTest.php` passes with 12 tests / 294 assertions.
- [ ] `php artisan test` passes.
  - Status: not rerun to completion on 2026-05-16 because the full suite takes too long and was cancelled by operator request. Targeted Module 5 checks passed during the implementation pass and later changes were outside Module 5.
