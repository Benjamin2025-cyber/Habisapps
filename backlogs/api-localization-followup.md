# API Localization — Follow-up Catalogue

Generated: 2026-06-07. Companion to `backlogs/api-localization-backlog.md`.

The localization mechanism translates user-facing API envelope messages through
Laravel's translator, including the JSON dictionaries (`lang/en.json` /
`lang/fr.json`) for legacy English sentence keys. `ApiResponse` intentionally
does **not** translate `data`, `errors`, or `meta`, because those payloads can
contain machine values and database-backed content that must remain stable
across locales. Every **static** user-facing envelope message (a complete string
literal passed to `respond*()` or a caught-and-surfaced
`InvalidArgumentException`) now has an English→French entry in the dictionary.
Validation and domain error payloads must be translated before they are passed
to the response helper.

This catalogue lists the **remaining** user-facing messages that are **not**
dictionary-translatable as-is because they are **dynamic** — a literal is
concatenated with a runtime variable, or built with `sprintf()`/`%s`/`%d`. The
runtime string never matches a fixed dictionary key, so the static fragment
would not translate reliably as an envelope message. These require a follow-up
refactor to Laravel placeholder messages (e.g.
`__('domain.key', ['value' => $x])`) before they can be localized.

## Out of scope (intentionally not translated)

- Database-stored business content (journal narrations such as
  `'Cash deposited to customer account'`, transaction descriptions, notification
  record titles/bodies, report titles). Owned by a future DB-translation task.
- Machine contract values: enum/status values, permission names, audit event
  keys, public ids, reference numbers, operation/contract type codes,
  product/scheme proper names (Mourabaha, Ijara wa Iqtina, Moudaraba, etc.).

## Dynamic / concatenated user-facing messages (need placeholder refactor)

### Loans
- `LoanListQuery`: "The following filter keys are not supported: " + list
- `LoanCrudWorkflow`: "Unknown linked-account field(s): " + list; "Provide at least one linked-account field: " + list
- `LoanSetupState`: "Setup charges must be collected before disbursement: " + list
- `LoanSetupChargeWorkflow`: "Unsupported setup charge type: " + type
- `RecordLoanRepayment`: "Unsupported loan repayment component: " + component; "Active credit ledger mapping is required for " + operationCode
- `RescheduleLoan`: field + " must be an integer amount."
- `TransitionLoanStatus`: sprintf "Loan status cannot transition from %s to %s."
- `GenerateLoanSchedule`: sprintf schedule rate/units messages; component reconciliation messages
- `PhysicalCashAmount::validationMessage($currency)` (delegated dynamic message; shared with cash/insurance)

### Accounts / Journal Entries
- `JournalEntryListQuery`: "The following filter keys are not supported: " + list
- `JournalEntryWorkflow`: label + " must contain at least two lines."; label + " must be balanced."

### Cash Operations
- `TellerTransactionWorkflow`: "The following filter keys are not supported: " + list
- `TellerSessionWorkflow`: "Allowed sort values: " + list

### Islamic Finance
- `IslamicFinancingWorkflow`: "Islamic product is not usable for new financing: " + reasons; "... template is not usable for origination: " + reasons; installment-total/sale-price sprintf; "Operation code " + code + " is configured as non-reversible." / "... requires configured reversal_operation_code."; contract-template language/family selection messages
- `IslamicMappingValidationService`: "Approved Islamic mapping is required for " + code + " (" + side + ")." and sibling mapping messages
- `IslamicPartnershipWorkflow`: sprintf contribution/profit/buyout gate messages
- `IslamicIstisnaaProjectWorkflow`: sprintf milestone/supplier activation messages
- `IslamicSalamGoodsWorkflow`: sprintf terminal-status / evidence messages
- Governance services (`IslamicShariaAuthorityWorkflow`, `IslamicRegulatorySignoffWorkflow`, `IslamicTreatmentRoutingService`, `IslamicApprovalWorkflowService`, `Islamic*StateMachine`, `IslamicInterestGuardPolicy`, `IslamicProductFamilyRegistry`, `IslamicScreeningPolicyService`): status-transition and "Unknown ..." messages built with concatenation/sprintf

### CRM
- `ClientListQuery`: "The following filter keys are not supported: " + list
- `ClientGuarantorController` / `ClientIdentityDocumentController` / `ClientProxyController`: sprintf "A %s requires both front and back faces ..." / "... requires an expiry date ..."

### Staff / Authorization
- `RoleController`: "These permissions can never be delegated to non-platform roles: " + list

### HR / Payroll
- `HrPayrollRunWorkflow`: "Active operation mapping is required for " + code; "Mapping for " + code + " is missing required ledger."; "Mapped ledger for " + code + " must be active and agency-scoped."

### FX / Batch / Reporting
- `Fx*Workflow`: "Currency " + code + " is not active ..."; "No active exchange rate is available for " + base/quote
- `RegulatorySourceWorkflow`: "EMF regulatory account code already exists: " + code; "Parent code not found ...: " + parentCode
- `RegulatoryReportingWorkflow`: "Report definition source/field is not allowlisted: " + value
- `MappingCompletenessGate`: "Operation code does not exist/is not active: " + code; "No active operation mapping for " + code
- `BatchProcedureController`: "Only active operation codes can be attached. Inactive selections: " + list
- `ReportDefinitionController` / `UserNotificationController`: "The following filter keys are not supported: " + list

### Insurance / Notifications
- `InsuranceProductWorkflow` / `InsuranceProductReadinessService`: "Product readiness check failed: " + reasons
- `InsuranceClaimWorkflow`: "Active debit and credit ledger mappings are required for " + code; "Required claim evidence is missing: " + types
- `InsuranceAccountingService`: "Active credit/debit ledger mapping is required for " + code
- `NotificationOutbox` / `NotificationTemplateManager`: internal pipeline guards (mostly not surfaced directly)

## Static error payload messages

`ApiResponse` intentionally preserves `errors` payloads exactly as supplied.
That means static English strings inside `respondUnprocessable(errors: [...])`
or similar payloads must be converted directly at the call site, for example
`[__('domain.selected_agency_invalid')]`. Do not restore recursive response
translation to handle these; it would also translate machine values and stored
business content.

Static search on 2026-06-07 found 0 direct English static strings in
representative `respondUnprocessable(errors: [...])` payloads:

```bash
rg -n "respondUnprocessable\([^\n]*errors: \[[^\n]*\['[A-Z][^']*'\]|errors: \[[^\n]*=> \[['\"][A-Z][^'\"]*['\"]\]" app/Http/Controllers app/Application | wc -l
```

This catalogue therefore only tracks remaining dynamic/concatenated messages
that require placeholder refactors.

Progress on 2026-06-07:

- Converted `TillController` static error payloads for duplicate till codes,
  invalid/incompatible ledger accounts, duplicate active teller assignment, and
  open-session reassignment locks.
- Converted `TillReconciliationController` static and dynamic error payloads
  for invalid agency filters, pending teller transactions, currency mismatch,
  non-zero reconciliation difference, missing till linkage, and unsupported
  filter keys.
- Added French regression assertions in `Module5CashInfrastructureTest` for the
  converted cash/till paths.
- Converted `JournalLineController` static error payloads for invalid/non-draft
  journal entries, invalid/cross-scope/inactive ledger accounts, invalid or
  cross-scope customer accounts, single-sided line validation, and draft-only
  line update/delete guards.
- Added French regression assertions in `Module3AccountingArchitectureTest` for
  inactive ledger rejection and non-draft journal-line creation.
- Converted `TellerCashTransactionWorkflow`, `TellerSessionWorkflow`, and
  `TellerManualJournalWorkflow` direct static error payloads for open-session
  guards, till/customer-account/ledger validation, currency mismatches,
  idempotency tender mismatch, max-balance/withdrawal-limit blockers,
  session-close blockers, and unsupported teller-session filters.
- Added French regression assertions in `Module5CashInfrastructureTest` for the
  converted teller-session close, cash deposit limit, cash withdrawal limit,
  idempotency mismatch, and teller-session filter paths.
- Converted `CustomerAccountWorkflow`, `AccountProductController`, and
  `LoanProductController` static error payloads for invalid client/account
  product/ledger/agency selection, inactive ledger accounts, inactive or
  unavailable account products, KYC-gated client opening rules, and custom loan
  amount/term/grace-period range guards.
- Added French regression assertions in `Module3AccountingArchitectureTest`,
  `Module3AccountingProductTest`, and `Module4CreditLoansTest` for inactive
  ledger, inactive account product, loan-product inactive ledger, and custom
  loan range validation paths.
- Converted `LedgerAccountController` and `JournalEntryWorkflow` static error
  payloads for missing agency scope, invalid/self/cross-scope/cyclic parent
  accounts, draft/submitted/posted journal transition guards, and approved
  journal posting balance guards.
- Added French regression assertions in `Module3AccountingArchitectureTest` for
  ledger agency/parent errors, posted-entry update rejection, and invalid
  journal rejection transition errors.
- Converted `AccountHoldController`, `SubSectorController`,
  `EmfRegulatoryAccountController`, `EmfLedgerAccountMappingController`,
  `CustomerAccountSignatureController`, and `OperationAccountMappingController`
  static error payloads for invalid/closed accounts, inactive holds,
  invalid sectors, invalid/inactive EMF or ledger mappings, invalid signature
  documents/proxies/signature states, mapping readiness agency errors,
  inactive operation/ledger selections, overlap conflicts, and
  mapping-agency ledger scope errors.
- Converted remaining direct static loan/collateral error payloads in
  `LoanCrudWorkflow`, `LoanTransferWorkflow`, `LoanSetupChargeWorkflow`,
  `LoanRepaymentWorkflow`, `DelinquencyTrackingWorkflow`,
  `CollateralController`, and `LoanGuaranteeObligationController` for
  application-stage update guards, linked-account closure/client guards,
  transfer manager/state guards, setup-charge exception guards, early repayment
  mutually exclusive direction options, delinquency status gates, collateral
  release gates, and guarantee obligation release/update guards.
- Converted remaining non-Islamic direct static error payloads in reporting,
  accounting-day resolution, batch runs, HR/payroll, FX setup, document scope,
  report runs, and notification filters.
- Converted remaining Islamic finance direct static error payloads in approval
  workflows, Sharia authority setup, product readiness, Salam goods,
  regulatory sign-offs, financing origination, and screening policy setup.

## Recommended approach for the follow-up task

1. Convert each dynamic message to a keyed translation with placeholders, e.g.
   `throw new InvalidArgumentException(__('domain.loan_status_transition', ['from' => $from, 'to' => $to]))`.
2. Add the key to `lang/en/<group>.php` and `lang/fr/<group>.php`.
3. For "unsupported filter keys" style messages, the joined list is a machine
   value — translate only the surrounding sentence via a `:keys` placeholder.
