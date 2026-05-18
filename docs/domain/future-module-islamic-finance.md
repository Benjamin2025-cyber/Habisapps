# Future Module: Islamic Finance

Stakeholder source: section 29, `Finance Islamique`.

## Stakeholder Intent

The stakeholder wants an Islamic finance offering governed by Sharia principles:

- no interest/riba;
- no excessive uncertainty or speculation;
- profit and loss sharing;
- financing only licit activities;
- financing linked to real goods, services, or projects.

They list:

- Murabaha;
- Ijara / Ijara wa Iqtina;
- Salam;
- Istisna'a;
- Mudaraba;
- Musharaka;
- Islamic current accounts;
- Islamic savings accounts;
- Islamic investment accounts.

## Standards Boundary

Islamic finance cannot be implemented as a renamed conventional loan. It needs Sharia governance, product-specific contracts, asset traceability, and different accounting.

Useful standards sources for discovery:

- AAOIFI Sharia standards: https://aaoifi.com/shariah-standards-3/?lang=en
- AAOIFI accounting standards: https://aaoifi.com/accounting-standards-2/?lang=en

AAOIFI lists Sharia and accounting standards for products such as Murabaha, Musharakah, Mudaraba, and Murabaha/deferred payment sales. These standards should guide legal/compliance review, but local regulatory acceptance must also be confirmed.

## Architecture Decision

Islamic financing must be a separate product family, not a flag on conventional `loans`.

Reason:

- Conventional loans calculate interest.
- Islamic products have sale, lease, partnership, or manufacturing contracts.
- Islamic products require asset or project traceability.
- Profit may be fixed markup, lease rental, or profit-sharing, depending product.
- Loss treatment differs by contract.
- Accounting differs by contract.

Recommended boundary:

- Keep conventional loan tables for conventional loans.
- Add Islamic product and contract tables for Islamic finance.
- Share clients, documents, agencies, accounts, approvals, audit, and accounting engine where appropriate.

## Product Types

### Murabaha

Stakeholder example:

- Purchase cost: 100,000 XAF.
- Added costs: 20,000 XAF.
- Repayment price: 120,000 XAF.

Implementation interpretation:

- The institution purchases or constructively owns the asset before selling to the client.
- Sale price and markup are disclosed upfront.
- Receivable is based on sale price, not interest on money.

Acceptance criteria:

- Asset is identified.
- Cost, allowed added costs, markup, and sale price are snapshotted.
- Contract approval happens before disbursement/sale posting.
- Schedule collects sale receivable.
- No interest fields are used.

### Ijara / Ijara Wa Iqtina

Stakeholder example:

- Asset cost: 250,000 XAF.
- Duration: 5 months.
- Monthly payment: 52,000 XAF.
- Residual: 30,000 XAF.
- Total: 290,000 XAF.

Implementation interpretation:

- The institution owns the asset and leases it to the customer.
- Ownership transfer, if any, must be contractually defined.

Acceptance criteria:

- Asset ownership is recorded.
- Rental schedule is separate from sale residual.
- Residual/transfer option is explicit.
- Asset loss/damage responsibilities are contract-defined.

### Salam

Implementation interpretation:

- Institution pays upfront for goods delivered later.
- Useful for agricultural/commercial production.

Acceptance criteria:

- Goods specifications, quantity, delivery date, and delivery place are explicit.
- Delivery and settlement workflow exists.
- Non-delivery handling is approved by Sharia/legal owners.

### Istisna'a

Implementation interpretation:

- Construction/manufacturing contract for an asset not yet existing.

Acceptance criteria:

- Specifications, milestones, inspections, delivery, and acceptance are tracked.
- Supplier/contractor payments are staged.
- Customer billing follows approved contract.

### Mudaraba

Stakeholder example:

- Financing: 500,000 XAF.
- Duration: 5 years.
- Forecast result: 200,000 XAF/year.
- Profit split: 60% microfinance / 40% entrepreneur.

Implementation interpretation:

- Institution provides capital.
- Client manages business.
- Profit sharing follows agreed ratio.
- Loss is borne by capital provider except misconduct/negligence/breach.

Acceptance criteria:

- Profit reports and evidence are collected.
- Profit distribution is calculated by agreed ratio.
- Loss/misconduct handling requires approval workflow.

### Musharaka

Stakeholder example:

- Capital: 500,000 XAF, 250,000 each.
- Forecast profit: 100,000 XAF/year.
- Profit split: 70% startup / 30% microfinance.

Implementation interpretation:

- Parties contribute capital.
- Profit split may differ from capital ratio if contractually agreed.
- Loss follows capital participation unless legally approved otherwise.

Acceptance criteria:

- Partner contributions are recorded.
- Profit/loss distribution logic is contract-specific.
- Exit, buyout, and impairment rules are defined.

## Sharia Governance

Required controls:

- Sharia board or approved compliance reviewers.
- Product approval before launch.
- Contract approval before use.
- Haram activity screening.
- Interest/riba control: Islamic products must not use conventional interest formulas.
- Audit trail for Sharia approvals.

## Accounting Impact

Separate mappings are required for:

- Murabaha inventory/asset purchase;
- Murabaha receivable;
- deferred profit recognition if applicable;
- Ijara assets and rental income;
- Salam advance purchase;
- Istisna'a work-in-progress;
- Mudaraba/Musharaka investment accounts;
- profit distribution;
- Zakat where applicable.

## Backlog

1. Sharia governance discovery.
2. Regulatory/legal validation.
3. Islamic product family ADR.
4. Asset/project registry.
5. Murabaha MVP.
6. Ijara MVP.
7. Profit-sharing products after reporting and accounting controls are mature.
8. Islamic accounts.
9. Sharia audit reports.

