# Islamic Finance Complete Implementation Backlog

Date: 2026-05-23
Status: implementation backlog

This backlog implements the complete stakeholder-requested Islamic finance domain. It covers Sharia governance, Islamic accounts, Mourabaha, Ijara / Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, Moucharaka, accounting, Haram blocking, interest controls, Zakat-related accounting where configured, asset traceability, reporting, and tests.

Source documents:

- `docs/domain/islamic-finance.md`
- `docs/adr/islamic-finance-full-architecture.md`
- `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`, section 29

External standards to validate against:

- AAOIFI Shariah Standards: https://aaoifi.com/shariah-standards-3/?lang=en
- AAOIFI Accounting Standards: https://aaoifi.com/accounting-standards-2/?lang=en
- IFSB standards and guiding principles: https://www.ifsb.org/standards-page/
- IFSB-31, Guiding Principles for Effective Supervision of Shariah Governance: https://www.ifsb.org/wp-content/uploads/2025/07/IFSB-31-Guiding-Principles-for-Effective-Supervision-of-Shariah-Governance.pdf
- IFSB-1, Guiding Principles of Risk Management for institutions offering Islamic financial services: https://www.ifsb.org/wp-content/uploads/2023/10/ifsb1.pdf

## Completion Standard

The Islamic finance domain is complete when every item below is implemented or has a dated rejection signed by business, legal, accounting, and Sharia governance owners. A rejection must identify the stakeholder requirement it removes and the operational consequence.

No item is complete without:

- Migration and model changes where needed.
- API contracts and request validation.
- Authorization and audit coverage.
- Accounting mapping behavior where money moves.
- Document/evidence behavior where approvals or contracts exist.
- Targeted feature tests.
- Forbidden-state tests using the proof-by-contradiction rule.

## 1. Standards, Ownership, And Legal Baseline

### IF-001: Create Islamic Finance Standards Registry

Goal: record the standards, legal opinions, policies, and internal decisions that govern Islamic finance behavior.

Proof-by-contradiction invariant: assume a product is approved against no identifiable standard. Approval must be impossible because the product has no active standards baseline.

Acceptance criteria:

- Store standard source, title, version or publication date where known, scope, owner, effective date, expiry date, and attachment.
- Allow linking standards to product families, account types, accounting mappings, contract templates, and screening policies.
- Require standards baseline before product readiness approval.
- Audit creation, amendment, activation, expiry, and retirement.

Tests:

- Product readiness fails without active standards baseline.
- Expired standard blocks new approvals.
- Standards amendment creates an audit event.

### IF-002: Capture Local Regulatory Sign-Off

Goal: require legal/compliance sign-off for Cameroon and CEMAC/COBAC treatment before production activation.

Proof-by-contradiction invariant: assume Islamic finance is activated without local regulatory sign-off. Activation must be impossible because legal clearance is missing.

Acceptance criteria:

- Store jurisdiction, regulator, opinion summary, allowed products, restrictions, accounting implications, responsible owner, approval date, and evidence.
- Link sign-off to product family and account type.
- Block production activation when sign-off is absent, expired, or restrictive.

Tests:

- Product can be configured in draft without sign-off.
- Product cannot activate without sign-off.
- Restrictive sign-off blocks disallowed product family.

## 2. Sharia Governance Foundation

### IF-010: Sharia Authority Model

Goal: model the Sharia Board or compliance committee requested by stakeholders.

Proof-by-contradiction invariant: assume a staff user without Sharia mandate approves a product. Approval must be rejected by role and mandate checks.

Acceptance criteria:

- Add committee/authority records with mandate, members, role, scope, effective dates, and evidence.
- Support chair, reviewer, approver, observer, and administrator roles.
- Enforce no self-approval for material decisions.
- Audit membership changes and mandate changes.

Tests:

- Unauthorized staff cannot approve.
- Expired mandate cannot approve.
- Self-approval is rejected.

### IF-011: Sharia Approval Workflow

Goal: provide reusable approval states for products, templates, policies, contracts, exceptions, mappings, and corrective actions.

Proof-by-contradiction invariant: assume an Islamic product is used while still draft. Origination must be impossible because only approved active records are selectable.

Acceptance criteria:

- States include draft, submitted, approved, rejected, suspended, revoked, expired, and archived.
- Approval captures approver, decision, comments, conditions, evidence, and effective dates.
- Suspension and revocation immediately block new use while preserving existing records.
- Conditional approval can enforce expiry and conditions.

Tests:

- Draft product cannot originate a contract.
- Suspended template cannot be used.
- Revoked policy blocks new approvals.

### IF-012: Compliance Review Case Management

Goal: track reviews across products, customers, assets, goods, projects, suppliers, accounts, contracts, and transactions.

Proof-by-contradiction invariant: assume a flagged contract proceeds with an unresolved review. Activation must be blocked.

Acceptance criteria:

- Create review cases with subject type, reason, risk, checklist version, assigned reviewer, due date, decision, and evidence.
- Support approved, rejected, needs information, conditionally approved, suspended, and corrective action decisions.
- Link reviews to workflow blockers.
- Expose reportable status and audit trail.

Tests:

- Unresolved blocking review prevents activation.
- Conditional approval expires and blocks future action.
- Corrective action closure is audited.

## 3. Haram Screening And Interest Controls

### IF-020: Screening Policy Configuration

Goal: configure licit and prohibited activity screening for all Islamic products.

Proof-by-contradiction invariant: assume a prohibited activity passes because the rule is only informational. Contract approval must be blocked by active screening policy.

Acceptance criteria:

- Configure prohibited sectors, restricted sectors, prohibited goods, restricted goods, supplier flags, customer business flags, source/use-of-funds flags, and escalation rules.
- Version policies and require Sharia approval before activation.
- Preserve policy snapshot on each screening result.
- Support manual override only through approved exception workflow.

Tests:

- Prohibited sector blocks product approval.
- Restricted sector creates compliance review.
- Policy version is snapshotted on result.

### IF-021: Screening Execution Engine

Goal: run screening for customer, supplier, asset, goods, project, contract, and account context.

Proof-by-contradiction invariant: assume a contract activates without screening. Activation must fail because screening result is missing.

Acceptance criteria:

- Execute screening before product approval, contract approval, supplier use, asset acceptance, goods acceptance, project approval, and investment account pool assignment.
- Results include pass, fail, manual review, expired, and not applicable.
- Failed result blocks action and records blocked attempt.
- Manual review routes to compliance case.

Tests:

- Missing screening blocks activation.
- Failed screening records blocked attempt.
- Manual review opens compliance case.

### IF-022: Interest Control Guardrails

Goal: prevent Islamic products and accounts from using conventional interest behavior.

Proof-by-contradiction invariant: assume an Islamic account accrues conventional interest. The system must reject product configuration and any posting event using interest mappings.

Acceptance criteria:

- Islamic products cannot bind conventional interest formulas.
- Islamic products cannot post to interest revenue or interest receivable mappings.
- Islamic account statements must label distributions as profit, fees, rent, sale receivable, or approved product-specific terms.
- Late-payment handling must route to approved fee, charity, cost recovery, or corrective treatment only.

Tests:

- Interest formula on Islamic account product is rejected.
- Interest operation code cannot be linked to Islamic product.
- Statement generation rejects forbidden terminology configuration.

## 4. Product Catalog And Readiness

### IF-030: Islamic Product Family Catalog

Goal: define all stakeholder-requested Islamic product families as first-class product families.

Proof-by-contradiction invariant: assume a new Islamic contract is created under an unknown or generic family. Creation must fail because product family is not approved.

Acceptance criteria:

- Product families include Mourabaha, Ijara, Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, Moucharaka, Islamic current account, Islamic savings account, and Islamic investment account.
- Each family defines required fields, workflow states, evidence rules, accounting events, screening rules, reporting category, and readiness checklist.
- Add translated display names where the API needs user-facing labels.

Tests:

- Unknown family rejected.
- Required fields differ by family.
- Product family metadata is exposed through read API.

### IF-031: Product Readiness Checklist

Goal: block product activation until governance, accounting, contracts, evidence, screening, and reports are complete.

Proof-by-contradiction invariant: assume a product activates without accounting mapping. Activation must fail on readiness check.

Acceptance criteria:

- Checklist includes standards baseline, legal sign-off, Sharia approval, contract template, accounting mappings, screening policy, document requirements, report category, authorization rules, and operational procedure.
- Checklist is product-family specific.
- Readiness result explains missing items.
- Activation stores readiness snapshot.

Tests:

- Missing template blocks activation.
- Missing mapping blocks activation.
- Completed checklist allows activation.

### IF-032: Contract Template Registry

Goal: store versioned contract templates approved for each product family.

Proof-by-contradiction invariant: assume a contract is originated from an unapproved template. Origination must be rejected.

Acceptance criteria:

- Store template family, language, version, effective date, expiry, fields, approval status, and document attachment.
- Require Sharia and legal approval before use.
- Contract snapshots template version and resolved commercial terms.
- Retired template remains visible for historical contracts.

Tests:

- Draft template cannot originate contract.
- Expired template cannot originate new contract.
- Existing contract keeps old template snapshot.

## 5. Asset, Goods, Project, And Partnership Registries

### IF-040: Financed Asset Registry

Goal: track assets for Mourabaha and Ijara workflows.

Proof-by-contradiction invariant: assume an asset-backed contract activates without asset evidence. Activation must fail.

Acceptance criteria:

- Store asset category, description, supplier, acquisition cost, ownership/control status, condition, documents, location, customer request, screening result, and status.
- Statuses include requested, quoted, purchased, controlled, delivered, leased, transferred, returned, impaired, disposed, and cancelled.
- Asset status transitions are audited and product-aware.

Tests:

- Mourabaha approval requires purchased or controlled asset as configured.
- Ijara activation requires owned or controlled leased asset.
- Asset status transition without evidence is rejected.

### IF-041: Salam Goods Registry

Goal: track specified goods for Salam contracts.

Proof-by-contradiction invariant: assume goods are described vaguely. Contract approval must fail until required specification fields are complete.

Acceptance criteria:

- Store goods category, quality, quantity, unit, delivery date, delivery place, counterparty, inspection requirements, and acceptance rules.
- Support partial delivery, substitution request, rejection, and non-delivery states.
- Link delivery evidence to inventory or settlement treatment.

Tests:

- Missing quantity blocks approval.
- Missing delivery date blocks approval.
- Partial delivery opens settlement workflow.

### IF-042: Istisna'a Project Registry

Goal: track construction or manufacturing obligations.

Proof-by-contradiction invariant: assume a milestone payment is released without inspection evidence. Payment must be rejected.

Acceptance criteria:

- Store project specification, contractor, customer, site, milestones, payment plan, inspection rules, variation orders, acceptance criteria, and delivery evidence.
- Support parallel supplier contract reference where approved.
- Variation order creates versioned before/after values.

Tests:

- Payment requires approved milestone evidence.
- Variation cannot change already posted payment facts.
- Acceptance closes project obligation only after evidence.

### IF-043: Partnership Registry

Goal: track Moudaraba and Moucharaka investments.

Proof-by-contradiction invariant: assume partnership profit is distributed without contribution evidence. Distribution must fail.

Acceptance criteria:

- Store partners, capital contributions, contribution evidence, profit ratios, loss rules, governance rights, reporting cadence, and exit terms.
- Track reports, profit declarations, losses, misconduct findings, valuations, buyouts, and liquidation.
- Separate Moudaraba and Moucharaka rule sets.

Tests:

- Missing contribution evidence blocks activation.
- Profit declaration requires report evidence.
- Buyout requires approved valuation.

## 6. Islamic Accounting Foundation

### IF-050: Islamic Operation Codes

Goal: define operation codes for every product family and account event.

Proof-by-contradiction invariant: assume an Islamic event posts through a conventional loan-interest code. Posting must fail.

Acceptance criteria:

- Add operation-code families for Mourabaha, Ijara, Salam, Istisna'a, Moudaraba, Moucharaka, Islamic accounts, profit pools, Zakat-related accounting, charity/non-compliant income treatment, reversals, impairments, and corrections.
- Operation codes declare product family, event type, debit/credit expectations, allowed states, and reversal behavior.
- Conventional interest operation codes cannot be assigned to Islamic products.

Tests:

- Islamic product rejects conventional operation code.
- Missing operation code blocks posting.
- Reversal uses configured reversal code.

### IF-051: Approved Mapping Workflow

Goal: require accounting and Sharia approval for mappings that move money.

Proof-by-contradiction invariant: assume money posts through an unapproved mapping. Posting must fail.

Acceptance criteria:

- Mapping records operation code, debit account, credit account, effective dates, currency, agency scope, approval status, accounting owner, and Sharia approval where required.
- Mapping validation runs before every posting.
- Expired mappings block new postings.

Tests:

- Draft mapping blocks posting.
- Expired mapping blocks posting.
- Approved mapping posts expected journal lines.

### IF-052: Zakat And Charity/Non-Compliant Income Accounts

Goal: support stakeholder-requested Zakat accounting and governed treatment of non-compliant income where configured.

Proof-by-contradiction invariant: assume late-payment penalty income is recognized as ordinary profit. Posting must fail unless the active policy permits the exact treatment.

Acceptance criteria:

- Configure Zakat-related accounts and charity/non-compliant income accounts where institution policy requires them.
- Link late-payment, non-compliant income, and purification events to approved treatment.
- Reports show balances and source transactions.

Tests:

- Missing charity treatment blocks configured late-fee event.
- Zakat account mapping required when product policy enables Zakat posting.
- Report reconciles to posted journals.

## 7. Mourabaha Implementation

### IF-060: Mourabaha Product Configuration

Goal: configure Mourabaha as a cost-plus sale product.

Proof-by-contradiction invariant: assume Mourabaha is configured as an interest-bearing loan. Configuration must be rejected.

Acceptance criteria:

- Configure allowed asset categories, allowed costs, margin rule, repayment schedule rules, delivery requirements, early settlement policy, late-payment policy, cancellation policy, and accounting mappings.
- Store Sharia approval and contract template version.
- Prohibit interest formulas.

Tests:

- Interest formula rejected.
- Missing allowed-cost policy blocks activation.
- Approved configuration can originate contract.

### IF-061: Mourabaha Origination And Purchase

Goal: support customer request, institution purchase/control, cost capture, and sale contract creation.

Proof-by-contradiction invariant: assume sale contract exists before institution purchase/control evidence. Approval must fail.

Acceptance criteria:

- Capture customer request, asset, supplier quote, screening, purchase approval, purchase evidence, and cost evidence.
- Calculate sale price from purchase cost plus allowed costs plus margin.
- Snapshot disclosed cost, margin, sale price, and schedule terms.
- Block contract if sale price formula does not reconcile.

Tests:

- Missing asset rejected.
- Missing purchase evidence rejected.
- Sale price mismatch rejected.

### IF-062: Mourabaha Receivable, Collection, And Reversal

Goal: post sale receivable and collections through Mourabaha mappings.

Proof-by-contradiction invariant: assume collection posts interest revenue. Posting must fail.

Acceptance criteria:

- Create receivable schedule equal to approved sale price.
- Post sale receivable after contract activation.
- Collect installments against sale receivable.
- Handle rebate, cancellation, default treatment, reversal, and correction through approved policies.

Tests:

- Schedule total equals sale price.
- Interest revenue mapping rejected.
- Reversal offsets original journal.

## 8. Ijara / Ijara Wa Iqtina Implementation

### IF-070: Ijara Product Configuration

Goal: configure leasing products with or without ownership transfer.

Proof-by-contradiction invariant: assume ownership transfer occurs under ordinary Ijara without transfer terms. Transfer must be rejected.

Acceptance criteria:

- Configure leased asset categories, rental rules, maintenance responsibility, insurance/takaful handling, residual value, transfer option, damage/loss rules, termination rules, and accounting mappings.
- Distinguish Ijara from Ijara wa Iqtina.
- Store approved templates for each variant.

Tests:

- Transfer option unavailable on ordinary Ijara unless configured.
- Missing maintenance policy blocks activation.
- Missing residual policy blocks transfer variant activation.

### IF-071: Ijara Contract And Rental Schedule

Goal: activate lease contracts backed by owned or controlled assets.

Proof-by-contradiction invariant: assume lease activates without institution asset ownership/control. Activation must fail.

Acceptance criteria:

- Capture asset, condition report, lease term, rental schedule, customer obligations, institution obligations, and evidence.
- Post rental receivable and rental income per approved mapping.
- Handle asset damage, rental suspension, early termination, and transfer.

Tests:

- No owned asset blocks activation.
- Rental schedule excludes sale-price interest logic.
- Damage event creates approved workflow.

### IF-072: Ijara Wa Iqtina Transfer

Goal: transfer asset ownership only through approved transfer event.

Proof-by-contradiction invariant: assume asset ownership changes by direct field edit. Mutation must be rejected; transfer must use workflow.

Acceptance criteria:

- Transfer requires completed rental obligations or approved exception.
- Transfer captures residual amount, waiver if approved, transfer document, accounting posting, and customer acceptance.
- Asset status changes to transferred with audit evidence.

Tests:

- Direct transfer mutation rejected.
- Missing transfer evidence rejected.
- Transfer posts configured residual or approved zero-residual mapping.

## 9. Salam Implementation

### IF-080: Salam Product Configuration

Goal: configure Salam as upfront purchase of specified goods delivered later.

Proof-by-contradiction invariant: assume Salam is used as unrestricted cash financing. Contract approval must fail because goods and delivery terms are required.

Acceptance criteria:

- Configure allowed goods, specification requirements, payment timing, delivery rules, inspection rules, substitution policy, non-delivery policy, parallel Salam policy, and accounting mappings.
- Store approved template and screening policy.

Tests:

- Missing goods policy blocks activation.
- Missing upfront payment mapping blocks activation.
- Cash-only request rejected.

### IF-081: Salam Contract, Payment, And Delivery

Goal: manage approval, upfront payment, goods delivery, and settlement.

Proof-by-contradiction invariant: assume upfront payment posts before contract approval. Posting must fail.

Acceptance criteria:

- Capture goods specification, quantity, quality, delivery date, delivery place, counterparty, price, and evidence.
- Post upfront payment only after approval.
- Manage full delivery, partial delivery, substitution, rejection, non-delivery, and dispute.
- Link delivery to inventory or settlement accounting.

Tests:

- Approval rejects vague goods.
- Payment before approval rejected.
- Partial delivery opens settlement state.

## 10. Istisna'a Implementation

### IF-090: Istisna'a Product Configuration

Goal: configure construction/manufacturing contracts.

Proof-by-contradiction invariant: assume staged payments are allowed without milestones. Product activation must fail.

Acceptance criteria:

- Configure project categories, milestone rules, inspection rules, payment rules, variation rules, delivery/acceptance rules, defect rules, parallel Istisna'a policy, and mappings.
- Store approved template and screening policy.

Tests:

- Missing milestone policy blocks activation.
- Missing variation policy blocks activation.
- Missing project mapping blocks activation.

### IF-091: Istisna'a Project Workflow

Goal: originate, fund, inspect, vary, deliver, and close manufacturing/construction contracts.

Proof-by-contradiction invariant: assume contractor payment occurs without approved milestone. Posting must fail.

Acceptance criteria:

- Capture project specs, parties, milestones, payment plan, inspections, contract value, and delivery criteria.
- Approve milestone before payment.
- Approve variation before changing future obligations.
- Close only after delivery and acceptance evidence.

Tests:

- Payment without approved milestone rejected.
- Variation after posted milestone cannot rewrite original journal.
- Delivery without acceptance rejected.

## 11. Moudaraba Implementation

### IF-100: Moudaraba Product Configuration

Goal: configure capital-provider and entrepreneur profit-sharing products.

Proof-by-contradiction invariant: assume fixed institution return is configured. Product activation must fail.

Acceptance criteria:

- Configure eligible business activities, capital rules, profit-sharing ratios, reporting cadence, evidence requirements, loss rules, misconduct/negligence/breach rules, liquidation rules, and mappings.
- Prohibit guaranteed return, interest, and fixed profit amount as institution entitlement.

Tests:

- Guaranteed return rejected.
- Missing reporting cadence blocks activation.
- Missing loss-rule policy blocks activation.

### IF-101: Moudaraba Contract And Capital Deployment

Goal: activate and manage Moudaraba investments.

Proof-by-contradiction invariant: assume capital is disbursed without approved contract and screening. Disbursement must fail.

Acceptance criteria:

- Capture entrepreneur, business plan, capital amount, eligible use, profit ratio, reporting obligations, evidence, and screening.
- Disburse capital through approved mapping.
- Require periodic reports.
- Block profit distribution until report and profit declaration are approved.

Tests:

- Disbursement before approval rejected.
- Missing report blocks profit distribution.
- Profit ratio snapshot used for distribution.

### IF-102: Moudaraba Loss, Misconduct, And Exit

Goal: handle losses, misconduct, liquidation, and exit correctly.

Proof-by-contradiction invariant: assume normal business loss is charged to entrepreneur. Charge must fail.

Acceptance criteria:

- Record normal business loss against capital provider as configured.
- Entrepreneur liability requires approved misconduct, negligence, or breach finding.
- Exit/liquidation records final assets, recoveries, losses, and distributions.
- Accounting posts impairment, loss, recovery, and liquidation events through approved mappings.

Tests:

- Normal loss cannot be charged to entrepreneur.
- Misconduct recovery requires approved finding.
- Liquidation report reconciles postings.

## 12. Moucharaka Implementation

### IF-110: Moucharaka Product Configuration

Goal: configure partnership products with capital participation.

Proof-by-contradiction invariant: assume loss-sharing follows profit ratio where capital ratio differs. Configuration or posting must fail unless approved exception exists.

Acceptance criteria:

- Configure contribution rules, profit ratio rules, loss ratio rules, governance rights, reporting cadence, reserve policy, additional capital, impairment, buyout, valuation, exit, and mappings.
- Store whether diminishing partnership is enabled; if enabled, require its own transfer and valuation rules.

Tests:

- Loss by profit ratio rejected without approved exception.
- Missing valuation policy blocks buyout-enabled activation.
- Missing contribution evidence policy blocks activation.

### IF-111: Moucharaka Partnership Workflow

Goal: originate, operate, distribute, impair, and exit partnerships.

Proof-by-contradiction invariant: assume partnership activates without both parties' contribution evidence. Activation must fail.

Acceptance criteria:

- Capture partners, contributions, contribution evidence, capital ratios, profit ratios, loss rules, governance, reporting, and exit terms.
- Distribute profit from approved reporting.
- Allocate loss by approved capital rule.
- Process additional capital, impairment, buyout, and exit through governed workflows.

Tests:

- Missing contribution evidence blocks activation.
- Profit distribution uses contract ratio.
- Loss distribution uses capital ratio unless approved exception exists.
- Buyout requires valuation approval.

## 13. Islamic Accounts

### IF-120: Islamic Current Account Product

Goal: support current accounts with deposits, withdrawals, and payments without interest remuneration.

Proof-by-contradiction invariant: assume current account earns interest. Configuration and posting must fail.

Acceptance criteria:

- Configure legal/Sharia basis, fees, restrictions, statement labels, closure rules, and mappings.
- Disable interest accrual.
- Ensure statement vocabulary avoids interest terminology.

Tests:

- Interest setup rejected.
- Deposit/withdrawal posts through account mappings.
- Statement contains approved labels.

### IF-121: Islamic Savings Account Product

Goal: support savings accounts with returns linked to profits from licit activities.

Proof-by-contradiction invariant: assume guaranteed savings return is configured as interest. Activation must fail.

Acceptance criteria:

- Configure profit pool, distribution ratio, calculation period, reserve policy, withdrawal rules, loss policy, statement labels, and mappings.
- Distribution requires approved pool result.
- No guaranteed interest return.

Tests:

- Guaranteed return rejected.
- Distribution without approved pool result rejected.
- Statement labels distribution as profit.

### IF-122: Islamic Investment Account Product

Goal: support investment accounts with variable return based on investment result.

Proof-by-contradiction invariant: assume account holder receives a fixed return despite investment loss. Distribution must fail.

Acceptance criteria:

- Configure pool, tenor, risk disclosure, profit ratio, loss treatment, withdrawal restrictions, reserve policy, and mappings.
- Account agreement snapshots terms.
- Profit/loss distribution uses approved pool performance.

Tests:

- Fixed return rejected.
- Loss period does not create guaranteed profit.
- Withdrawal restriction enforced.

### IF-123: Profit Pool Engine

Goal: calculate, approve, post, and report Islamic savings/investment distributions.

Proof-by-contradiction invariant: assume profit is distributed from unapproved or negative pool result. Posting must fail.

Acceptance criteria:

- Pool records eligible assets/products, income, expenses, reserves, allocation method, account eligibility, and period.
- Pool close requires accounting and Sharia approval where configured.
- Distribution snapshots calculation inputs, ratios, reserves, and rounding treatment.
- Corrections use reversal or adjustment workflow.

Tests:

- Unapproved pool cannot distribute.
- Distribution total reconciles to approved pool amount.
- Correction creates audit and reversal/adjustment entries.

## 14. Payments, Collections, Defaults, And Corrections

### IF-130: Product-Specific Payment Routing

Goal: route payments to correct Islamic obligations.

Proof-by-contradiction invariant: assume a Mourabaha payment reduces conventional loan interest. Routing must fail.

Acceptance criteria:

- Payment allocation is product-family aware.
- Allocation rules distinguish sale receivable, rent, goods settlement, project billing, investment profit, partnership distribution, fees, charity/non-compliant income, and reversals.
- Manual allocation requires authorization and audit.

Tests:

- Wrong family allocation rejected.
- Manual allocation audited.
- Payment receipt shows product-specific labels.

### IF-131: Default And Late Payment Handling

Goal: handle arrears without converting Islamic products into interest-bearing debt.

Proof-by-contradiction invariant: assume late payment generates institution interest income. Posting must fail.

Acceptance criteria:

- Configure product-family late-payment policy.
- Separate recovery of actual costs, approved fees, charity/non-compliant income, rescheduling, suspension, asset recovery, and legal recovery.
- Late treatment is visible in Sharia audit reports.

Tests:

- Interest penalty rejected.
- Charity routing required where policy uses charitable treatment.
- Rescheduling snapshots approved terms.

### IF-132: Reversal And Correction Framework

Goal: correct posted Islamic finance facts without destructive deletion.

Proof-by-contradiction invariant: assume a posted compliance or accounting fact is deleted. Deletion must be impossible through domain APIs.

Acceptance criteria:

- Support reversal, correction, suspension, revocation, and corrective action states.
- Preserve original event, reason, approver, evidence, and replacement event.
- Reports include both original and corrective events.

Tests:

- Posted journal cannot be deleted.
- Reversal balances original journal.
- Corrective action appears in report.

## 15. APIs, Authorization, And Public IDs

### IF-140: API Resource Design

Goal: expose Islamic finance APIs with consistent public IDs and clear resource boundaries.

Proof-by-contradiction invariant: assume an API exposes internal numeric IDs for contracts. Response contract must fail serialization review or test.

Acceptance criteria:

- APIs use public IDs for products, contracts, assets, goods, projects, partnerships, reviews, approvals, accounts, and reports.
- Endpoints are family-specific where behavior differs materially.
- Requests use form/request validation with product-family specific rules.
- Responses follow standard API envelope.

Tests:

- Response does not expose internal IDs.
- Invalid family-specific payload rejected.
- Authorization enforced per endpoint.

### IF-141: Roles And Permissions

Goal: enforce separation of duties.

Proof-by-contradiction invariant: assume one teller can create, approve, post, and reverse a contract alone. Workflow must reject insufficient separation.

Acceptance criteria:

- Permissions cover Sharia governance, product configuration, screening, contract origination, evidence upload, accounting mapping, posting, collections, reversal, reporting, and administration.
- Sensitive decisions require maker-checker.
- Role assignment is audited.

Tests:

- Originator cannot approve own material decision.
- Unauthorized role cannot post.
- Reporter role can view reports without mutation rights.

## 16. Reporting And Audit

### IF-150: Sharia Audit Report

Goal: generate an auditable report of compliance state and exceptions.

Proof-by-contradiction invariant: assume a blocked Haram attempt is invisible to auditors. Report must include it.

Acceptance criteria:

- Include product approvals, template approvals, screenings, blocked attempts, contract exceptions, late-payment treatments, corrective actions, suspensions, revocations, and unresolved reviews.
- Support filters by agency, product family, period, status, reviewer, and risk.
- Include generated-by, generated-at, checksum, and source version.

Tests:

- Blocked attempt appears.
- Filters return correct subset.
- Checksum changes when source set changes.

### IF-151: Product And Exposure Reports

Goal: report active Islamic contracts and financial exposure by product family.

Proof-by-contradiction invariant: assume Islamic exposure is counted as conventional loans without product split. Report reconciliation must fail.

Acceptance criteria:

- Reports cover Mourabaha receivables, Ijara rentals/assets, Salam goods commitments, Istisna'a projects, Moudaraba investments, Moucharaka partnerships, account profit pools, arrears, impairments, and reversals.
- Reconcile to posted journals.
- Show product-specific labels and statuses.

Tests:

- Report total reconciles to ledger.
- Product-family filter works.
- Conventional loan balances excluded.

### IF-152: Profit Pool And Distribution Reports

Goal: report Islamic savings and investment account distributions.

Proof-by-contradiction invariant: assume distribution is posted without explaining source pool. Report must fail because source pool link is mandatory.

Acceptance criteria:

- Show pool income, expenses, reserves, distributable amount, ratio, accounts included, distributions, corrections, and approvals.
- Reconcile distribution postings to ledger.
- Exportable with checksum.

Tests:

- Missing source pool blocks distribution report generation.
- Report reconciles to ledger.
- Correction appears with original distribution.

## 17. Migration From Current Partial Foundation

### IF-160: Audit Existing Islamic Tables And Workflows

Goal: map current schema and code to the full architecture without assuming current tables are sufficient.

Proof-by-contradiction invariant: assume a current generic table supports all product families. Audit must identify missing fields, states, mappings, and constraints before migration.

Acceptance criteria:

- Inventory current migrations, models, routes, workflows, validation, tests, and operation mappings.
- Classify each artifact as keep, extend, split, replace, or remove.
- Identify data migration steps and backward-compatibility impact.
- Produce a migration plan before schema changes.

Tests:

- Audit fixture identifies missing non-Mourabaha workflows.
- Migration plan references every current Islamic table.
- No live data migration runs without dry-run report.

### IF-161: Replace Murabaha-Only Validation

Goal: remove validation that limits Islamic products to one family and replace it with product-family specific validators.

Proof-by-contradiction invariant: assume Ijara, Salam, Istisna'a, Moudaraba, or Moucharaka cannot be configured because code hardcodes one family. Tests must prove all approved families validate through their own rules.

Acceptance criteria:

- Product-family validation is metadata-driven or strategy-based.
- Every stakeholder-requested family has validator and tests.
- Unknown family remains rejected.

Tests:

- Mourabaha payload validates under Mourabaha rules.
- Ijara payload validates under Ijara rules.
- Salam payload validates under Salam rules.
- Istisna'a payload validates under Istisna'a rules.
- Moudaraba payload validates under Moudaraba rules.
- Moucharaka payload validates under Moucharaka rules.

## 18. Test Strategy

### IF-170: Product-Family Feature Test Suites

Goal: create dedicated tests for each product family and account type.

Proof-by-contradiction invariant: assume broad generic tests prove compliance. Acceptance must fail because each product family has forbidden states that only family-specific tests cover.

Acceptance criteria:

- Test suites exist for governance, screening, accounting, documents, reporting, Mourabaha, Ijara, Salam, Istisna'a, Moudaraba, Moucharaka, current accounts, savings accounts, investment accounts, profit pools, payments, defaults, reversals, and migration.
- Tests cover success path and forbidden states.
- Tests assert audit events for approvals and rejections.

Tests:

- CI can run Islamic finance test group independently.
- Every backlog item has at least one targeted test reference before closure.

### IF-171: Accounting Reconciliation Tests

Goal: prove every money-moving workflow posts balanced, approved, product-specific journals.

Proof-by-contradiction invariant: assume a journal is unbalanced or posted through wrong mapping. Test must fail.

Acceptance criteria:

- Every product-family posting has debit/credit balance assertions.
- Tests assert mapping approval state.
- Tests assert reversal symmetry.
- Reports reconcile to ledger fixtures.

Tests:

- Unbalanced posting rejected.
- Draft mapping rejected.
- Reversal offsets original.

### IF-172: Compliance Regression Fixtures

Goal: preserve known forbidden scenarios as regression tests.

Proof-by-contradiction invariant: assume a future refactor reopens a forbidden path. Regression suite must fail.

Acceptance criteria:

- Fixture scenarios include cash-only Mourabaha, Ijara without asset, vague Salam goods, Istisna'a payment without milestone, guaranteed Moudaraba return, unsupported Moudaraba loss charge, Moucharaka loss by wrong ratio, Islamic interest accrual, missing mapping posting, missing Sharia approval, and Haram-screened activation.
- Fixtures are named by forbidden state.
- Tests run in CI.

Tests:

- Each forbidden fixture fails for the intended reason.
- Error messages are precise enough for operators.

### IF-173: Stakeholder Example Acceptance Fixtures

Goal: preserve the stakeholder's calculation examples as executable acceptance fixtures.

Proof-by-contradiction invariant: assume the platform implements the right product names but cannot reproduce the stakeholder's examples. Acceptance must fail because the implementation has not proven it can represent the requested business behavior.

Acceptance criteria:

- Mourabaha fixture: purchase amount `100000 XAF`, allowed additions `20000 XAF`, total sale receivable `120000 XAF`.
- Ijara fixture: acquisition amount `250000 XAF`, term `5 months`, monthly rental `52000 XAF`, residual `30000 XAF`, total expected customer outflow `290000 XAF`.
- Moudaraba fixture: investment `500000 XAF`, term `5 years`, projected annual result `200000 XAF`, profit split `60%` microfinance and `40%` entrepreneur.
- Moucharaka fixture: capital `500000 XAF` with `250000 XAF` contributed by each party, projected annual profit `100000 XAF`, profit split `70%` startup and `30%` microfinance.
- Fixtures are implemented as product-specific tests and demo seed data, not hardcoded production formulas.
- Fixture outputs must reconcile to product-specific accounting events where money is posted.

Tests:

- Mourabaha fixture calculates sale receivable from purchase amount plus allowed additions.
- Ijara fixture separates rental total from residual transfer value.
- Moudaraba fixture distributes approved profit by ratio and does not guarantee the projected result.
- Moucharaka fixture separates equal capital contribution from different profit-sharing ratio.

## 19. Operational Readiness

### IF-180: Staff Training And Operating Procedures

Goal: reflect stakeholder requirement to train staff and explain differences from conventional finance.

Proof-by-contradiction invariant: assume product is activated with no procedure or trained role. Activation must fail readiness check.

Acceptance criteria:

- Store operating procedure per product family.
- Store training acknowledgment requirements by role.
- Product activation requires procedure and training plan.
- Critical workflow screens link to procedure references.

Tests:

- Missing procedure blocks activation.
- Staff without required training cannot execute restricted action.
- Training acknowledgment is audited.

### IF-181: Customer Education Material Registry

Goal: support stakeholder requirement to sensitize customers on principles, benefits, and operation.

Proof-by-contradiction invariant: assume customer signs investment account without approved education/disclosure material. Account opening must fail.

Acceptance criteria:

- Store approved customer materials by product family and language.
- Contract or account opening captures material version presented to customer.
- Investment and partnership products require risk disclosure acknowledgment.

Tests:

- Missing material blocks account opening where required.
- Disclosure version is snapshotted.
- Updated material affects new contracts only.

## Required Closure Evidence

Before any backlog item is closed, attach or reference:

- Code changes.
- Migration changes if any.
- API request/response examples if endpoint changed.
- Accounting mapping examples if money moves.
- Approval and audit evidence behavior.
- Feature tests and forbidden-state tests.
- Report output or export fixture where reporting changed.
