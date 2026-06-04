# New Feature Implementation Backlogs

Purpose: execution-ready backlogs for the stakeholder sections that were added after the formula questions. These tickets are written for an implementation agent that must not invent financial logic.

## Official Source Anchors

Use these sources as guardrails. They do not replace legal/compliance review, but they prevent implementation from being based on memory or guesswork.

| Domain | Official source anchor | Implementation consequence |
|---|---|---|
| Insurance / bancassurance | CIMA Code des assurances: https://cima-afrique.org/wp-content/code-cima/fr/CODEDESASSURANCESDESETATSMEMBRES.html | Treat insurance as a regulated product/intermediary domain. Do not invent risk-carrier, broker, commission, or claim-payment accounting rules without business-model confirmation. |
| Currency exchange | BEAC foreign-exchange regulations page: https://www.beac.int/p-des-changes/reglements/ | Treat this as regulated manual currency exchange, not generic multi-currency banking. Do not implement production exchange operations without authorization, KYC/AML, reporting, stock, and rate controls. |
| Currency exchange reporting detail | BEAC Instruction 011/GR/2019 was reachable during drafting at `https://www.beac.int/wp-content/uploads/2016/10/Instruction-n%C2%B0-011-GR-2019_3.pdf`, but this is a brittle PDF deep link. If unreachable, find it from BEAC's Politique des changes / Instructions section. | Capture identity, currency, amount, reference/applied rate, and reporting fields. Do not rely on the pasted PDF URL as the only source. |
| EMF regulatory reporting | BEAC/COBAC microfinance instructions: https://www.beac.int/supervision-bancaire/microfinance/instructions-de-microfinance/ | Report definitions, periodicity, and layouts must be versioned from official COBAC/EMF sources. Do not invent report layouts. |
| Accounting framework | OHADA AUDCIF/SYSCOHADA: https://www.ohada.org/en/publication-of-a-new-uniform-act-on-accounting-law-and-financial-reporting-uaafr/ | Accounting/reporting work must respect OHADA/SYSCOHADA and EMF regulatory chart alignment. |
| HR/payroll social obligations | CNPS employer pages: https://www.cnps.cm/index.php/fr/ and `https://www.cnps.cm/fr/employeurs/obligations-de-lemployeur1.html` | Employer is responsible for declaring personnel/salaries and paying employer and employee social contributions. Do not hardcode payroll formulas without dated legal configuration. CNPS page paths change; navigate from the official CNPS site if a deep link fails. |
| Islamic finance Sharia standards | AAOIFI Sharia standards: https://aaoifi.com/shariah-standards-3/?lang=en | Islamic finance is not renamed interest lending. Products need Sharia review and product-specific contracts. |
| Islamic finance accounting standards | AAOIFI accounting standards: https://aaoifi.com/accounting-standards-2/?lang=en | Accounting differs by product family, such as Murabaha, Musharaka, Mudaraba, and deferred payment sales. |

## Global Execution Rules

These rules apply to every ticket.

- Do not invent statuses, formulas, rates, tax/social contribution rules, accounting entries, or regulatory report layouts.
- Do not treat a technical foundation slice as feature completion unless the ticket explicitly says the business feature can stop there.
- When the stakeholder requested a full module, backlog work must keep adding tickets until the full business lifecycle is covered: configuration, creation, approval, posting, correction/reversal, reporting/export, audit, permissions, and operational exceptions.
- If a ticket intentionally defers part of a requested module, it must name the deferred work as a required later ticket in the same backlog.
- Do not use internal numeric IDs in API contracts. Use public IDs.
- Do not silently create ledger accounts or operation mappings.
- Do not post financial entries when required operation mappings are missing.
- Do not hard-delete posted financial, payroll, insurance, exchange, or regulatory records.
- Do not weaken existing loan repayment allocation, formula gates, teller controls, or journal immutability.
- Do not add frontend code in these tickets.
- Every financial workflow must run in a DB transaction and lock the rows it mutates.
- Every posted financial workflow must support reversal/correction as a separate workflow.
- Every ticket must include targeted tests and run PHPStan before handoff.

## Execution Order

1. Bancassurance completion.
2. Notifications and alerts completion.
3. Currency exchange.
4. EMF/COBAC reporting completion.
5. Dashboards.
6. HR/payroll.
7. Islamic finance.

Reasoning:

- Bancassurance already has implemented schema/API foundation and loan-insurance integration.
- Notifications support loans, insurance, HR, reports, and later dashboards.
- Currency exchange touches cash, rates, accounting, and BEAC controls, so it should follow stronger operational foundations.
- EMF/COBAC reporting must sit on reliable accounting mappings and posted journals.
- Dashboards should read governed reporting views, not raw incomplete workflows.
- HR/payroll has a separate legal/payroll scope and should not block client-facing finance work.
- Islamic finance is a separate finance architecture and should come after explicit Sharia/legal design.

## Backlog A: Bancassurance Completion

Current state: `insurance_partners`, `insurance_products`, `insurance_product_coverages`, `insurance_subscriptions`, `insurance_premium_assessments`, `insurance_premium_payments`, `insurance_claims`, and `insurance_claim_documents` exist. Basic insurance APIs exist. V1 loan setup does not create loan-linked borrower insurance premium assessments or account collections; any loan-linked borrower insurance integration requires a separate future bancassurance contract.

Completion standard:

- This backlog is not complete when the basic API foundation is implemented.
- It is complete only when the insurance product lifecycle, recurring premiums, renewals, endorsements, cancellations, reversals, remittances/commissions, claims, settlement, reports, exports, permissions, audit, and operational exception workflows are implemented and tested.
- All stakeholder-requested product families must be configurable product types, not hardcoded one-off flows: borrower, health, life, savings, agricultural, home, professional/commercial multi-risk, automobile/motorcycle, school, travel, funeral, and mobile/equipment insurance.

### A1. Standalone Premium Assessment Endpoint

Decision:

- Create premium assessments for standalone insurance subscriptions.
- This ticket does not collect money.

Scope:

- Add endpoint: `POST /api/v1/insurance-subscriptions/{subscriptionPublicId}/premium-assessments`.
- Use existing `insurance_premium_assessments`.

Do not:

- Do not generate recurring premiums automatically.
- Do not collect payment.
- Do not create or alter insurance products.
- Do not post accounting.

Business rules:

1. Load active subscription by public ID.
2. Lock subscription.
3. Reject inactive/cancelled/expired subscription.
4. Require `premium_amount_minor > 0`, `due_on`, optional `base_amount_minor`, optional `rate`.
5. Currency must equal subscription currency.
6. Create one assessment with status `assessed`.
7. Return assessment public ID, due date, amount, currency, and status.

Tests:

- Creating an assessment for an active subscription succeeds.
- Inactive subscription is rejected.
- Currency mismatch is rejected.
- Zero/negative premium is rejected by validation/database constraint.

Acceptance criteria:

- No journal entry is created.
- Assessment is reloadable by public ID.
- Existing loan insurance assessment tests still pass.

### A2. Standalone Premium Collection From Customer Account

Decision:

- Collect a standalone insurance premium from an active same-client customer account.
- Teller cash collection is separate ticket A3.

Scope:

- Add endpoint: `POST /api/v1/insurance-premium-assessments/{assessmentPublicId}/collect-from-account`.
- Use existing `insurance_premium_payments`.
- Use operation code `insurance_premium_collection`.

Do not:

- Do not debit an account belonging to another client.
- Do not collect an already paid assessment.
- Do not partially collect in this ticket.
- Do not post without operation mapping.
- Do not auto-create operation mapping or ledger accounts.

Business rules:

1. Lock premium assessment.
2. Load subscription and client.
3. Lock customer account.
4. Reject if assessment status is not `assessed`.
5. Reject if customer account client differs from subscription client.
6. Reject if account currency differs from assessment currency.
7. Reject if available balance is less than premium.
8. Create journal entry using configured `insurance_premium_collection` mapping.
9. Debit customer account/ledger side and credit configured premium income/payable ledger side based on mapping.
10. Create `insurance_premium_payments`.
11. Update assessment status to `paid` and attach journal entry.

Tests:

- Successful collection creates payment, journal entry, and paid assessment.
- Missing operation mapping fails before account balance changes.
- Insufficient balance fails before journal/payment creation.
- Wrong client account is rejected.
- Duplicate collection is rejected.

Acceptance criteria:

- Operation is atomic.
- Response includes assessment and payment public IDs.
- Account balance and journal lines reconcile to payment amount.

### A3. Standalone Premium Collection From Teller Cash

Decision:

- Collect a standalone insurance premium through an open teller session.

Scope:

- Add endpoint: `POST /api/v1/insurance-premium-assessments/{assessmentPublicId}/collect-cash`.
- Reuse teller session/till controls.

Do not:

- Do not bypass teller session status.
- Do not mix this with account debit.
- Do not allow collection from closed teller session.

Business rules:

1. Lock assessment and teller session.
2. Reject non-`assessed` assessment.
3. Require open teller session with active till.
4. Currency must be `XAF` unless teller supports another currency explicitly.
5. Create teller cash transaction and journal entry.
6. Create payment and mark assessment paid.

Tests:

- Open teller session can collect.
- Closed session rejected.
- Missing operation mapping rejected.
- Duplicate collection rejected.

Acceptance criteria:

- Teller balance, payment row, and journal entry are consistent.

### A4. Claim Evidence Attachment API

Decision:

- Attach existing document records to an insurance claim.

Scope:

- Add endpoint: `POST /api/v1/insurance-claims/{claimPublicId}/documents`.
- Use existing `insurance_claim_documents`.

Do not:

- Do not expose raw document paths.
- Do not attach documents from another client/agency.
- Do not create claim decisions.

Business rules:

1. Load claim by public ID.
2. Load document by public ID.
3. Reject if document does not belong to the claim client/agency or allowed scope.
4. Insert `insurance_claim_documents` with document type.
5. Duplicate claim/document pair returns validation error or idempotent success, but must not duplicate rows.

Tests:

- Attach valid document.
- Reject cross-client/cross-agency document.
- Duplicate attachment does not create duplicate row.

Acceptance criteria:

- Response returns document public ID and document type only.

### A5. Maker-Checker Claim Decision Workflow

Decision:

- Replace direct operational claim finalization with maker-checker requests for approve/reject/settle.

Scope:

- Use existing approval/request pattern if present.
- If no reusable table exists, create minimal `insurance_claim_decisions` table.

Do not:

- Do not allow requester to approve own decision.
- Do not settle claim accounting in this ticket.
- Do not delete old decision history.

Business rules:

1. Maker creates decision request for `approve`, `reject`, or `settle`.
2. Checker approves/rejects request.
3. Claim status changes only after checker approval.
4. Approved/rejected/settled status is timestamped and audited.
5. `settle` requires indemnified amount.

Tests:

- Maker request does not change claim status.
- Checker approval changes status.
- Same user cannot maker-check own request.
- Rejected decision request leaves claim unchanged.

Acceptance criteria:

- Direct claim decision endpoint either uses maker-checker or is disabled for production roles.

### A6. Claim Settlement Accounting

Decision:

- Settlement is a financial event separate from status change.

Scope:

- Add endpoint: `POST /api/v1/insurance-claims/{claimPublicId}/settlement-posting`.
- Use configured operation mapping `insurance_claim_settlement`.

Do not:

- Do not assume the institution is the risk carrier.
- Do not post if business model is not configured for the product.
- Do not pay client from cash/account without explicit configured payment source.

Business rules:

1. Require claim status eligible for settlement.
2. Require business model on product: `broker`, `collector`, `risk_carrier`, or configured equivalent.
3. If broker/collector, post receivable/payable according to configured mapping.
4. If risk carrier, post claim expense/payable according to configured mapping.
5. Store journal entry on claim.

Tests:

- Missing business model rejected.
- Missing mapping rejected.
- Settlement posts correct amount and links journal.
- Duplicate settlement rejected.

Acceptance criteria:

- Accounting behavior is driven by product rules and operation mappings, not hardcoded ledger accounts.

### A7. Insurance Reports

Decision:

- Add API reporting endpoints backed by existing insurance tables.

Scope:

- Reports: active subscriptions, premiums due/paid, unpaid premiums, claims by status, expiring coverage.

Do not:

- Do not calculate loss ratio until claim settlement accounting exists.
- Do not expose cross-agency data to agency-scoped roles.

Tests:

- Reports filter by agency, product, partner, period, and status.
- Agency role cannot see other agency data.

Acceptance criteria:

- Report totals reconcile with table-level seeded fixtures.

### A8. Insurance Product Catalog And Rule Versioning

Decision:

- Implement configurable insurance product definitions for every stakeholder-requested product family.
- Premium rules must be versioned and effective-dated.

Do not:

- Do not hardcode product-specific premium formulas in controllers.
- Do not allow overlapping active rule versions for the same product.
- Do not allow inactive or unapproved products to accept subscriptions.

Business rules:

1. Product types include borrower, health, life, savings, agricultural, home, professional/commercial multi-risk, automobile/motorcycle, school, travel, funeral, and mobile/equipment.
2. Each product has one or more effective-dated premium rule versions.
3. A premium rule version records calculation type, base, rate/fixed amount, caps/floors, taxes/fees if configured, source/contract reference, and approver.
4. New subscriptions and premium assessments snapshot the exact rule version used.
5. Product changes do not mutate existing subscription/premium snapshots.

Tests:

- Every requested product family can be created as a configurable product type.
- Overlapping active rule versions are rejected.
- Premium assessment snapshots the rule version.
- Inactive/unapproved product cannot be subscribed to.
- Existing subscription remains unchanged after a later rule version is approved.

Acceptance criteria:

- Product configuration is data-driven.
- Product/rule responses expose public IDs only.
- Product rule approval is audited.

### A9. Recurring Premium Schedules And Renewal Lifecycle

Decision:

- Implement recurring premium generation for products whose contract requires periodic premiums.
- Implement coverage renewal instead of forcing manual subscription recreation.

Do not:

- Do not create duplicate assessments for the same subscription period.
- Do not silently renew expired coverage without configured renewal terms.
- Do not mark coverage active when required premiums are unpaid unless a waived-premium approval exists.

Business rules:

1. Product rule defines premium frequency: one-time, monthly, quarterly, annual, or contract-specific schedule.
2. Subscription activation creates or schedules the first premium assessment.
3. Batch generation creates due assessments for upcoming periods idempotently.
4. Renewal snapshots new coverage dates, insured amount, and product rule version.
5. Grace periods, lapses, and reinstatements are explicit statuses and audited.

Tests:

- Recurring schedule generation is idempotent.
- Renewal creates new coverage period without mutating old period.
- Expired/lapsed subscription cannot accept claims for dates outside valid coverage.
- Waived-premium activation requires approval.

Acceptance criteria:

- Premium due reports include generated recurring assessments.
- Coverage status is derived from dates, payment/waiver state, and lifecycle status.

### A10. Endorsements, Cancellations, Refunds, And Reversals

Decision:

- Implement operational changes after subscription creation: endorsements, cancellations, refunds, and financial reversals.

Do not:

- Do not edit posted premium payments or claim settlement journals in place.
- Do not delete subscriptions with posted financial activity.
- Do not calculate refunds without a configured refund rule or manual approval.

Business rules:

1. Endorsement changes coverage amount, beneficiary/asset details, or coverage dates through an approved change record.
2. Cancellation records effective date, reason, approval, and refund treatment.
3. Premium payment reversal creates a reversing journal and reopens/voids the assessment according to status rules.
4. Claim settlement reversal creates a reversing journal and moves the claim to an approved correction status.
5. Refunds post through configured operation mappings and never bypass accounting.

Tests:

- Endorsement snapshots before/after values.
- Cancellation blocks future claims after effective date.
- Premium reversal posts equal-and-opposite journal lines.
- Claim settlement reversal posts equal-and-opposite journal lines.
- Posted records cannot be hard-deleted.

Acceptance criteria:

- All correction workflows are auditable and public-ID based.
- Reports exclude or separately show reversed/voided financial activity.

### A11. Insurer Remittance And Commission Accounting

Decision:

- Complete the insurer-side business model: broker/distributor/collector/risk carrier behavior by product.

Do not:

- Do not assume all premiums are institution income.
- Do not assume the microfinance pays every claim from its own risk.
- Do not post remittance or commission without product business-model configuration.

Business rules:

1. Product business model is required: broker, distributor, premium collector, risk carrier, or explicit configured equivalent.
2. Premium collection splits gross premium into insurer payable, commission income, taxes/fees, and institution income only according to configured rules.
3. Insurer remittance batches group payable amounts by partner, product, period, and currency.
4. Remittance approval posts accounting and marks included premium payments as remitted.
5. Commission reports reconcile to posted premium and remittance records.

Tests:

- Missing business model blocks premium collection/remittance.
- Broker product posts insurer payable and commission income according to configured split.
- Risk-carrier product posts premium income and claim expense according to configured mapping.
- Remittance batch cannot include already remitted payments.
- Commission report reconciles to journal lines.

Acceptance criteria:

- Accounting behavior is fully configuration-driven.
- Insurer balances can be reconciled by partner/product/period.

### A12. Complete Claim Lifecycle And Evidence Controls

Decision:

- Implement the full operational claim lifecycle, not just basic decision requests.

Do not:

- Do not allow claim settlement before required evidence and approval gates are satisfied.
- Do not expose raw document storage paths.
- Do not allow claims outside active coverage dates.

Business rules:

1. Claim statuses cover draft/intake, pending review, evidence requested, approved/validated, rejected, settlement approved, settled, corrected/reversed, and closed.
2. Required evidence can be configured per product/claim type.
3. Claim intake validates incident date against coverage period.
4. Decision maker-checker records reason codes, indemnified amount, coverage limits, deductible/excess if configured, and reviewer.
5. Settlement posting is separate from claim approval and uses product business model mappings.
6. Notifications are queued for claim decisions without exposing sensitive details.

Tests:

- Claim outside coverage period is rejected.
- Missing required evidence blocks approval/settlement.
- Settlement amount cannot exceed configured coverage/approved indemnity.
- Rejected claim cannot be settled.
- Claim decision notification outbox row is created.

Acceptance criteria:

- Claim lifecycle is inspectable from API without internal IDs or raw file paths.
- Claim reports reconcile approved, rejected, pending, and settled statuses.

### A13. Insurance Exports And Regulatory/Partner Reporting

Decision:

- Implement exportable insurance reports for operations, insurer partners, and management.

Do not:

- Do not call a report complete if it only returns dashboard aggregates.
- Do not expose cross-agency data to agency-scoped users.
- Do not export raw document paths or unnecessary PII.

Business rules:

1. Export formats include CSV and PDF or the project's standard export mechanism.
2. Reports cover active subscriptions, premiums due/paid, unpaid premiums, claims by status, loss ratio, commissions, remittances, cancellations/refunds, and expiring coverage.
3. Exports record generated-by, generated-at, filters, checksum, and source query version.
4. Agency-scoped users only export their agency data.

Tests:

- Each report exports with expected headers and totals.
- Export checksum changes when source data changes.
- Agency user cannot export another agency's insurance data.
- PII is limited to fields explicitly required for the report.

Acceptance criteria:

- Exported totals reconcile to API report totals and posted journal lines where financial.

### A14. Insurance Permissions, Audit, And Operational Rollout Controls

Decision:

- Add production-grade permission, audit, and rollout controls for the complete module.

Do not:

- Do not let generic platform-admin checks be the only long-term control for insurance operations.
- Do not allow direct production use of partially configured products.

Business rules:

1. Permissions separate product setup, subscription creation, premium collection, claim intake, claim review, claim settlement, remittance, reversal, and report export.
2. Every financial or claim-status transition records a security audit event.
3. Product readiness checklist blocks activation until partner, rule version, business model, accounting mappings, evidence requirements, and report category are configured.
4. Feature rollout can disable new subscriptions for a product without affecting servicing of existing subscriptions.

Tests:

- Users without each permission are denied.
- Audit events are recorded for subscription activation, premium posting, claim decision, settlement, reversal, remittance, and export.
- Product activation fails until readiness checklist passes.
- Disabling new business blocks new subscriptions but keeps claims/premium servicing available.

Acceptance criteria:

- Bancassurance can be operated as a complete module with clear role separation and audit evidence.

### A15. Insurance Controller SRP Refactor

Decision:

- Refactor the insurance API so `InsuranceController` is only a transport adapter.
- Keep business decisions, transaction boundaries, accounting orchestration, report queries, and lifecycle transitions in dedicated application services/actions.

Do not:

- Do not add more insurance business rules directly to `InsuranceController`.
- Do not move code into a generic helper that simply hides the same mixed responsibilities.
- Do not change public API behavior, response payloads, permissions, audit events, accounting postings, or database state transitions during the refactor.
- Do not mark this ticket complete because new services exist while the controller still owns multi-record workflows.

Business rules:

1. Split insurance behavior by workflow ownership: product setup/readiness/rule versions, subscription lifecycle, premium assessment/collection/reversal, claim intake/evidence/decision/settlement, endorsements/cancellations/refunds, remittances, reports/exports, and serialization/payload mapping.
2. Each service/action owns its database transaction for the workflow it executes.
3. Controllers validate/authorize input, call one service/action, record only transport-level response handling where needed, and return serialized output.
4. Shared lookup, row conversion, and payload serialization move into named collaborators instead of remaining private controller helpers.
5. Existing focused insurance feature tests remain behaviorally unchanged, and new service-level tests cover the extracted workflow rules that no longer require HTTP.

Tests:

- Existing A1-A14 insurance feature tests still pass after extraction.
- Service-level tests cover at least premium collection, claim settlement/reversal, cancellation/refund, remittance approval, product activation readiness, and exports.
- PHPStan passes for the controller and all extracted insurance services/actions.
- A controller-size guard fails if `app/Http/Controllers/Api/V1/InsuranceController.php` grows beyond 500 lines after the refactor.

Acceptance criteria:

- `InsuranceController` contains no inline `DB::transaction(...)` calls.
- `InsuranceController` contains no direct `JournalEntry`, `JournalLine`, `TellerTransaction`, or operation-mapping writes.
- `InsuranceController` contains no report query builders and no export checksum logic.
- `InsuranceController` has no private row parsing helpers such as `rowString`, `rowInt`, or `jsonOrNull`.
- The controller is small enough to review as HTTP routing glue, not as the insurance domain implementation.

## Backlog B: Notifications And Alerts Completion

### B1. Notification Consent And Template Model

Decision:

- Store client notification consent and versioned message templates.

Do not:

- Do not send SMS in this ticket.
- Do not include full account numbers or sensitive balances by default.

Business rules:

1. Consent is per client, channel, category, and language.
2. Templates are versioned and statused.
3. Templates support variable allow-list only.

Tests:

- Consent opt-in/opt-out works.
- Inactive template cannot be used.
- Unknown variables rejected.

### B2. Outbound Notification Log

Decision:

- Create a provider-neutral outbox before integrating any SMS provider.

Do not:

- Do not call external SMS provider in tests.
- Do not store unmasked sensitive values in log body unless explicitly required.

Business rules:

1. Domain workflow creates notification outbox row with idempotency key.
2. Duplicate idempotency key does not duplicate messages.
3. Statuses: `pending`, `sent`, `failed`, `cancelled`.

Tests:

- Idempotency suppresses duplicates.
- Failed messages can be retried within limit.

### B3. Loan And Insurance Alert Producers

Decision:

- Generate alerts for loan due, loan overdue, insurance premium due, and claim decision.

Do not:

- Do not change loan penalty logic.
- Do not send messages directly from loan/insurance transaction.

Business rules:

1. Scheduled job identifies eligible events.
2. Outbox row is created only if consent exists.
3. Idempotency key includes domain object and event date.

Tests:

- Due loan creates one alert.
- Re-running job does not duplicate.
- No consent means no client message.

### B4. Report Deadline Alerts

Decision:

- Generate internal alerts for report due dates and failed scheduled reports.

Do not:

- Do not auto-submit regulatory reports.

Tests:

- Report due alert targets configured roles.
- Failed report alert is escalated.

## Backlog C: Currency Exchange

Current scope: counter currency exchange only. This is not a platform-wide multi-currency banking implementation.

### C1. Regulatory/Authorization Configuration Gate

Decision:

- Add a feature gate requiring explicit currency exchange authorization metadata before production operations.

Do not:

- Do not allow exchange transactions just because tables exist.
- Do not hardcode authorization numbers.

Business rules:

1. Store authorization reference, effective date, status, and supported agencies.
2. Exchange transaction endpoints reject if authorization is inactive/missing.

Tests:

- Missing authorization blocks transaction.
- Active authorization allows next validation stage.

### C2. Currency Reference Data

Decision:

- Add currency records used only by exchange module.

Do not:

- Do not make customer accounts or loans foreign-currency capable.

Business rules:

1. Currency has ISO code, label, precision, status.
2. `XAF` exists as base settlement currency.
3. Inactive currency cannot be used in new rates/transactions.

Tests:

- Active currency usable.
- Inactive currency rejected.

### C3. Exchange Rate Publication

Decision:

- Publish buy/sell rates with maker-checker and effective dating.

Do not:

- Do not rewrite rates used by posted transactions.
- Do not allow two active rates for same currency/direction/effective window.

Business rules:

1. Draft rate includes currency, direction, reference rate, margin, applied rate, effective time.
2. Checker approval activates rate.
3. Transaction snapshots applied rate and reference rate.

Tests:

- Draft rate not usable.
- Approved rate usable.
- Overlapping active rate rejected.

### C4. Dedicated Exchange Till And Stock

Decision:

- Track foreign-currency stock in dedicated exchange tills.

Do not:

- Do not reuse main XAF teller till as foreign-currency stock.
- Do not support customer foreign-currency deposits.

Business rules:

1. Exchange till belongs to agency/user.
2. Till has balances by currency.
3. Stock cannot go negative.

Tests:

- Stock increase/decrease works.
- Negative stock rejected.
- Main teller till cannot be selected for exchange stock.

### C5. Counter Exchange Transaction

Decision:

- Implement buy/sell foreign currency against `XAF`.

Do not:

- Do not support foreign-to-foreign exchange in this ticket.
- Do not bypass identity capture.
- Do not post if rate or stock is missing.

Business rules:

1. Require client public ID or walk-in identity snapshot.
2. Require identity document fields aligned with BEAC reporting needs.
3. Direction is `buy_foreign_currency` or `sell_foreign_currency`.
4. Use approved active rate.
5. Lock exchange till stock.
6. Create transaction, register entry, and journal entry.
7. Snapshot source amount, target amount, reference rate, applied rate, margin.
8. Correction uses reversal.

Tests:

- Buy transaction updates stock and accounting.
- Sell transaction checks stock.
- Missing identity rejected.
- Reversal restores stock and posts reversing journal.

### C6. Exchange Slip And Register

Decision:

- Generate immutable slip/register numbers.

Do not:

- Do not regenerate register sequence after posting.

Tests:

- Posted transaction has slip number and register number.
- Register export contains required fields for period.

### C7. Partner Bank Replenishment/Sale

Decision:

- Record buying/selling foreign-currency stock from/to partner bank.

Do not:

- Do not treat partner bank operation as customer exchange.

Tests:

- Replenishment increases stock.
- Sale decreases stock.
- Approval required.

### C8. End-Of-Day Exchange Reconciliation

Decision:

- Close exchange till by comparing counted and theoretical stock.

Do not:

- Do not close with unexplained variance.

Tests:

- Matching count closes.
- Variance blocks close.
- Approved adjustment posts correction.

## Backlog D: EMF/COBAC Regulatory Reporting Completion

### D1. Regulatory Source Registry

Decision:

- Store official source/version metadata for regulatory accounts and reports.

Do not:

- Do not invent report rows from memory.

Business rules:

1. Source has title, authority, effective date, file checksum, and imported by.
2. Report/account definitions point to source version.

Tests:

- Import requires source metadata.
- Source checksum is stored.

### D2. EMF Regulatory Account Loader

Decision:

- Load EMF account reference data from reviewed source file.

Do not:

- Do not manually type accounts in migrations except seed/test fixtures.

Tests:

- Parent/child hierarchy imports.
- Duplicate account code rejected.
- Inactive old account cannot be used for new mappings.

### D3. Mapping Completeness Gate

Decision:

- Block financial posting when operation mapping is missing.

Do not:

- Do not default to suspense accounts silently.

Tests:

- Missing mapping fails before journal creation.
- Complete mapping allows posting.

### D4. Report Definition Schema

Decision:

- Store report definitions as versioned formulas over ledger balances/journals.

Do not:

- Do not query arbitrary raw tables from report definitions.

Tests:

- Report definition validates allowed source fields.
- Published definition cannot be edited; create new version.

### D5. Regulatory Report Generation

Decision:

- Generate reproducible report runs from posted journals only.

Do not:

- Do not include draft journals.

Tests:

- Same period/source version produces same totals.
- Posted correction after report requires rerun/new run.

### D6. Report Review And Submission Metadata

Decision:

- Add maker-checker review and submission tracking.

Do not:

- Do not auto-submit to COBAC.

Tests:

- Approved report locks.
- Submission metadata stored with reference/channel.

## Backlog E: Dashboards

### E1. Reporting Read Models

Decision:

- Build dashboard read models from approved report/accounting sources.

Do not:

- Do not calculate portfolio/cash metrics differently from report definitions.

Tests:

- Dashboard totals reconcile to reporting fixtures.
- Data freshness timestamp present.

### E2. Operational Dashboard API

Metrics:

- portfolio outstanding;
- PAR30/PAR60/PAR90;
- collections;
- daily cash position;
- teller variances;
- insurance premiums due/paid;
- claims by status.

Do not:

- Do not expose cross-agency data.

Tests:

- Filters by agency, period, product, and status.
- Role permissions enforced.

### E3. Executive Summary API

Decision:

- Create aggregated executive view with limited sensitive detail.

Do not:

- Do not expose client names in executive aggregate endpoints.

Tests:

- Aggregate endpoint contains no client PII.

## Backlog F: HR And Payroll

### F1. Employee File

Decision:

- Implement HR employee records separate from application users.

Do not:

- Do not use `users` as employee master data.
- Do not expose document paths.

Tests:

- Employee public ID/matricule immutable.
- Agency history preserved.
- Permission gates protect salary fields.

### F2. Employee Documents

Decision:

- Link HR documents through existing document/media layer.

Do not:

- Do not store raw file paths in HR tables/API.

Tests:

- Attach document.
- Cross-employee/cross-agency access rejected.

### F3. Contract Lifecycle

Decision:

- Manage CDD/CDI contracts with versions and expiry alerts.

Do not:

- Do not rewrite old contracts on renewal.

Tests:

- Renewal creates new version.
- Expiry alert generated.

### F4. Leave, Absence, And Sanctions

Decision:

- Track leave/absence/sanction approvals before payroll impact.

Do not:

- Do not deduct salary from unapproved absence/sanction.

Tests:

- Approved absence can feed payroll facts.
- Draft/rejected absence does not affect payroll.

### F5. Payroll Formula Configuration

Decision:

- Store dated payroll formulas/rates for CNPS, taxes, bonuses, deductions, and employer charges.

Do not:

- Do not hardcode CNPS/IRPP/CAC rates in PHP.
- Do not calculate payroll without an active dated formula set.

Tests:

- Missing active formula set blocks payroll calculation.
- Payroll run snapshots formula version.

### F6. Payroll Run And Approval

Decision:

- Draft payroll calculates; approved payroll posts.

Do not:

- Do not post accounting from draft payroll.
- Do not edit approved payroll; use correction run.

Tests:

- Draft has no journal.
- Approval creates journal using mappings.
- Correction run reverses/adjusts prior run.

### F7. Payroll Reports And Declarations

Decision:

- Generate payslips, payroll journal, salary state, and declaration exports from approved payroll.

Do not:

- Do not submit to CNPS/tax systems automatically.

Tests:

- Unapproved payroll cannot generate final declarations.
- Export has checksum and source payroll run.

## Backlog G: Islamic Finance

Detailed implementation backlog moved to:

- `backlogs/islamic-finance-complete-implementation-backlog.md`

Completion standard:

- The Islamic finance domain is complete only when Sharia governance, Haram screening, interest controls, product readiness, accounting mappings, contract templates, evidence handling, reporting, and every stakeholder-requested Islamic account and product family are implemented or have dated business/legal/accounting/Sharia rejection.
- Required account families: Islamic current account, Islamic savings account, and Islamic investment account.
- Required financing and partnership families: Mourabaha, Ijara / Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, and Moucharaka.
- Each product family requires product-specific workflows, accounting, reports, authorization, audit, API validation, and forbidden-state tests.

Architecture documents:

- `docs/domain/islamic-finance.md`
- `docs/adr/islamic-finance-full-architecture.md`

## Soundness Review

The backlog above was reviewed against these risks:

| Risk | Review decision |
|---|---|
| Agent invents formulas | Tickets require configuration/source versions and explicitly forbid hardcoded regulatory formulas. |
| Agent posts money to wrong accounts | Financial tickets require operation mappings and fail-closed behavior. |
| Agent overbuilds multi-currency platform | Currency exchange tickets explicitly forbid foreign-currency customer accounts/loans and limit scope to counter exchange. |
| Agent treats Islamic finance as normal loans | Islamic tickets forbid interest fields and conventional loan reuse without ADR. |
| Agent bypasses approvals | Rate publication, claims, payroll, reports, and Sharia products require maker-checker or approval gates. |
| Agent creates unverifiable reports | Reporting tickets require source registry, versioned report definitions, posted journals only, and reproducible runs. |
| Agent exposes sensitive data | HR, documents, SMS, dashboards, and claim evidence tickets forbid raw paths and unnecessary PII. |
| Agent relies only on passing broad tests | Every ticket specifies targeted tests tied to the exact behavior. |

## Required Verification Before Any Ticket Is Marked Done

- Run targeted Laravel tests for the touched module.
- Run migration/schema integrity tests when a migration changes.
- Run `vendor/bin/phpstan analyse --memory-limit=1G`.
- Confirm no new internal numeric IDs are exposed in API responses.
- Confirm no posted financial record can be hard-deleted through the new workflow.
- Update the relevant domain doc if behavior changes.
