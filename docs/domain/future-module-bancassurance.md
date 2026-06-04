# Bancassurance Module

Stakeholder source: section 27, `Bancassurance`.

## Current Status

This module is no longer purely future scope, but it is not complete. The codebase now contains a first bancassurance foundation:

- insurance partners;
- insurance products and coverages;
- insurance subscriptions;
- insurance premium assessments;
- insurance premium payments;
- insurance claims;
- insurance claim documents table;
- standalone premium assessment and collection;
- teller-cash premium collection;
- claim evidence attachment;
- maker-checker claim decision requests;
- claim settlement accounting;
- insurance report endpoints;
- basic insurance API routes for partner, product, subscription, claim creation, claim evidence, claim decisions, premium collection, settlement posting, and reports.

The remaining work is required for complete bancassurance delivery, not optional hardening. The module should not be treated as complete until the product catalog, recurring premiums, renewals, endorsements, cancellations, refunds, reversals, insurer remittances/commissions, complete claim lifecycle, report exports, permissions, audit, rollout controls, and any explicit loan integration are implemented and tested.

## Stakeholder Intent

The stakeholder wants a full insurance module, not only borrower loan insurance. Requested products include:

- borrower insurance;
- health insurance;
- life insurance;
- savings insurance;
- agricultural insurance;
- home insurance;
- professional/commercial multi-risk insurance;
- automobile/motorcycle insurance;
- school insurance;
- travel insurance;
- funeral insurance;
- mobile/equipment insurance.

The requested module menu is:

- insurance products;
- subscriptions;
- premium payments;
- claims;
- insurer partners;
- insurance reports.

## Boundary

Loan assurance in v1 credit/loans is not bancassurance. It may exist only as a loan formula field such as `insurance_rate` / `insurance_amount_minor`; it does not create insurance subscription or premium records. Full bancassurance remains a separate insurance domain:

- it has insurer partners;
- standalone subscriptions not necessarily tied to loans;
- premium schedules and collections;
- claims and claim documents;
- claim decisions and indemnification tracking;
- reports by product, insurer, subscription, premium, and claims.

## Core Data Model

Implemented or expected entities:

- `insurance_partners`: implemented for insurer identity, contact, status, and linked ledger account.
- `insurance_products`: implemented for product code, type, covered risks, partner, premium rule fields, currency, status, and rules.
- `insurance_product_coverages`: implemented for structured product coverages.
- `insurance_subscriptions`: implemented for client, optional loan, product, coverage period, insured amount, currency, and status.
- `insurance_premium_assessments`: implemented for subscription, due date, amount, rate, currency, journal entry, and status. The schema can reference a loan, but v1 loan setup does not populate that link.
- `insurance_premium_payments`: implemented for assessment, customer account/teller transaction, journal entry, amount, currency, and status.
- `insurance_claims`: implemented for subscription, incident date, claim type, claimed amount, status, indemnified amount, settlement date, and journal entry.
- `insurance_claim_documents`: implemented for claim evidence linked to `documents`.
- `insurance_claim_decisions`: implemented for maker-checker claim decision requests.

## Workflows

### Product Setup

Acceptance criteria:

- Product code is unique.
- Product is linked to an active insurance partner.
- Covered risks are structured data.
- Premium rule is versioned and effective-dated.
- Inactive products cannot accept new subscriptions.

### Subscription

Acceptance criteria:

- Subscription must link to an active client.
- Future loan-linked borrower insurance, if approved, must link to the loan and snapshot loan principal at subscription time. This is not part of the v1 loan workflow.
- Standalone subscriptions must define coverage start/end and insured amount.
- Subscription activation requires premium assessment or waived-premium decision.

### Premium Collection

Acceptance criteria:

- Premium may be deducted from a same-client customer account or collected through teller cash.
- Missing operation mappings fail before posting.
- Premium payment posts accounting and marks assessment paid.
- Overpayment remains on the customer account unless explicitly allocated.

Current status:

- Standalone subscription premium assessment and customer-account collection are implemented.
- Teller-cash collection for standalone insurance premiums is implemented for `XAF`.

### Claims

Stakeholder statuses:

- pending;
- validated;
- rejected;
- indemnified.

Acceptance criteria:

- Claim requires subscription, incident date, claim type, and evidence documents.
- Validation/rejection requires maker-checker.
- Indemnification is a separate financial event, not just a status label.
- Claim documents are access-controlled and never exposed as raw paths.

Current status:

- Claim creation is implemented.
- Direct claim decisions are disabled; claim decisions now use maker-checker requests.
- Claim document attachment is implemented without exposing raw document paths.
- Claim settlement accounting is implemented as a separate posting workflow after settlement approval.

## Accounting Impact

Needed mappings:

- premium receivable;
- premium income or insurer payable depending business model;
- cash/account collection;
- commission income;
- claim receivable from insurer;
- claim indemnification payable to client;
- reversals.

Open business decision:

- Is the microfinance acting as broker, policy distributor, premium collector, risk carrier, or a mix by product?

This decision changes accounting.

## Reports

Minimum reports:

- active subscriptions by product/partner;
- premiums due and paid;
- unpaid premiums;
- claims by status;
- claims loss ratio by product/partner;
- commissions receivable/payable;
- expiring coverage.

## Backlog

1. Complete product catalog and rule versioning for all stakeholder-requested insurance families.
2. Confirm and configure insurance business model and insurer contracts per product.
3. Add recurring premium schedule generation where product contracts require it.
4. Add renewal, lapse, reinstatement, and waiver workflows.
5. Add endorsement, cancellation, refund, and correction workflows.
6. Add reversal workflows for premium collections and claim settlement postings.
7. Add insurer remittance and commission accounting.
8. Complete claim lifecycle with required evidence controls and coverage-date validation.
9. Add final report export formats with checksums and source versions.
10. Add module-specific permissions, audit gates, and product readiness controls.
11. Define an explicit future borrower-insurance integration contract before linking loans to bancassurance premium workflows.
