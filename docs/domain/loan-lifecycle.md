# Loan Lifecycle

This document defines the corrected loan engine model based on stakeholder module 4 and the ER mapping.

## Lifecycle States

Recommended loan states:

- `draft`
- `submitted`
- `under_review`
- `rejected`
- `cancelled`
- `approved`
- `disbursed`
- `active`
- `in_arrears`
- `closed`
- `written_off`

The exact list can be reduced, but transitions must be explicit.

Avoid encoding every approval step as a top-level loan status unless reporting requires it. Prefer a compact lifecycle status plus approval transition records for step-level detail.

## Workflow Steps

Stakeholder steps:

- Montage
- Comptabilité
- Contrôle
- Direction

Implementation rules:

- Each step creates an approval transition record.
- The transition records decision, actor, comments, timestamps, and optional rejection reason.
- Only allowed transitions can be executed.
- Controllers must not mutate workflow state directly.
- A rejection or return must state whether the loan can be resubmitted.
- A later approval step must not run before all previous required steps are approved.

Implemented approval behavior:

- `POST /api/v1/loans/{loan}/approvals/{step}` records `montage`, `comptabilite`, `controle`, and `direction` decisions.
- Supported decisions are `approved`, `rejected`, and `returned`.
- Each step requires `loans.approvals.{step}` unless the actor is platform admin.
- Earlier steps must be approved before later steps can run.
- Non-final approvals move the loan to `in_review`; Direction approval moves it to `approved`.
- Rejection moves the loan to `rejected`; return moves it back to `application`.
- Lifecycle status changes are recorded in `loan_status_transitions`; direct `status` updates through the loan application endpoint are rejected.

Implemented lifecycle transition behavior:

- `POST /api/v1/loans/{loan}/status-transitions` records controlled lifecycle transitions in `loan_status_transitions`.
- The allowed graph covers `application`, `in_review`, `approved`, `disbursed`, `active`, `rescheduled`, `closed`, `written_off`, and `rejected`.
- Invalid transitions are rejected with validation errors.
- This endpoint records lifecycle state only. Money movement, schedule generation, and accounting entries for disbursement, repayment, closure, or write-off must still be performed by their dedicated workflows before calling the transition action.

## Loan Applications

Implemented application behavior:

- `GET /api/v1/loans` lists loan applications inside the caller's permitted agency scope.
- `POST /api/v1/loans` creates an application for an active, KYC-verified client.
- `GET /api/v1/loans/{loan}` returns an application by public ID.
- `PATCH /api/v1/loans/{loan}` updates only application-stage records.
- Creation validates active products, product amount limits, assigned credit agents, same-client linked accounts, sector/sub-sector consistency, and agency scope.
- Loan responses expose public IDs for clients, products, agencies, agents, sectors, and linked accounts.

## Setup Charges

Implemented setup assessment behavior:

- `POST /api/v1/loans/{loan}/setup-charges/assess` assesses setup charges once and returns the existing assessment on repeat calls.
- The action requires the `fees_taxes_insurance` formula policy gate to be approved.
- Dossier fees, setup tax, and guarantee deposit are stored in `loan_charge_assessments`.
- The normal dossier fee rule is 3% of granted principal, assessed at setup approval/credit committee validation, collected separately before disbursement, and non-refundable after setup approval.
- Exceptional dossier-fee cases such as cancellation during setup, waiver, refund, reversal, or recalculation require Direction manual decision. Do not automate these edge cases with formulas.
- V1 loan setup does not create insurance subscriptions, premium assessments, or premium payments. Those belong to the future bancassurance module.
- A configured loan product `insurance_rate` is retained only as a loan-level assurance calculation stored on the loan; it is not a premium workflow and does not create a collection step.
- Product `rules.setup_charges.dossier_fee_rate` configures the dossier fee rate.
- Product `rules.setup_charges.tax_base` configures the setup tax base; the stakeholder-approved default is `principal_plus_interest`, meaning granted principal plus total flat interest. Legacy supported values are `dossier_fee` and `principal`.
- Product `rules.insurance.full_module_enabled` and `rules.insurance.insurance_product_public_id` are not part of the v1 loan workflow. A future bancassurance integration must define its own UI/API contract before those concepts are reintroduced.
- Guarantee deposit is 10% of granted principal by product rate, collected in cash before disbursement by default, held as restricted guarantee money, released only after full settlement, and never used to settle unpaid loans.
- Loan insurance is 2% of granted principal by product rate, assessed upfront, and non-refundable on early closure.
- `POST /api/v1/loans/{loan}/setup-charges/{chargePublicId}/collect` posts non-insurance setup-charge collection before disbursement, either from an active same-client customer account or from an open teller cash session with an active till.
- Setup-charge collection uses active `loan` operation account mappings for `loan_setup_dossier_fee`, `loan_setup_tax`, and `loan_setup_guarantee_deposit`; missing mappings fail closed before posting.
- Teller-cash setup-charge collection creates a linked teller transaction and debits the till cash ledger directly.
- Loan setup-charge collection uses active loan operation account mappings for dossier fee, setup tax, and guarantee deposit. There is no v1 `loan_insurance_premium` posting requirement.
- Full insurance operations expose `POST /api/v1/insurance-partners`, `POST /api/v1/insurance-products`, `POST /api/v1/insurance-subscriptions`, `POST /api/v1/insurance-claims`, and `POST /api/v1/insurance-claims/{claimPublicId}/decision`.
- Insurance claim decisions currently manage the operational claim lifecycle (`pending`, `approved`, `rejected`, `settled`). Claim settlement accounting remains a separate policy decision and is not silently posted.

## Product Rules

Loan products define:

- min/max amount
- min/max duration
- interest rate
- tax/VAT behavior
- fees
- insurance
- guarantee deposit
- penalty formula
- grace period rules
- linked ledger accounts

Implemented product catalog behavior:

- `GET /api/v1/loan-products` lists products with pagination metadata.
- `POST /api/v1/loan-products` creates a product with a stable public ID.
- `GET /api/v1/loan-products/{loanProduct}` returns product details by public ID.
- `PATCH /api/v1/loan-products/{loanProduct}` updates product configuration.
- `DELETE /api/v1/loan-products/{loanProduct}` archives the product instead of deleting it.
- Product responses expose public IDs and ledger account public IDs, not internal integer IDs.

Product formula policies:

- Formula policy keys on loan products are configuration references only; they do not execute calculations by themselves.
- A product cannot be configured with a formula policy key while the matching gate in `config/formulas.php` is unapproved.
- Top-level product policy keys cover interest, fees/tax/insurance/guarantee deposit, penalties, and repayment allocation.
- `rules.formula_policies.rounding_policy_key`, `schedule_policy_key`, and `reporting_policy_key` cover rounding, installment/schedule, and portfolio reporting policy gates.
- Loan approval/setup code must snapshot the approved product policy configuration through `LoanProductFormulaPolicySnapshotter` before generating schedules or posting formula-derived charges.

Rules:

- Validate loan applications against active product rules.
- Snapshot product terms onto the loan at approval/disbursement.
- Later product edits must not change existing loans.
- Product deactivation must prevent new applications but must not break existing active loans.

## Schedule Generation

The loan engine must generate amortization schedules.

Schedule rows include:

- installment number
- due date
- principal
- interest
- tax
- penalty
- total due
- remaining principal
- status

Rules:

- Schedule generation is deterministic and tested.
- Schedule changes require explicit rescheduling actions.
- Repayments allocate against schedule rows by defined allocation order.
- Allocation order must be documented before implementation, for example penalty, tax, interest, principal, or another stakeholder-approved order.
- Rounding behavior must be deterministic and tested.

Implemented schedule behavior:

- `POST /api/v1/loans/{loan}/schedule/generate` creates an active `loan_schedule_snapshots` record with `loan_schedule_lines`.
- Generation requires approved `xaf_rounding`, `loan_interest_method`, `loan_installment_amount`, and `fees_taxes_insurance` policy gates.
- Only approved loans can generate schedules.
- The first implementation uses flat interest on initial/approved principal and equal per-installment components.
- Standard flat-interest schedules do not prorate partial months and do not need day-count calculation.
- Principal and flat interest are split equally across installments. Upfront dossier fee, setup tax, and borrower insurance stay in their setup assessment/payment workflows and are not spread into standard installments unless a product explicitly marks a component as financed or periodic.
- Repeating generation for the same loan and policy snapshot hash returns the existing active schedule instead of duplicating lines.

Required update from the latest stakeholder clarification:

- Loan/account amounts may use exact 2-decimal XAF values even though physical cash deposits are whole-XAF amounts.
- Residual component differences from equal division are carried into the final installment so approved totals reconcile exactly.

## Financial Posting

Loan operations must post ledger entries:

- Approval may not post money.
- Disbursement posts principal movement.
- Fee/insurance/guarantee deposit assessment posts the relevant receivable/income/liability entries.
- Repayment posts cash/account movement and loan balance reduction.
- Normal arrears penalty assessment is operational, not an accounting posting: assessed unpaid penalties update loan arrears/schedule state and are recognized in the ledger only when collected through repayment. Any future accrual-basis penalty recognition must be a separate approved accounting workflow.
- Write-off posts explicit accounting entries and does not delete the loan.

Outstanding balances are derived from schedules, repayments, and ledger entries.

Implemented disbursement behavior:

- `POST /api/v1/loans/{loan}/disburse` posts an approved loan disbursement.
- The implemented channel is `transfer_account`; `cash` is rejected until the Module 5 teller/cash workflow is connected.
- Disbursement debits the loan product ledger account and credits the linked transfer account ledger account.
- The journal entry is posted immediately with `source_module = credit_loans` and `source_type = loan_disbursement`.
- A `loan_disbursements` row links the loan, transfer account, journal entry, posted actor, amount, channel, and idempotency key.
- Repeated disbursement calls for the same loan return the existing disbursement and journal entry; duplicate disbursement rows and duplicate postings are blocked.
- The workflow moves the loan from `approved` to `disbursed` and records `loan_status_transitions.reason = loan_disbursement_posted`.

Implemented repayment allocation behavior:

- `POST /api/v1/loans/{loan}/repayments` posts a repayment from a linked active customer account.
- Repayment requires the `repayment_allocation_order` formula policy gate to be approved.
- The allocation order clears scheduled principal, interest, fees, insurance, and tax before penalties, using oldest due scheduled items first.
- Old penalties do not consume money needed to keep a current scheduled installment current.
- Once allocation reaches penalties, the oldest assessed penalty is collected before newer penalties.
- Original principal remains the flat-interest base; remaining principal reduces only when repayment is allocated to principal.
- `loan_repayments` records the received amount, allocated amount, and retained overpayment.
- `loan_repayment_allocations` records the exact installment component allocations.
- Repayment journal credits are posted by allocation component using active `loan` operation account mappings for `loan_repayment_principal`, `loan_repayment_interest`, `loan_repayment_fees`, `loan_repayment_insurance`, `loan_repayment_tax`, and `loan_repayment_penalty`.
- Missing component credit mappings fail closed before posting so principal, interest, penalties, taxes, fees, and insurance are not collapsed into one ledger account.
- Overpayment is not debited from the customer account; only the allocated amount is posted to the journal entry.
- Example: if the installment due is `833.33 XAF` and the customer physically deposits `850 XAF`, the repayment workflow deducts `833.33 XAF` and leaves `16.67 XAF` on the customer account.
- Arrears use J+5: a due installment becomes late when the full expected scheduled amount is not allocated by 5 days after the due date.
- The unpaid amount for arrears is scheduled due less allocated payment; excess customer-account balance does not reduce arrears until an allocation is posted.
- Grace periods do not rewrite the standard flat-interest schedule: principal is not deferred, interest continues, interest is not capitalized, and penalties are disabled during grace.
- Classic interest capitalization is not part of the normal flat-interest loan lifecycle. Unpaid amounts do not change the original principal or the flat-interest base. Normal "capitalized unpaid amounts" are handled as an arrears carry-forward view derived from open scheduled dues and repayment allocations; no journal entry is posted merely because arrears carried forward. True capitalization is allowed only through a separate credit-committee rescheduling/refinancing workflow.
- The workflow updates `last_repayment_date`, `next_repayment_date`, and `installments_repaid_count` from the active schedule.

All externally callable loan mutations require idempotency keys.

## Early Repayment

Rules:

- Early repayment is allowed after the product minimum period; the stakeholder default is preferably 3 months after disbursement.
- Default payoff collects all remaining scheduled flat interest because Q2 uses flat interest calculated at setup.
- Direction may waive future scheduled interest as an explicit override.
- Direction may also approve a negotiated total interest amount for early settlement. The resulting concession reduces future scheduled interest first; interest already paid is not refunded unless a separate Direction refund approval exists.
- There is no early repayment fee.
- Loan insurance is not refunded on early closure.
- Guarantee obligations and guarantee deposits are released only after full settlement and closure.
- Automatic recovery priority is the loan credit/repayment account first, then other linked same-client accounts by configured priority.
- Multi-account recovery must enforce same-client linkage, agency scope, authorization, insufficient-funds behavior, reversals, and audit logging.

## Collateral

Collateral records should include:

- collateral type
- estimated value
- valuation date
- valuation actor
- guarantor link when applicable
- lifecycle status
- release/sale fields

Use stable ASCII values for enum storage.

Implemented collateral behavior:

- `GET /api/v1/loans/{loan}/collaterals` lists loan collateral records.
- `POST /api/v1/loans/{loan}/collaterals` creates real-estate, movable, or personal-guarantee collateral records.
- `PATCH /api/v1/loans/{loan}/collaterals/{collateral}` updates collateral facts without changing loan identity.
- `POST /api/v1/loans/{loan}/collaterals/{collateral}/release` releases collateral only after the loan is closed.
- `POST /api/v1/loans/{loan}/collaterals/{collateral}/items` creates item-level collateral facts.
- `PATCH /api/v1/loans/{loan}/collaterals/{collateral}/items/{item}` updates item facts.
- Collateral client, loan, document, and item operations are constrained to the same agency.
- No destructive delete route is exposed for collateral items; historical item rows remain available.

Implemented guarantee-obligation behavior:

- `GET /api/v1/loans/{loan}/guarantee-obligations` lists loan-specific guarantor obligations.
- `POST /api/v1/loans/{loan}/guarantee-obligations` creates an obligation linked to an active verified Module 2 client guarantor for the same loan client.
- `PATCH /api/v1/loans/{loan}/guarantee-obligations/{obligation}` updates obligation facts without changing the loan or guarantor identity.
- `POST /api/v1/loans/{loan}/guarantee-obligations/{obligation}/release` releases the obligation only after the loan is closed.
- Each obligation stores a `guarantor_identity_snapshot` at creation time. Later edits to the Module 2 guarantor record do not rewrite historical loan obligation facts.
- Guarantor, loan, document, and obligation records are constrained to the same agency.

## Delinquency Tracking

Delinquency tracking records interactions and promises to pay.

Minimum fields:

- client
- loan
- tracking date
- reason
- appointment type/date
- promised amount
- comments
- staff actor

Rules:

- Promises to pay do not change financial balances.
- Follow-up records are audit/business records, not accounting postings.
- Broken promises should create follow-up state, not silently overwrite previous promises.

## Rescheduling And Refinancing

Do not mutate original schedule history in place.

Rules:

- Rescheduling creates a new version or explicit adjustment record.
- Prior schedule rows remain auditable.
- Refinancing is a separate business operation with its own approvals and ledger postings.

Implemented rescheduling behavior:

- `POST /api/v1/loans/{loan}/schedule/reschedule` keeps the same loan public ID.
- The active schedule snapshot is marked `superseded`; its lines remain unchanged.
- A new active schedule snapshot and lines are generated from the updated dates/installment count.
- Rescheduling records a lifecycle transition to `rescheduled` when the loan was previously active.
- Standard rescheduling preserves the approved flat-interest logic unless a credit-committee workflow explicitly approves different terms.
- Requests that attempt to capitalize interest or penalties are rejected until a dedicated approved credit-committee and accounting workflow exists.
