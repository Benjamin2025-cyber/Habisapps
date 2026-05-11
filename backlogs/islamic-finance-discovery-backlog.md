# Islamic Finance Discovery Backlog

Source trigger: Section 29 of `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`.

Status: newly discovered stakeholder module. The current migration layer gives this domain a persistence foundation, but the stakeholder note is not enough to implement Islamic finance products safely.

Reference standards found during research:

- AAOIFI Shari'ah Standards include specific standards for Murabahah, Ijarah, Salam, Istisnaa, Musharakah/Sharikah, and Mudarabah: https://aaoifi.com/shariah-standards-3/?lang=en
- AAOIFI accounting standards include product-specific accounting standards such as Murabaha and deferred payment sales, Ijarah, deferred delivery sales for Salam and Istisna, participatory ventures, investment accounts, and Islamic windows: https://aaoifi.com/accounting-standards-2/?lang=en
- IFSB material stresses that Shariah governance requires independent oversight, pronouncements/resolutions, internal Shariah compliance review or audit, and external annual Shariah review or audit: https://www.ifsb.org/wp-content/uploads/2023/10/IFSB-21-December-2018_En.pdf

## What The Stakeholder Provided

- High-level principles: no interest, avoid excessive uncertainty/speculation, share profit/loss, finance licit activities, link operations to real assets.
- Product names: Mourabaha, Ijara / Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, Moucharaka.
- Account names: Islamic current, savings, and investment accounts.
- Implementation themes: product configuration, accounting setup, Sharia Board validation, blocking Haram operations, asset traceability.
- Example calculations for Murabaha, Ijara, Moudaraba, and Moucharaka.

## Why This Is Not Enough For Implementation

- Each product is a different contract type, not a variant of a normal interest-bearing loan.
- Accounting differs by product and may need AAOIFI or local regulatory alignment.
- Shariah approval is a workflow and governance requirement, not only a status field.
- Asset ownership, transfer, delivery, leasing, profit-sharing, loss allocation, and non-compliance handling must be specified before business logic is written.
- The stakeholder note does not define local regulatory requirements for offering an Islamic finance window in this microfinance context.

## Migration Coverage Already Added

- `islamic_products`
- `islamic_financings`
- `islamic_financed_assets`
- `islamic_profit_sharing_terms`
- `islamic_compliance_reviews`

This is enough for discovery-safe persistence. It is not enough to claim product readiness.

## Required Decisions Before Implementation

- [ ] Decide whether Islamic finance is in the first release or only future discovery scope.
- [ ] Identify the governing Shariah authority or product approver.
- [ ] Decide whether AAOIFI standards are the reference baseline, and whether local CEMAC/COBAC rules add constraints.
- [ ] For each product, document lifecycle states, accounting entries, documents, approvals, and cancellation/non-compliance handling.
- [ ] For Murabaha, define purchase request, institution asset purchase, cost disclosure, markup, resale, delivery, repayment, default, and early settlement.
- [ ] For Ijara, define asset ownership, lease schedule, residual value, maintenance responsibility, transfer-of-title rules, and termination.
- [ ] For Salam and Istisna, define delivery obligations, parallel contracts, supplier/client roles, delivery failures, and inventory/asset treatment.
- [ ] For Moudaraba and Moucharaka, define capital contributions, profit ratio, loss allocation, periodic profit recognition, reporting, and exit.
- [ ] Define Haram activity screening and the exact data needed for financed-activity validation.
- [ ] Define how Islamic accounts differ from conventional accounts in ledger mapping, profit distribution, and available balance.

## Implementation Position

Do not implement Islamic finance workflows from Section 29 alone. Keep the current tables as a schema foundation and open product-specific discovery tickets before building services, formulas, accounting postings, APIs, or UI.

