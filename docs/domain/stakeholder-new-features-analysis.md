# Stakeholder New Features Analysis

Source:

- `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`, sections 26-30.
- `docs/domain/stakeholder-response-scope-audit.md`.

The stakeholder formula response contains several approved-looking sections after the formula questionnaire. These sections are not formula clarifications. They are new product domains and must be handled as separate discovery and implementation streams.

## Decision

Do not merge sections 26-30 into the loan formula backlog. They are future modules with independent legal, accounting, workflow, security, reporting, and UI scope.

The appropriate product split is:

| Stakeholder section | Product domain | Document |
|---|---|---|
| 26. Gestion des ressources humaines | HR, payroll, employee documents, social declarations, payroll accounting | `docs/domain/future-module-hr-payroll.md` |
| 27. Bancassurance | Insurance catalogue, subscriptions, premiums, claims, insurer partners | `docs/domain/future-module-bancassurance.md` |
| 28. Echange de devises | Counter currency exchange, dedicated foreign-currency till, exchange rates, exchange register, margins | `docs/domain/future-module-currency-exchange.md` |
| 29. Finance islamique | Sharia governance, Islamic accounts, Islamic financing products, asset traceability | `docs/domain/future-module-islamic-finance.md` |
| 30. Plan comptable EMF | CEMAC/COBAC chart alignment, regulatory statements, codification directories | `docs/domain/future-module-emf-regulatory-reporting.md` |
| 30. Modules complementaires | SMS banking, automated alerts, automatic reporting, executive dashboards | `docs/domain/future-module-digital-notifications-dashboards.md` |

Implementation backlogs with detailed guardrails are in `docs/domain/new-feature-implementation-backlogs.md`.

## Cross-Cutting Architecture Rules

These modules share controls that must be designed once and reused:

- Agency scope: all records must be agency-aware unless explicitly global reference data.
- Maker-checker: payroll validation, claims decisions, currency exchange rate publication, Islamic Sharia approvals, and regulatory report submission need controlled approvals.
- Documents: scanned contracts, HR files, claim evidence, currency exchange identity documents, Islamic asset contracts, and regulatory attachments must use the document/media layer, not raw paths.
- Accounting: every financial operation must map to operation codes and ledger mappings before posting.
- Audit: every approval, posting, reversal, rate change, payroll run, claim decision, and report submission must be auditable.
- Reversals: no destructive deletion for posted financial facts; use reversal or correction workflows.
- Public IDs: APIs must expose public IDs only.
- Regulatory uncertainty: legal/tax/regulatory formulas must be configurable, dated, and versioned.

## Delivery Order

Recommended order:

1. Bancassurance completion, because the codebase already has insurance schema, APIs, and loan-insurance integration.
2. Digital notification and alert foundation, because it supports loan, insurance, HR, and reporting events.
3. Currency exchange, because it introduces regulated foreign-currency cash stock, rates, margins, and reporting.
4. EMF accounting and reporting foundation, because reliable regulatory reports need mature operation mappings and posted journals.
5. Dashboards, because they should read governed reporting views after the underlying workflows are stable.
6. HR/payroll, because it is important but separate from the client-facing finance workflows.
7. Islamic finance, because it is a parallel finance architecture, not a variant of conventional loans.

Rationale:

- Bancassurance has the shortest path to completion because the implemented foundation already exists.
- Notifications should be implemented early as a reusable event/outbox capability.
- Currency exchange is high-risk because it introduces regulated foreign-currency stock, rate publication, margins, till reconciliation, and reporting.
- EMF accounting/reporting is foundational for regulatory reporting and dashboards, but should be implemented with versioned official report definitions instead of guessed layouts.
- HR/payroll can be isolated, but payroll accounting and social declarations need strong controls.
- Islamic finance must not be bolted onto conventional interest-based loan tables; it needs separate contracts, assets, accounting, and Sharia governance.

## External Reference Notes

These references are not implementation approval. They identify standards or regulatory sources to verify during discovery:

- BEAC microfinance instructions list COBAC declarative statement requirements for microfinance institutions: https://www.beac.int/supervision-bancaire/microfinance/instructions-de-microfinance/
- BEAC foreign-exchange regulation page references CEMAC foreign exchange regulation: https://www.beac.int/p-des-changes/circulaires-et-decisions/circulaires_et_decisions-vf/
- AAOIFI publishes Islamic finance Sharia standards, including Murabaha and Musharakah standards: https://aaoifi.com/shariah-standards-3/?lang=en
- AAOIFI publishes accounting standards for Islamic finance, including Mudaraba, Musharaka, and Murabaha accounting standards: https://aaoifi.com/accounting-standards-2/?lang=en

## Not Ready For Implementation Until

Each future module needs:

- product owner confirmation that it is in scope;
- legal/regulatory owner;
- accounting owner;
- workflow owner;
- data model ADR;
- API contract;
- migration plan;
- test plan;
- rollout plan;
- operational procedures.
