# ADR: Islamic Finance Architecture

## Status

Accepted (2026-05-20)

## Context

Islamic finance products must comply with Sharia law. They cannot involve interest (riba), excessive uncertainty (gharar), or prohibited activities. The existing conventional loan module (with interest, penalty formulas, and amortization schedules) must not be repurposed for Islamic products.

The AAOIFI Sharia and accounting standards serve as the authoritative reference.

## Decision

### 1. MVP Product: Murabaha

Murabaha (cost-plus sale) is the MVP product. It is an asset-backed deferred-payment sale where:

- The institution acquires a tangible asset (or accepts as if acquired).
- The institution sells the asset to the client at cost plus an agreed markup.
- The sale price is collected in deferred installments.
- No interest accrues; the markup is fixed at contract inception.

Ijara, Musharaka, and Mudaraba are explicitly deferred (see G5).

### 2. Product-Only Implementation (No Contract Templates)

MVP covers product-level Sharia approval. Contract templates are deferred.

- An `islamic_products` record stores the product definition with `status: draft|approved|inactive`.
- Products require a Sharia compliance review (`islamic_compliance_reviews`) before they can be used.

### 3. No Interest Fields

Islamic financing records must not reference interest rates, interest calculations, or conventional loan schedule tables. The `islamic_financings` table records `purchase_cost_minor`, `allowed_costs_minor`, `markup_minor`, and `sale_price_minor`. The profit is a fixed markup, not an interest rate applied over time.

### 4. Separate Receivable Schedule

Islamic financing installments use a dedicated `islamic_financing_installments` table, not `loan_schedule_lines`. The installments table tracks the receivable portion of the deferred sale price only. No interest, no penalty formulas.

### 5. Accounting Boundary

Murabaha uses operation mappings scoped to module `islamic_finance`:

- `murabaha_receivable` — debit: receivable from client (full sale price)
- `murabaha_payable` — credit: purchase cost plus allowed costs payable to supplier/cost accounts
- `murabaha_profit` — credit: deferred profit / income

Mappings must be configured before financial posting. Missing mappings block the workflow.

### 6. Sharia Approval Roles

- `platform-admin` can create products, request compliance reviews, and approve/review.
- Maker-checker enforcement: the user who requests a compliance review cannot approve it.

### 7. Asset Registry

Assets linked to financings use `islamic_financed_assets` with ownership status transitions (`pending`, `owned_by_institution`, `transferred_to_client`). Status transitions are audited through the `updated_at` timestamps and security audit events.

### 8. Cameroon MVP Currency And Agency Boundary

The MVP is XAF only. Multi-currency Islamic finance is deferred until accounting, regulatory reporting, and Sharia review can explicitly cover it.

Clients must belong to the financing agency. Agency-scoped Islamic products can only be used by their agency; products with no agency are treated as institution-wide templates.

Islamic financing references to clients are restricted on delete. A client removal must not cascade-delete financial contracts.

### 9. No is_islamic Flag

We do not add an `is_islamic` flag to conventional loan products or loans. Islamic products are a separate domain with separate tables, controllers, and workflows.

## Consequences

- Clean separation from conventional lending prevents accidental interest calculation.
- Dated product Sharia approval enables governance.
- Dedicated tables and mappings protect auditability.
- Future Ijara/Musharaka/Mudaraba products can follow the same pattern: separate product type, separate accounting mappings, dedicated features.
- The deferred backlog (G5) remains a discovery-only track with no partial tables.

## References

- AAOIFI Sharia Standard (Murabaha): sale-based deferred payment, no interest.
- AAOIFI FAS 28 (replacing FAS 2 & FAS 20): governs Murabaha and other deferred payment sales. Available at https://aaoifi.com/accounting-standards-2/?lang=en
- Under AAOIFI FAS 2 Para 8: profit is recognized at contract time; deferred profit is recorded as a contra-asset to receivables (FAS 2 Para 9).
- Under AAOIFI FAS 2 Para 16: deferred profit is amortized to income on a time-proportionate basis. Straight-line is acceptable for ≤12-month contracts.
- AAOIFI FAS 28 Para 5-28 provides the accounting rules for initial recognition, subsequent measurement, and derecognition of inventory and Murabaha receivables.
- Research sources confirming journal entry pattern for Murabaha:
  - Dr Murabaha Receivable (at sale price)
  - Cr Revenue / Asset Inventory (at cost)
  - Cr Deferred Profit (markup)
  - Source: MDPI Journal of Risk and Financial Management, "An Overview of Islamic Accounting: The Murabaha Contract" (2023), https://www.mdpi.com/1911-8074/16/7/335
