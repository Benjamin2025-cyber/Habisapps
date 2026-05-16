# Stakeholder Formula Responses

This document extracts the formula-related responses from:

- `docs/domain/stakeholder-formula-questions.md`
- `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`

It intentionally covers only sections 1-25 from the original formula questionnaire. Later stakeholder additions are not evaluated here.

Items marked `A preciser`, `A definir`, or similar are explained in `docs/domain/stakeholder-formula-items-to-explain.md` for stakeholder follow-up sessions.

Status meanings:

- `usable`: clear enough to shape implementation after config approval and tests.
- `needs internal decision`: stakeholder direction exists, but implementation must choose/configure the exact system representation without sending the same question back.
- `needs reconciliation`: the response contains a direct conflict or an explicit `a preciser` / `a definir` marker.

Formula gates in `config/formulas.php` must stay closed until a section is converted into an exact implementation rule with owner/date approval.

For implementation decisions in this project, "approval" means the builder-maintained rule is clear, finance-aligned, and testable. It does not require a separate external sign-off when the stakeholder answer already resolves the business rule.

## New Stakeholder Round Of Talk

### Question 1: Physical XAF Deposits Versus Exact Loan Deductions

Stakeholder clarification:

- Customers physically deposit whole XAF amounts because fractional XAF cash is not practical.
- The customer account can retain the deposited excess.
- The loan engine debits the exact amount due from the customer account, even when the exact installment contains decimals.
- Example: if the expected installment is `833.33 XAF`, the customer may physically deposit `850 XAF`; the loan deduction remains `833.33 XAF`, and `16.67 XAF` stays on the customer account.
- The last installment applies the remaining-balance logic so the approved total interest is fully collected. In the example, total interest still finishes at `10,000 XAF`.

Implementation reading:

- Do not round loan debt to match physical cash denominations.
- Treat physical cash deposit precision and loan/account ledger precision as separate concerns.
- Cash/teller deposit entry should reject fractional physical XAF cash amounts unless a non-cash channel explicitly allows decimals.
- Loan schedules and repayments may carry 2-decimal XAF values.
- Residual schedule differences caused by decimal division should be absorbed by the final installment component so totals reconcile exactly.
- Repayment overpayment is not loan revenue, not lost money, and not automatic prepayment. It remains on the customer account unless another authorized workflow later uses it.
- This rule is approved for implementation as of 2026-05-14.

Ambiguities fixed by this clarification:

1. Physical XAF deposits are whole-cash amounts, not decimal cash payments.
2. Loan/account deductions may still use exact 2-decimal amounts.
3. Final installment components should reconcile residual formula differences to the approved total.
4. Excess deposited cash remains as customer-account balance, not as loan overpayment revenue.

## Cross-Impact Of Approved Questions 1 And 2

Question 1 and Question 2 are foundational implementation rules. They constrain later sections even when those later sections still need their own workflow or accounting decisions.

Question 1, physical cash versus exact account/loan deductions, affects:

- Section 4, installment amount: final installment components absorb residual decimal differences.
- Section 6, dossier fees: physical cash collection is whole-XAF, while receivable/accounting values may use 2 decimals.
- Section 7, VAT/tax: tax values may use exact 2-decimal account/ledger precision.
- Section 8, insurance: premium values may use exact 2-decimal account/ledger precision.
- Section 9, guarantee deposit: physical cash collection is whole-XAF; ledger values can remain exact.
- Section 10, penalties: penalty calculations use exact account/ledger precision, not cash-denomination rounding.
- Section 11, arrears/unpaid amount: unpaid amount is exact scheduled due minus exact allocated payment.
- Section 12, repayment allocation: excess deposited cash remains on the customer account.
- Section 15, early repayment: payoff is exact; excess account balance is not automatic loan revenue or prepayment.
- Sections 20-22, cash/till: physical cash counting remains denomination based.
- Section 25, collection performance: recognized collection is the exact allocated amount, not the full physical deposit.

Question 2, flat interest on initial principal, affects:

- Section 3, day-count convention: ordinary loan interest is fixed at setup; day-count matters mainly for exceptional value-date, early closure, or rescheduling behavior.
- Section 4, installment amount: scheduled interest is total flat interest divided by duration.
- Section 5, principal and interest split: original principal remains the interest base; remaining principal tracks repayment progress.
- Section 7, VAT/tax: interest in the tax base is total scheduled flat interest.
- Section 13, grace period: interest is static unless a specific approved exception changes it.
- Section 14, capitalized unpaid amounts: unpaid amounts do not change the original flat-interest base.
- Section 15, early repayment: future interest means remaining scheduled flat interest unless Direction waiver is recorded.
- Section 16, rescheduling/refinancing: preserve original flat-interest logic unless rescheduling explicitly creates an approved new schedule.
- Section 23, portfolio outstanding: interest exposure comes from scheduled/due flat interest.
- Section 25, collection performance: expected interest is scheduled flat interest.

## Response Register

| # | Area | Stakeholder response | Status | Implementation notes |
|---|---|---|---|---|
| 1 | XAF precision and rounding | Customer-facing accounting/loan amounts use 2 decimal places. Physical XAF cash deposits are whole-cash amounts. Internal decimals may be retained. The system does not round loan debt to match cash denominations. | usable | Implement XAF monetary values with 2 decimal places for account/loan display and API. Cash deposit channels should reject fractional physical XAF cash. Keep loan/account deductions exact to the approved formula amount. |
| 2 | Loan interest method | Flat interest on initial principal. Formula given as `initial principal * interest rate / duration`. | usable | Approved for implementation. Interpret `interest_rate` as the total flat percentage for the loan term: total interest is `initial principal * interest_rate / 100`, then scheduled interest per installment is `total interest / duration`. The rate is product configuration, not a stakeholder ambiguity. |
| 3 | Day-count convention | 360-day convention. Leap-year rule: none. For the chosen flat-interest method, partial months do not prorate standard scheduled interest. Also mentions a value date 5 days after operation date. | usable | Approved for ordinary flat-interest microfinance schedules. Standard practice for this product type is to calculate interest at setup on initial principal, divide it across the agreed duration, and not recalculate ordinary installments by actual days. Use the 360-day convention only where an explicit day-based calculation is later introduced. Value-date, early-closure waiver, and rescheduling rules are workflow/product exceptions, not blockers for the normal schedule. |
| 4 | Installment amount | Equal amount every period. Included components stated as `(capital * interest rate) / duration + taxes`. New clarification says a customer may deposit a whole XAF amount such as `850`, while the system deducts the exact due amount such as `833.33`; the last installment applies the remaining-balance logic so the approved total interest is fully collected. | approved | Standard schedules use equal installments composed of scheduled principal, scheduled flat interest, and configured taxes/fees/insurance where product policy includes them. Regular installment amounts are as equal as possible at 2-decimal precision. The final installment component absorbs residual differences so principal, interest, and other component totals reconcile exactly. |
| 5 | Principal and interest split | Capital remaining reduces after payment. Initial principal is not reduced. Partial payment prioritizes capital, then interest. Interest calculated at loan setup. | approved | Store original principal separately from remaining principal. Original principal remains the flat-interest base. Remaining principal reduces only when a repayment allocation is posted to principal. Partial payments allocate to principal before interest, following oldest installment first from section 12. |
| 6 | Dossier/application fees | Fee is 3% of granted/initial capital. Trigger stated as disbursement in responses, but decision field says credit committee validation. Paid separately in cash. Non-refundable after setup stage. Accounting automated at setup. NOTE: exceptional setup cases require Direction manual decision. | approved | Assess dossier fee at credit committee validation/setup approval on granted principal, collect it separately before disbursement, and treat it as non-refundable after setup approval. This matches standard microfinance practice: the fee is earned when the loan is approved, not when cash moves. The disbursement wording is collection/control timing, not the calculation trigger. Physical cash collection is whole-XAF, while ledger/receivable values may use 2 decimals. Do not automate exceptional setup cases with formulas; route waiver, refund, reversal, or recalculation to Direction manual decision. |
| 7 | VAT/tax | Tax rate 19.25%. Tax base: capital + interest. Calculated upfront. No rounding. Accounting automated at setup. | approved | Implement the stated setup tax rule as 19.25% of granted principal plus total flat interest from section 2. Use exact 2-decimal account/ledger precision; do not round to cash denominations. |
| 8 | Insurance | Fixed percentage. Base is granted amount. Formula: granted amount * 2%. Paid upfront. Non-refundable on early closure. Accounting automated at setup. | approved | Implement borrower loan insurance as 2% of granted principal, assessed upfront at setup, non-refundable on early closure, and posted through the insurance module when full insurance integration is enabled. Use exact 2-decimal account/ledger precision. |
| 9 | Guarantee deposit | Fixed 10% of granted amount. Can be paid in cash or deducted, but decision field says cash. Released at closure. Cannot settle unpaid loans, yet use/offset field says "at the last installment." Accounting automatic. | approved | Implement 10% of granted principal, collected in cash before disbursement by default, held as restricted guarantee money, and released only after full loan settlement/closure. Treat "at the last installment" as release timing, not as offset permission, because the response explicitly says the guarantee cannot settle unpaid loans. Physical cash collection is whole-XAF; ledger values may use 2 decimals. |
| 10 | Penalty formula | Trigger around day 26/month-end close after 5 grace days. Formula: `5,000 + 2% of unpaid amount`. Monthly. NOTE: the system must not penalize unpaid amounts below 1,000 XAF. No rounding. Capitalization rule still unspecified. | approved | Implement monthly arrears penalty as `5,000 + 2% unpaid scheduled due`, after 5 grace days, with no penalty when the unpaid amount is below 1,000 XAF. Unpaid penalties remain owed and collectible, but prior penalties do not themselves generate new penalties. Repayments clear scheduled principal, interest, fees, insurance, and tax before penalties; once collecting penalties, oldest assessed penalty is collected before newer penalties. If only prior penalties are unpaid while scheduled principal/interest due is current, collect those penalties without assessing a new monthly penalty. Capitalized unpaid amounts are handled separately in section 14 and do not block this penalty rule. |
| 11 | Arrears/unpaid amount | Late when full expected amount is not paid by due date. Late rule J+5. Partial payments are free payments with no classification. Total unpaid = due installment - amount paid. | approved | Use J+5 as the late boundary. For each due installment, unpaid amount is exact scheduled due, excluding prior penalties for penalty-base purposes, less exact allocated payment. Partial payments are allocated through the standard allocation order and do not need a separate classification. Excess physical deposit that remains in the account does not reduce arrears until allocated to the loan. |
| 12 | Repayment allocation order | Pay capital first. Oldest installments first. Same-day payments use same order. Overpayment remains in customer account. New clarification confirms that if the customer deposits more than the exact installment, the system deducts only the due amount and leaves the excess on the account. | approved | Clear scheduled principal, interest, fees, insurance, and tax before penalties, using oldest due scheduled items first. Within scheduled dues, principal is first. Once collecting penalties, oldest assessed penalty is collected before newer penalties. Same-day payments use the same deterministic order. Overpayments stay on the customer account and are not treated as loan revenue, lost money, or automatic prepayment. |
| 13 | Grace period | Principal not deferred. Interest continues and is paid during grace. Interest is not capitalized. Penalties disabled. Schedule impact: none. | approved | The standard flat-interest schedule remains fixed during grace: principal is not deferred, scheduled flat interest continues, interest is not capitalized, and penalties are disabled during the grace period. Grace changes penalty eligibility, not the ordinary schedule formula. |
| 14 | Capitalized interest | NOTE: because capital and interest are fixed, stakeholders propose renaming this concept to "capitalized unpaid amounts"; if an installment from month X remains unpaid in month Y, the unpaid amount is updated and penalties apply to it. Trigger, formula, future interest base, penalty treatment, and accounting treatment are all marked `a preciser`. | approved as no automatic capitalization | Do not implement classic interest capitalization for the normal flat-interest loan. Under the approved flat-interest method, unpaid amounts do not change original principal or the flat-interest base, and prior penalties do not generate new penalties. Handle this as an arrears carry-forward view: open scheduled dues remain open into the next period, penalties are assessed by the approved Q10 rule on unpaid scheduled due, and no journal entry is posted merely because arrears carried forward. Capitalization of unpaid amounts is allowed only inside a separate credit-committee rescheduling/refinancing workflow. |
| 15 | Early repayment | Allowed, preferably 3 months after disbursement. Future interest is not waived, but may be negotiated by Direction. No early repayment fee. Insurance not refunded. Guarantee released when full sums due are settled. Accounting automated. NOTE: automate all recoveries, debit the credit account first, then any other client account if incomplete; all clients must be linked to their accounts, with client identification proposed by code. | approved | Default payoff includes all remaining scheduled flat interest, no early fee, no insurance refund, and guarantee release only after full settlement/closure. Direction may approve either a full future-interest waiver or a negotiated total interest amount for early settlement. A negotiated concession reduces future scheduled interest first; interest already paid is not refunded without separate Direction approval. Recovery priority is credit/repayment account first, then other linked same-client accounts by configured priority. Exact payoff may be deducted from accounts; excess account balance remains with the customer. |
| 16 | Rescheduling/refinancing | Rescheduling allowed. Same loan is modified. Interest and penalties are capitalized. Credit committee approval required. Accounting automated. | approved with capitalization guard | Keep the same loan identity while preserving old schedule snapshots and creating a new active schedule. Standard rescheduling preserves the approved flat-interest logic unless a credit-committee workflow explicitly approves different terms. Capitalizing interest or penalties is not automatic; it is allowed only through a dedicated credit-committee and accounting workflow that posts the required entries. |
| 17 | Accounting balance | Balance comes from entered and validated ledger entries. Pending transactions need definition. Reversals shown only on internal processing interface. NOTE: account types need clarification, including recovery accounts and ordinary savings accounts. Formula/status/reversal fields are marked `a definir`. | approved | Accounting balance uses posted/validated journal entries only. Debit-normal accounts use `debit total - credit total`; credit-normal accounts use `credit total - debit total`. Draft, pending, rejected, and cancelled operations do not affect accounting balance. Reversals do not delete original entries; a posted reversal entry creates the opposite accounting effect, while the original remains visible internally. |
| 18 | Available balance | Savings minimum balance 5,000 XAF, subject to Direction policy. Current accounts minimum 0. Loan restrictions reduce availability. Formula given: accounting balance - account minimum. Pending withdrawals still to clarify. | approved | Available balance is `accounting balance - account/product minimum balance - unavailable amount - active holds`. Ordinary savings default minimum is 5,000 XAF; current accounts default to 0. Loan restrictions, pending withdrawals, and other reservations reduce available balance when represented as active holds or unavailable amounts. They do not affect accounting balance until posted. |
| 19 | Daily and cumulative movements | Transaction date vs posting date must be contextualized. Reversals shown internally. Day close is performed by batch. Formulas remain contextualized/undefined. | approved | Accounting movement reports use posting/business date because that is when the ledger changes. Operational movement reports may use transaction date when a workflow captures it. Daily movement is the signed posted movement for the selected business date; cumulative movement is the opening balance plus signed posted movements through the selected period. Day close uses the batch business date as the reporting boundary. |
| 20 | Bank notes and coins / billetage | All denominations accepted. Coins tracked. Damaged cash accepted and not tracked separately. Denominations not deactivatable. Counts required at opening and closing. NOTE: configure the till-closing interface. | approved | Denomination values are configurable reference data. The denomination line total is `denomination value * count`. Coins are tracked. Damaged cash is accepted but not tracked as a separate cash category. Opening and closing counts are mandatory. The till-closing interface note is workflow/UI scope, but it is not a formula blocker. |
| 21 | Till theoretical balance | Only recorded transactions included. Pending transactions shown on close state. Opening balance = initial balance + inflows - outflows, with opening J equal to closing J-1. Theoretical balance = opening + deposits - withdrawals. Inter-till transfer during supplies. | approved | Theoretical cash balance uses posted/recorded cash movements only: `opening balance + posted cash inflows - posted cash outflows`. Pending teller transactions are displayed on the close state but are not included in theoretical balance until posted. Opening J comes from closing J-1, or from the initial opening declaration for the first session. |
| 22 | Till reconciliation difference | Zero tolerance. No shortage/overage approval because there should be no differences. Closure with unresolved difference is blocked, except differences linked to pending transactions. | approved | Reconciliation difference is `counted cash - theoretical balance`. Posted cash differences have zero tolerance and block close until corrected. Pending-transaction differences are displayed separately; close should be blocked until those pending operations are posted, cancelled, or explicitly carried forward by a supervisor workflow. |
| 23 | Portfolio outstanding | Composition: capital + interest + penalties. Written-off loans excluded. Rescheduled loans stay in original portfolio. Formula field says sum of debts and unpaid repayments, still to clarify by portfolio. | approved | Portfolio outstanding is remaining principal plus scheduled/due flat interest plus assessed unpaid penalties for active non-written-off loans. Written-off loans are excluded from portfolio outstanding and reported separately. Rescheduled loans keep their original portfolio identity, while restructured/rescheduled status is reported as a separate dimension. |
| 24 | Portfolio at risk / delinquency | PAR30 bucket. Base: overdue amount, not full outstanding principal. Written-off and rescheduled loans excluded. | approved with metric split | Standard microfinance PAR30 is not the overdue amount only; it is the outstanding balance of loans with at least one installment more than 30 days past due, divided by the gross outstanding portfolio, excluding written-off loans. The stakeholder's overdue-amount base should be implemented as a separate delinquent-amount/arrears metric. Formally rescheduled loans that are current may be excluded from main PAR30 but must remain visible in a restructured watchlist; delinquent rescheduled loans must not disappear from risk reporting. |
| 25 | Collection performance | Penalties included in expected collection. No fees. Partial payments counted immediately. Cash and account debits included. Expected formula stated as scheduled capital + scheduled interest. Actual formula stated as expected collection - recognized collection. | approved with metric split | Use the explicit inclusion answers as authoritative. Expected collection is scheduled principal due plus scheduled flat interest due plus assessed penalties due; fees are excluded. Recognized collection is the exact amount allocated from cash and account debits to principal, interest, and penalties; unallocated customer deposits are not collection. Partial payments count immediately when allocated. Collection performance rate is `recognized collection / expected collection * 100`. The stakeholder's `expected collection - recognized collection` formula is the collection shortfall/gap, not the actual collection amount. |

## Implementation-Ready Summary

Usable after normal config approval and tests:

- Section 1: 2-decimal loan/account precision, whole-XAF physical cash deposits, and no rounding of loan debt to match cash denominations.
- Section 2: flat interest on initial principal.
- Section 4: equal installment behavior with final residual adjustment so approved totals reconcile exactly.
- Section 5: original principal versus remaining principal split.
- Section 7: upfront 19.25% tax on capital + interest.
- Section 8: insurance formula.
- Section 11: arrears/unpaid amount formula.
- Section 12: repayment allocation direction.
- Section 13: grace period behavior.
- Section 15: early repayment default behavior and recovery priority.
- Section 16: rescheduling/refinancing direction.
- Section 20: denomination count rules.
- Section 21: till theoretical balance.
- Section 22: till reconciliation difference.
- Section 23: portfolio outstanding.
- Section 24: PAR30 overdue-amount basis.

Needs internal reconciliation before opening formula gates:

- Section 14: capitalized interest/unpaid amounts.
- Section 17: accounting balance formula.
- Section 19: daily and cumulative movement formulas.

Everything else has a stakeholder direction but needs internal reconciliation because the response itself contains `a preciser`, `a definir`, or conflicting normalized fields.

## Stakeholder NOTE Items

These `NOTE` items are part of the stakeholder response and must not be discarded. They should be carried into implementation tickets or internal reconciliation work, even when they are not formulas by themselves.

| Section | Stakeholder note | Treatment |
|---|---|---|
| 6. Dossier/application fees | Exceptional setup cases require Direction manual decision. | Normal fee assessment is approved. Exceptional waiver, refund, reversal, or recalculation must go through Direction manual decision; do not automate every edge case. |
| 10. Penalty formula | The system must not penalize amounts below 1,000 XAF. | Implement as a hard penalty eligibility threshold: if unpaid scheduled due is below 1,000 XAF, no penalty is assessed. |
| 14. Capitalized interest | Stakeholders propose treating the concept as "capitalized unpaid amounts"; unpaid installments are updated in later months and penalties apply to that updated unpaid amount. | Explain this as arrears carry-forward, not classic interest capitalization. Original principal and flat-interest base do not change; normal carry-forward is calculated from open scheduled dues and does not post a journal entry. |
| 15. Early repayment | Automate all recoveries: debit the credit account first; if incomplete, debit other client accounts. Clients must be linked to all their accounts. Client identification by code is proposed. | Approved as the recovery priority: credit/repayment account first, then other linked same-client accounts by configured priority. Implementation must enforce same-client linkage, agency scope, authorization, insufficient-funds behavior, reversals, and audit logging. |
| 17. Accounting balance | Clarify account types, including recovery accounts and ordinary savings accounts. | Add account taxonomy work before finalizing balance and availability formulas. |
| 20. Billetage | Configure the till-closing interface. | Carry into teller-session/till-closing workflow scope; not a denomination formula, but still a required stakeholder expectation. |

## Internal Reconciliation Items

These items should be resolved by implementation policy, product configuration, or existing architecture before going back to stakeholders.

| Section | Reconciliation item | Likely implementation interpretation |
|---|---|---|
| 3. Day-count convention | Value date is introduced as operation date + 5 days. Partial-month language is treated as an exception-workflow note, not a standard flat-interest schedule blocker. | Standard flat-interest schedules do not prorate partial months. Use 360-day convention only for explicit day-based calculations if introduced later. Add value-date offset as configurable posting metadata. Resolve early-closure waiver, rescheduling, or posting-effective-date behavior inside those workflows, not as a blocker for the normal schedule. |
| 6. Dossier/application fees | Response text says disbursement; normalized field says credit committee validation. | Resolved: assess at credit committee validation/setup approval; collect separately before disbursement. Exceptional setup cases go to Direction manual decision. |
| 9. Guarantee deposit | Response allows cash or deduction; normalized field says cash. Offset field says last installment, while response says it cannot settle unpaid loans. | Resolved: use cash collection before disbursement as the default rule. Treat "last installment" as release timing after full settlement, not as debt offset. |
| 10. Penalty formula | Capitalization remains `a preciser`; PAR/CTX after 90 days is described as cap context. | Resolved for normal penalties: apply monthly penalty to unpaid scheduled due excluding prior penalties. Keep capitalized unpaid amounts and CTX transition handling in their separate workflows. |
| 14. Capitalized unpaid amounts | The note provides business direction, but all formula fields remain `a preciser`. | Resolved as normal arrears carry-forward: open scheduled dues remain open, original principal and flat-interest base do not change, prior penalties do not generate new penalties, and no accounting posting is made just because arrears carried forward. Any true capitalization belongs only to a separate credit-committee rescheduling/refinancing workflow. |
| 17. Accounting balance | Balance formula/statuses are `a definir`; pending transactions are undefined. | Resolved by accounting standard: posted/validated entries only. Pending operations do not affect accounting balance. Reversals affect balance through posted reversal entries. |
| 18. Available balance | Pending withdrawals remain `a preciser`; formula omits loan restrictions while text says they reduce availability. | Resolved by funds-availability standard: pending withdrawals and loan restrictions reduce available balance when recorded as active holds or unavailable amounts. They do not affect accounting balance until posted. |
| 19. Daily/cumulative movements | Transaction date versus posting date is contextual. | Resolved by report type: accounting reports use posting/business date; operational reports may use transaction date. Batch business date is the day-close boundary. |
| 25. Collection performance | Inclusion answers include penalties, but formula line omits penalties; actual formula looks like uncollected gap. | Expected collection = scheduled capital + scheduled interest + scheduled penalties. Actual collection = recognized repayments from cash and account debits. Gap = expected - actual; performance = actual / expected. |
