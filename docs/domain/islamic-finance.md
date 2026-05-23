# Islamic Finance Domain Requirements And Full Architecture

Date: 2026-05-23
Status: source-of-truth domain document for Islamic finance implementation

## Source Inputs

Primary stakeholder source:

- `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`, section 29, Finance Islamique.

External standards and governance references to use during design review:

- AAOIFI Shariah Standards: https://aaoifi.com/shariah-standards-3/?lang=en
- AAOIFI Accounting Standards: https://aaoifi.com/accounting-standards-2/?lang=en
- IFSB standards and guiding principles: https://www.ifsb.org/standards-page/
- IFSB-31, Guiding Principles for Effective Supervision of Shariah Governance: https://www.ifsb.org/wp-content/uploads/2025/07/IFSB-31-Guiding-Principles-for-Effective-Supervision-of-Shariah-Governance.pdf
- IFSB-1, Guiding Principles of Risk Management for institutions offering Islamic financial services: https://www.ifsb.org/wp-content/uploads/2023/10/ifsb1.pdf

These references do not replace local legal sign-off. They define the standard families the implementation must be checked against. Cameroon and CEMAC/COBAC regulatory treatment must be verified by the legal/compliance owner before production activation.

## Stakeholder Intent

The stakeholder requested a complete Islamic finance capability, not a cosmetic variation of conventional lending. The system must support:

- Sharia governance through a specialist or committee.
- Islamic current accounts, savings accounts, and investment accounts.
- Mourabaha, Ijara / Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, and Moucharaka.
- Product configuration by type, margin, duration, financed asset, and schedule where applicable.
- Accounting configuration for Mourabaha, Ijara, investments, and Zakat.
- Sharia controls that validate products, block Haram operations, and prevent interest usage.
- Traceability of financed assets, services, goods, projects, and partnership investments.
- Staff training on the difference between Islamic finance and conventional finance.
- Customer education on principles, advantages, risks, and product operation.

## Non-Negotiable Principles

The implementation must enforce these principles as system behavior, not documentation only:

- No riba: no Islamic product may use interest accrual, interest capitalization, or interest penalty revenue.
- No excessive gharar: contracts must capture clear parties, assets or projects, quantities, quality, dates, prices, duties, profit ratios, and loss rules as required by the product type.
- No maisir or speculative use: product configuration must restrict prohibited use cases and require compliance review for uncertain cases.
- Licit activity only: customer activity, financed asset, project purpose, supplier, and source/use of funds must pass screening before approval.
- Real economy linkage: financing must be connected to an asset, service, goods flow, construction/manufacturing obligation, investment activity, or partnership project as required by the product.
- Product-specific accounting: postings must use Islamic operation codes and mappings, fail closed when mappings are absent, and avoid conventional loan-interest accounts.
- Independent Sharia approval: products, contract templates, exceptional terms, corrective actions, and compliance findings require auditable approval by authorized roles.

## Proof By Contradiction Rule

Every workflow must include tests and runtime guards written from the forbidden state backward.

Pattern:

1. Assume the forbidden state can happen.
2. Identify the missing gate that would permit it.
3. Add validation, state transition rules, accounting fail-closed behavior, authorization, and audit evidence that make the forbidden state impossible.
4. Prove it with targeted tests.

Examples:

- Assume a Mourabaha contract can be approved without an asset. The system must reject approval because the contract is not linked to a real purchasable asset with cost evidence.
- Assume an Islamic savings account can accrue conventional interest. The system must reject the product setup because Islamic account products cannot bind interest formulas.
- Assume Moudaraba loss can be charged to the entrepreneur after normal business loss. The system must reject the charge unless an approved misconduct, negligence, or breach finding exists.
- Assume Salam goods are vague. The system must reject contract approval until quality, quantity, delivery date, and delivery place are precise.

## Governance Model

### Sharia Board Or Compliance Committee

The platform must model a Sharia authority with configurable members, roles, mandates, decision scope, effective dates, and evidence attachments.

Required decisions:

- Product family approval.
- Product configuration approval.
- Contract template approval.
- Accounting mapping approval.
- Exception approval.
- Corrective action approval for non-compliance events.
- Haram screening policy approval.
- Profit pool and investment account policy approval.

Required controls:

- Maker-checker for every approval.
- No self-approval for material compliance decisions.
- Versioned decisions with effective dates.
- Archived decision evidence.
- Revocation and suspension workflow.
- Audit trail for every status change.

### Compliance Review

Compliance reviews must be linked to product, contract, customer, supplier, asset, project, account, or transaction. A review must have:

- Scope and reason.
- Reviewer role and assignment.
- Checklist version.
- Decision: approved, rejected, needs information, conditionally approved, suspended, or revoked.
- Conditions and expiry if conditional.
- Evidence documents.
- Audit events.

### Haram Screening

The system must maintain configurable screening rules for:

- Prohibited sectors and activities.
- Restricted assets and goods.
- Supplier or counterparty restrictions.
- Source and use of funds concerns.
- Customer business activity classifications.
- Manual escalation triggers.

Forbidden behavior:

- A product cannot be approved if its configured use violates active screening policy.
- A contract cannot be activated if customer, supplier, asset, goods, or project screening fails.
- A blocked attempt cannot be silently deleted.

## Product Catalog Requirements

### Mourabaha

Mourabaha is a sale with known cost and known margin. The institution buys or otherwise obtains control of an asset, then sells it to the customer at a disclosed sale price.

Required data:

- Customer request and purpose.
- Asset category, description, supplier, and cost evidence.
- Purchase cost and allowed direct costs.
- Margin amount or approved margin rule.
- Sale price snapshot.
- Delivery and acceptance evidence.
- Repayment schedule for sale receivable.
- Late payment treatment approved by Sharia governance.
- Early settlement and rebate policy, if allowed.
- Reversal, cancellation, and asset return rules.

Proof obligations:

- A cash-only Mourabaha must be impossible.
- Sale price must equal approved cost plus allowed additions plus approved margin.
- Receivable schedule must collect sale price, not interest.
- Late fees cannot be recognized as institution profit unless legal and Sharia governance approve the exact treatment.

### Ijara / Ijara Wa Iqtina

Ijara is leasing. Ijara wa Iqtina includes a structured ownership transfer option or promise when approved.

Required data:

- Owned or controlled leased asset.
- Asset condition, useful life, insurance or takaful handling, and maintenance responsibility.
- Lease start, lease end, rental periods, rental amounts, and billing calendar.
- Residual value and ownership transfer option where applicable.
- Damage, total loss, early termination, default, and suspension rules.
- Transfer workflow and evidence for Ijara wa Iqtina.

Proof obligations:

- Lease activation without owned or controlled asset must be impossible.
- Rental receivable must not be posted as interest.
- Ownership transfer cannot happen without an approved transfer event.
- Maintenance and risk responsibility must be explicit before activation.

### Salam

Salam is an advance purchase where payment is made now for specified goods delivered later.

Required data:

- Goods category, quality, quantity, unit, specifications, delivery date, and delivery place.
- Counterparty, purpose, and screening result.
- Upfront payment amount and settlement evidence.
- Delivery workflow: full delivery, partial delivery, substitution, rejection, non-delivery, and dispute.
- Inventory or onward-sale treatment where applicable.
- Parallel Salam controls if the institution uses a separate downstream contract.

Proof obligations:

- Vague goods must make approval impossible.
- Upfront payment cannot post before contract approval and accounting readiness.
- Partial or failed delivery must enter an approved exception workflow.
- A parallel arrangement must remain legally and operationally separate from the original contract.

### Istisna'a

Istisna'a is manufacturing or construction finance for an asset not yet existing or not yet completed.

Required data:

- Asset or project specifications.
- Customer, manufacturer or contractor, and project site where applicable.
- Milestones, inspections, acceptance criteria, staged payments, delivery, and handover.
- Variation order process with before and after contract values.
- Parallel Istisna'a controls if the institution uses a separate supplier contract.
- Defect, delay, cancellation, and dispute workflow.

Proof obligations:

- Staged payment without approved milestone evidence must be impossible.
- Variation orders cannot mutate already posted facts.
- Delivery cannot close until acceptance evidence exists.
- Parallel contracts must not be collapsed into one obligation.

### Moudaraba

Moudaraba is a profit-sharing partnership where the institution provides capital and the entrepreneur provides expertise or management.

Required data:

- Capital amount, disbursement terms, eligible use, and entrepreneur obligations.
- Profit-sharing ratio as a percentage of actual profit, not a guaranteed return.
- Reporting cadence, records required, audit rights, and evidence documents.
- Profit declaration, approval, and distribution workflow.
- Loss handling rules.
- Misconduct, negligence, or breach investigation workflow.
- Exit, liquidation, and recovery treatment.

Proof obligations:

- Guaranteed institution profit must be impossible.
- Profit distribution cannot occur without approved profit evidence.
- Normal business loss cannot be charged to the entrepreneur.
- Entrepreneur liability for loss requires an approved misconduct, negligence, or breach finding.

### Moucharaka

Moucharaka is partnership finance with capital participation by the institution and partner.

Required data:

- Partner contributions, capital ratios, contribution evidence, and project governance.
- Profit-sharing ratio.
- Loss-sharing rule, normally by capital participation unless a legally approved exception is documented.
- Reporting cadence, management rights, reserve policy, and impairment treatment.
- Additional capital, dilution, buyout, exit, and valuation workflow.
- Diminishing partnership variant if the business later enables it through explicit product approval.

Proof obligations:

- Loss allocation by profit ratio must be impossible unless a valid approved exception exists.
- Buyout cannot happen without approved valuation.
- Profit distribution cannot happen without approved partnership reporting.
- Contributions must be evidenced before activation.

## Islamic Account Requirements

### Islamic Current Account

A current account supports deposits, withdrawals, and payments without interest remuneration.

Required behavior:

- No interest accrual configuration.
- Fees only from approved fee schedule.
- Statement language avoids interest terminology.
- Product legal basis must be approved by Sharia governance and legal owner.

### Islamic Savings Account

A savings account may receive returns linked to profit from licit activities. It must not guarantee interest.

Required behavior:

- Profit distribution source must be an approved profit pool or approved investment result.
- Profit calculation must be versioned and auditable.
- Statements show profit distribution, not interest.
- Loss, reserve, smoothing, or waiver policies must be approved before use.

### Islamic Investment Account

An investment account has variable return based on investment results.

Required behavior:

- Account holder agreement defines risk, profit-sharing ratio, pool, tenor, withdrawal restrictions, and loss treatment.
- No guaranteed return unless a specific legally and Sharia-approved guarantee structure exists.
- Pool performance must be approved before distribution.
- Distributions must be reversible only through governed correction entries.

## Accounting Requirements

Accounting must be configured before product activation.

Required operation-code families:

- Mourabaha purchase, direct costs, sale receivable, collection, cancellation, rebate, default handling, and reversal.
- Ijara asset acquisition, rental receivable, rental income, maintenance, impairment, residual, transfer, termination, and reversal.
- Salam upfront payment, goods delivery, partial delivery, non-delivery, inventory, substitution, settlement, and reversal.
- Istisna'a staged payment, work in progress, customer billing, variation, delivery, defect, cancellation, and reversal.
- Moudaraba capital investment, profit declaration, profit distribution, loss, impairment, misconduct recovery, liquidation, and reversal.
- Moucharaka contribution, profit declaration, profit distribution, loss allocation, additional contribution, impairment, buyout, exit, and reversal.
- Islamic account profit pools, profit distribution, reserves, charitable/non-compliant income treatment, Zakat-related accounts where applicable, fees, and corrections.

Fail-closed rule:

- If any required mapping is missing, expired, unapproved, or incompatible with the product state, posting must be blocked.

## Document And Evidence Requirements

The document/media layer must store:

- Sharia approvals.
- Product configuration evidence.
- Contract templates and signed contracts.
- Asset purchase documents.
- Supplier invoices.
- Delivery and acceptance proofs.
- Lease condition reports.
- Salam goods specifications and delivery evidence.
- Istisna'a milestone inspection evidence.
- Profit declarations and business records.
- Partnership contribution evidence.
- Screening results.
- Corrective action evidence.

Raw filesystem paths must not be exposed through APIs.

## Reporting Requirements

Required reports:

- Product approval register.
- Contract template approval register.
- Active Islamic products and readiness status.
- Financing contract register by product family.
- Asset traceability report.
- Haram screening blocked-attempt report.
- Compliance exception and corrective-action report.
- Sharia audit report.
- Product-specific aging and exposure reports.
- Profit pool and distribution report.
- Moudaraba and Moucharaka performance reports.
- Zakat and charity/non-compliant income report if enabled by accounting policy.

Reports must include generated-by, generated-at, source filters, source version, and checksum.

## Existing Implementation Gap

The current codebase contains useful foundation tables and initial workflows, but it is not the complete Islamic finance domain described here.

Observed gaps to close:

- Product and financing workflows only accept `murabaha`.
- Current routes cover product review, financing asset/installment setup, and approval only.
- Product-specific workflows for Ijara, Salam, Istisna'a, Moudaraba, and Moucharaka are absent.
- Islamic account profit pools and distribution workflows are absent.
- Haram screening is not fully modeled as a policy-driven approval blocker.
- Contract template approval is not fully modeled.
- Product-specific accounting mappings are incomplete.
- Sharia audit reporting is incomplete.
- Stakeholder examples for Ijara, Moudaraba, and Moucharaka are not implemented as workflows.

## Completion Gate

Islamic finance is complete only when all stakeholder-requested Islamic accounts and product families in this document are implemented, tested, governed, posted through approved accounting mappings, and reportable.

A product family may be omitted only by a dated business, legal, and Sharia governance decision that records the reason, affected stakeholder requirement, and replacement plan if any.

## Stakeholder Example Fixtures

The stakeholder supplied example calculations. They must be preserved as fixtures for validation, demonstrations, and user acceptance. These examples are not hardcoded universal formulas; they are proof cases that the configurable product engines can represent the stakeholder's expected behavior.

Required fixtures:

- Mourabaha: purchase amount `100000 XAF`, allowed additions `20000 XAF`, total sale receivable `120000 XAF`.
- Ijara: acquisition amount `250000 XAF`, term `5 months`, monthly rental `52000 XAF`, residual `30000 XAF`, total expected customer outflow `290000 XAF`.
- Moudaraba: investment `500000 XAF`, term `5 years`, projected annual result `200000 XAF`, profit split `60%` microfinance and `40%` entrepreneur.
- Moucharaka: capital `500000 XAF` with `250000 XAF` contributed by each party, projected annual profit `100000 XAF`, profit split `70%` startup and `30%` microfinance.

Proof obligations:

- The Mourabaha fixture must calculate the disclosed sale receivable from cost plus allowed additions.
- The Ijara fixture must separate rentals from residual transfer value.
- The Moudaraba fixture must treat the annual result as a profit-sharing basis, not a guaranteed return.
- The Moucharaka fixture must keep capital contribution ratios separate from the agreed profit-sharing ratio.
