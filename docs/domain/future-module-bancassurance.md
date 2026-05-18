# Bancassurance Module

Stakeholder source: section 27, `Bancassurance`.

## Current Status

This module is no longer purely future scope. The codebase now contains a first bancassurance foundation:

- insurance partners;
- insurance products and coverages;
- insurance subscriptions;
- insurance premium assessments;
- insurance premium payments;
- insurance claims;
- insurance claim documents table;
- loan-linked borrower insurance premium assessment and collection;
- basic insurance API routes for partner, product, subscription, claim creation, and claim decision.

The remaining work is not basic schema creation. The remaining work is the deeper operational workflow: standalone premium scheduling and collection, claim evidence API, maker-checker claim decisions, claim settlement accounting, product reports, and final business-model accounting rules.

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

Loan insurance already exists as a loan setup charge concept and can now link to the insurance subscription/premium tables. Full bancassurance remains broader than borrower loan insurance:

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
- `insurance_premium_assessments`: implemented for subscription, optional loan, due date, amount, rate, currency, journal entry, and status.
- `insurance_premium_payments`: implemented for assessment, customer account/teller transaction, journal entry, amount, currency, and status.
- `insurance_claims`: implemented for subscription, incident date, claim type, claimed amount, status, indemnified amount, settlement date, and journal entry.
- `insurance_claim_documents`: table implemented for claim evidence linked to `documents`; API workflow remains to be completed.
- `insurance_claim_decisions`: not a separate table today. Current claim decisions are recorded by updating `insurance_claims`; a separate immutable decision/audit table remains recommended if claim governance needs stronger traceability.

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
- Loan-linked borrower insurance must link to the loan and snapshot loan principal at subscription time.
- Standalone subscriptions must define coverage start/end and insured amount.
- Subscription activation requires premium assessment or waived-premium decision.

### Premium Collection

Acceptance criteria:

- Premium may be deducted from a same-client customer account or collected through teller cash.
- Missing operation mappings fail before posting.
- Premium payment posts accounting and marks assessment paid.
- Overpayment remains on the customer account unless explicitly allocated.

Current status:

- Loan-linked borrower insurance premium collection from a customer account is implemented through the loan API.
- Standalone subscription premium assessment/collection APIs remain to be implemented.
- Teller-cash collection for standalone insurance premiums remains to be implemented.

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

- Claim creation and direct platform-admin claim decisions are implemented.
- Claim document linkage table exists, but the API for attaching/verifying evidence documents remains to be implemented.
- Maker-checker claim decision workflow remains to be implemented.
- Claim settlement accounting remains to be implemented before indemnification can be treated as a posted financial event.

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

1. Confirm insurance business model and insurer contracts.
2. Complete standalone premium assessment and collection workflow.
3. Add teller-cash premium collection for standalone policies.
4. Add claim evidence API on top of `insurance_claim_documents`.
5. Add maker-checker claim decision workflow.
6. Add claim settlement accounting and reversal workflow.
7. Add insurance reports.
8. Harden product premium rule versioning/effective dating.
9. Keep borrower loan insurance linked to the module without duplicating premiums.
