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

## Response Register

| # | Area | Stakeholder response | Status | Implementation notes |
|---|---|---|---|---|
| 1 | XAF precision and rounding | Customer-facing amounts use 2 decimal places. Internal decimals may be retained. No rounding mode, no rounding timing, no final-installment adjustment. | usable | Implement XAF monetary values with 2 decimal places for display/API and enough internal decimal precision to avoid formula loss before persistence. Do not round installment lines or final installment differences unless a later policy changes this. |
| 2 | Loan interest method | Flat interest on initial principal. Formula given as `initial principal * interest rate / duration`. | usable | The chosen method is clear. Treat the interest rate period as a configurable product attribute instead of a blocker on the stakeholder's method response. |
| 3 | Day-count convention | 360-day convention. Leap-year rule: none. Partial-month rule: still to contextualize. Also mentions a value date 5 days after operation date. | needs reconciliation | Use 360-day convention where a day-count engine is needed. The response itself leaves partial-month behavior to contextualize and introduces value-date behavior, so those should be modeled as configurable rules rather than re-asking the base day-count question. |
| 4 | Installment amount | Equal amount every period. Included components stated as `(capital * interest rate) / duration + taxes`. First/last installment fixed. No rounding differences. | usable | Interpret the response with section 2: flat interest is computed from initial capital, then spread across the duration with taxes. The repayment schedule should keep equal periods and no first/last rounding adjustment. |
| 5 | Principal and interest split | Capital remaining reduces after payment. Initial principal is not reduced. Partial payment prioritizes capital, then interest. Interest calculated at loan setup. | usable | Store original principal separately from remaining principal. For flat-interest reporting, original principal remains the interest base while remaining principal reduces after payments. |
| 6 | Dossier/application fees | Fee is 3% of granted/initial capital. Trigger stated as disbursement in responses, but decision field says credit committee validation. Paid separately in cash. Non-refundable after setup stage. Accounting automated at setup. NOTE: exceptional cases during setup remain to be reviewed. | needs reconciliation | Prefer the decision-field trigger, credit committee validation, because it is the normalized field. Treat the disbursement wording as collection timing unless product confirms otherwise. Carry the NOTE as an exception-policy backlog item. |
| 7 | VAT/tax | Tax rate 19.25%. Tax base: capital + interest. Calculated upfront. No rounding. Accounting automated at setup. | usable | Implement the stated tax base as capital plus interest. Legal/accounting review may still be prudent, but the stakeholder formula response is explicit. |
| 8 | Insurance | Fixed percentage. Base is granted amount. Formula: granted amount * 2%. Paid upfront. Non-refundable on early closure. Accounting automated at setup. | usable | Ledger accounts and collection timing are implementation configuration; the formula direction is clear. |
| 9 | Guarantee deposit | Fixed 10% of granted amount. Can be paid in cash or deducted, but decision field says cash. Released at closure. Cannot settle unpaid loans, yet use/offset field says "at the last installment." Accounting automatic. | needs reconciliation | Formula and base are clear. Collection/use has conflicting wording: implement cash as the default collection method from the decision field, and treat deduction/last-installment use as product options requiring internal configuration policy. |
| 10 | Penalty formula | Trigger around day 26/month-end close after 5 grace days. Formula: `5,000 + 2% of unpaid amount`. Monthly. NOTE: the system must not penalize unpaid amounts below 1,000 XAF. No rounding. Capitalization rule still unspecified. | needs reconciliation | Implement monthly penalty as `5,000 + 2% unpaid` after 5 grace days, with no penalty below 1,000 XAF unpaid. The response explicitly leaves capitalization unspecified and links the cap to PAR/CTX handling after 90 days. |
| 11 | Arrears/unpaid amount | Late when full expected amount is not paid by due date. Late rule J+5. Partial payments are free payments with no classification. Total unpaid = due installment - amount paid. | usable | Use J+5 as late boundary. For each due installment, unpaid amount is due installment less amount paid. Partial payments reduce the unpaid amount immediately without a special classification. |
| 12 | Repayment allocation order | Pay capital first. Oldest installments first. Same-day payments use same order. Overpayment remains in customer account. | usable | Allocation starts with capital and then follows the same repayment component order consistently for remaining components. Overpayments stay on the customer account. |
| 13 | Grace period | Principal not deferred. Interest continues and is paid during grace. Interest is not capitalized. Penalties disabled. Schedule impact: none. | usable | "Capital static" and "interest static" should be translated into exact schedule behavior, but the direction is coherent. |
| 14 | Capitalized interest | NOTE: because capital and interest are fixed, stakeholders propose renaming this concept to "capitalized unpaid amounts"; if an installment from month X remains unpaid in month Y, the unpaid amount is updated and penalties apply to it. Trigger, formula, future interest base, penalty treatment, and accounting treatment are all marked `a preciser`. | needs reconciliation | The NOTE is the main stakeholder input for this section. Treat this as an arrears/penalty update concept, not classic interest capitalization. Exact update timing, updated unpaid amount formula, penalty base, and ledger treatment belong in the internal formula design. |
| 15 | Early repayment | Allowed, preferably 3 months after disbursement. Future interest is not waived, but may be negotiated by Direction. No early repayment fee. Insurance not refunded. Guarantee released when full sums due are settled. Accounting automated. NOTE: automate all recoveries, debit the credit account first, then any other client account if incomplete; all clients must be linked to their accounts, with client identification proposed by code. | usable | Default payoff includes future interest, no early fee, no insurance refund, and guarantee release only after full settlement. Direction negotiation is an override workflow. The recovery NOTE defines account debit priority: credit account first, then other linked client accounts. |
| 16 | Rescheduling/refinancing | Rescheduling allowed. Same loan is modified. Interest and penalties are capitalized. Credit committee approval required. Accounting automated. | usable | Keep the same loan identity while preserving audit history through schedule/version records. Capitalize interest and penalties through an approved credit committee workflow. |
| 17 | Accounting balance | Balance comes from entered and validated ledger entries. Pending transactions need definition. Reversals shown only on internal processing interface. NOTE: account types need clarification, including recovery accounts and ordinary savings accounts. Formula/status/reversal fields are marked `a definir`. | needs reconciliation | Direction supports ledger-derived balance. Use posted/validated ledger entries as the default accounting-balance basis while transaction statuses and account taxonomy are finalized internally. |
| 18 | Available balance | Savings minimum balance 5,000 XAF, subject to Direction policy. Current accounts minimum 0. Loan restrictions reduce availability. Formula given: accounting balance - account minimum. Pending withdrawals still to clarify. | needs reconciliation | Base formula is accounting balance less minimum balance, with account-type minimums. Loan restrictions also reduce availability. Pending withdrawals are explicitly left to clarify. |
| 19 | Daily and cumulative movements | Transaction date vs posting date must be contextualized. Reversals shown internally. Day close is performed by batch. Formulas remain contextualized/undefined. | needs reconciliation | Use batch day-close as the reporting boundary. Default accounting reports to posting date and operational activity reports to transaction date unless a report explicitly chooses otherwise. |
| 20 | Bank notes and coins / billetage | All denominations accepted. Coins tracked. Damaged cash accepted and not tracked separately. Denominations not deactivatable. Counts required at opening and closing. NOTE: configure the till-closing interface. | usable | Denomination catalog values are reference data. Formula `denomination value * quantity` is straightforward. The till-closing interface note is workflow/UI scope tied to teller-session implementation, not a formula blocker. |
| 21 | Till theoretical balance | Only recorded transactions included. Pending transactions shown on close state. Opening balance = initial balance + inflows - outflows, with opening J equal to closing J-1. Theoretical balance = opening + deposits - withdrawals. Inter-till transfer during supplies. | usable | Use recorded/posted cash movements for theoretical balance. Pending transactions are displayed on the close state, not included in the posted theoretical balance. |
| 22 | Till reconciliation difference | Zero tolerance. No shortage/overage approval because there should be no differences. Closure with unresolved difference is blocked, except differences linked to pending transactions. | usable | Difference is counted cash less theoretical balance. Enforce zero tolerance for posted cash differences; pending-transaction differences are surfaced separately on the close state. |
| 23 | Portfolio outstanding | Composition: capital + interest + penalties. Written-off loans excluded. Rescheduled loans stay in original portfolio. Formula field says sum of debts and unpaid repayments, still to clarify by portfolio. | usable | Use capital + interest + penalties for non-written-off loans. Keep rescheduled loans grouped under the original portfolio. Treat the status list as reporting group labels, while written-off loans remain excluded from outstanding exposure. |
| 24 | Portfolio at risk / delinquency | PAR30 bucket. Base: overdue amount, not full outstanding principal. Written-off and rescheduled loans excluded. | usable | PAR uses a 30-day bucket and overdue amount as the calculation base. |
| 25 | Collection performance | Penalties included in expected collection. No fees. Partial payments counted immediately. Cash and account debits included. Expected formula stated as scheduled capital + scheduled interest. Actual formula stated as expected collection - recognized collection. | needs reconciliation | Use the explicit inclusion answers as authoritative: expected collection includes capital, interest, and penalties; fees excluded; actual collection includes immediate partial payments by cash and account debit. The written actual formula likely labels the uncollected gap, not performance ratio. |

## Implementation-Ready Summary

Usable after normal config approval and tests:

- Section 1: 2-decimal XAF precision with no rounding.
- Section 2: flat interest on initial principal.
- Section 4: equal installment behavior.
- Section 5: original principal versus remaining principal split.
- Section 7: upfront 19.25% tax on capital + interest.
- Section 8: insurance formula.
- Section 11: arrears/unpaid amount formula.
- Section 12: repayment allocation direction, pending full component order.
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
| 6. Dossier/application fees | Exceptional cases remain to be reviewed, especially around setup-stage handling. | Add an exception-policy task for refund/waiver/reversal/manual approval handling before implementing fee collection. |
| 10. Penalty formula | The system must not penalize amounts below 1,000 XAF. | Implement as a hard penalty eligibility threshold once the penalty formula is approved. |
| 14. Capitalized interest | Stakeholders propose treating the concept as "capitalized unpaid amounts"; unpaid installments are updated in later months and penalties apply to that updated unpaid amount. | Keep the formula gate closed until the update formula, trigger, penalty base, and accounting treatment are specified. |
| 15. Early repayment | Automate all recoveries: debit the credit account first; if incomplete, debit other client accounts. Clients must be linked to all their accounts. Client identification by code is proposed. | Treat as mandatory recovery workflow input, but do not implement without authorization, consent, priority, failure, reversal, and audit rules. |
| 17. Accounting balance | Clarify account types, including recovery accounts and ordinary savings accounts. | Add account taxonomy work before finalizing balance and availability formulas. |
| 20. Billetage | Configure the till-closing interface. | Carry into teller-session/till-closing workflow scope; not a denomination formula, but still a required stakeholder expectation. |

## Internal Reconciliation Items

These items should be resolved by implementation policy, product configuration, or existing architecture before going back to stakeholders.

| Section | Reconciliation item | Likely implementation interpretation |
|---|---|---|
| 3. Day-count convention | Partial months are marked as contextual. Value date is introduced as operation date + 5 days. | Use 360-day convention as default. Add value-date offset as configurable posting metadata. Resolve partial-month behavior per loan product if needed. |
| 6. Dossier/application fees | Response text says disbursement; normalized field says credit committee validation. | Use credit committee validation as assessment trigger; collect/pay in cash before or at setup/disbursement according to workflow. |
| 9. Guarantee deposit | Response allows cash or deduction; normalized field says cash. Offset field says last installment, while response says it cannot settle unpaid loans. | Use cash collection as default. Model deduction/last-installment handling as optional product configuration, but never use the deposit for unpaid-loan settlement unless a later policy changes that. |
| 10. Penalty formula | Capitalization remains `a preciser`; PAR/CTX after 90 days is described as cap context. | Apply monthly penalty until CTX transition policy runs. Keep penalty capitalization disabled unless the capitalized-unpaid-amount rule is later approved. |
| 14. Capitalized unpaid amounts | The note provides business direction, but all formula fields remain `a preciser`. | Treat as a future penalty/arrears update rule, not classic interest capitalization. Keep formula gate closed for this specific engine. |
| 17. Accounting balance | Balance formula/statuses are `a definir`; pending transactions are undefined. | Use validated posted ledger entries only for accounting balance. Show reversals internally. Define pending statuses inside transaction workflow, not by asking the formula question again. |
| 18. Available balance | Pending withdrawals remain `a preciser`; formula omits loan restrictions while text says they reduce availability. | Available balance = accounting balance - account minimum - active loan restrictions - applicable holds. Pending withdrawals should reduce availability only after the pending status model exists. |
| 19. Daily/cumulative movements | Transaction date versus posting date is contextual. | Use posting date for accounting reports and transaction date for operational activity reports, with batch day-close as reporting boundary. |
| 25. Collection performance | Inclusion answers include penalties, but formula line omits penalties; actual formula looks like uncollected gap. | Expected collection = scheduled capital + scheduled interest + scheduled penalties. Actual collection = recognized repayments from cash and account debits. Gap = expected - actual; performance = actual / expected. |
