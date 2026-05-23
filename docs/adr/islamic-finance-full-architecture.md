# ADR: Full Islamic Finance Architecture

Date: 2026-05-23
Status: Accepted

## Context

Stakeholder section 29 requests a complete Islamic finance capability: Sharia governance, Islamic accounts, Mourabaha, Ijara / Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, Moucharaka, accounting setup, Haram-operation blocking, interest controls, Zakat-related accounting, and financed-asset traceability.

The previous partial architecture treated one product family as the first boundary and deferred important controls. That is not an acceptable architecture for this domain. Islamic finance must be modeled as a distinct governed finance domain, not as an `is_islamic` flag on conventional loans.

## Decision

Implement Islamic finance as a dedicated domain with product-specific contracts, workflows, accounting mappings, documents, approvals, reports, and tests.

The architecture consists of these components:

- Sharia governance service for boards, mandates, approvals, suspensions, revocations, exceptions, and corrective actions.
- Haram screening service for customer activity, asset, supplier, goods, project, source/use of funds, and policy-version checks.
- Islamic product catalog with product-family specific readiness gates.
- Contract template registry with versioned Sharia approval.
- Asset, goods, project, and partnership registries used by Islamic contracts.
- Product-specific workflow services for Mourabaha, Ijara, Salam, Istisna'a, Moudaraba, and Moucharaka.
- Islamic account services for current, savings, and investment accounts.
- Islamic accounting operation mappings that fail closed when incomplete or unapproved.
- Evidence/document integration for every approval, contract, asset, milestone, delivery, profit declaration, screening result, and corrective action.
- Reporting layer for Sharia audit, product readiness, contract registers, asset traceability, blocked attempts, profit pools, distributions, exposures, and exceptions.

## Architectural Rules

- Do not reuse conventional interest fields for Islamic products.
- Do not represent Islamic financing as ordinary loans with a label change.
- Do not post money until the product, contract template, accounting mapping, and required evidence are approved.
- Do not allow product activation if the readiness checklist is incomplete.
- Do not allow contract approval when required real-economy linkage is missing.
- Do not allow Haram-screened parties, assets, goods, projects, or purposes to proceed without an approved exception path.
- Do not silently delete compliance facts; use reversal, suspension, revocation, or corrective-action workflows.
- Expose public IDs through APIs and preserve internal IDs as implementation details.

## Product Boundaries

Each product family has its own workflow boundary:

- Mourabaha: cost-plus sale receivable backed by institution purchase or control of an asset.
- Ijara / Ijara wa Iqtina: lease workflow backed by owned or controlled asset and optional approved ownership transfer.
- Salam: upfront purchase of precisely specified goods delivered later.
- Istisna'a: manufacturing or construction obligation with milestones, inspections, variations, and acceptance.
- Moudaraba: capital and expertise partnership with profit sharing, non-guaranteed return, and loss rules.
- Moucharaka: capital partnership with contribution evidence, profit-sharing, loss-sharing, governance, and exit rules.
- Islamic accounts: current, savings, and investment accounts with no conventional interest accrual.

## Accounting Boundary

Islamic accounting is operation-code driven. Every product family must have approved mappings before activation.

Posting must fail when:

- Mapping is missing.
- Mapping is inactive or expired.
- Mapping has not been approved by accounting and Sharia governance where required.
- The posting event does not match the product state.
- The event would use conventional interest, interest penalty revenue, or an unrelated loan account.

## Proof By Contradiction Gate

Every workflow must include forbidden-state tests. The implementation is not accepted unless tests prove the following classes of contradiction are impossible:

- Cash-only Mourabaha approval.
- Ijara activation without an owned or controlled asset.
- Salam approval with vague goods or missing delivery terms.
- Istisna'a staged payment without milestone evidence.
- Moudaraba guaranteed return or unsupported entrepreneur loss charge.
- Moucharaka buyout without approved valuation.
- Islamic account conventional interest accrual.
- Posting with missing or unapproved Islamic operation mapping.
- Product activation without Sharia approval.
- Haram-screened contract activation without approved resolution.

## Consequences

Positive consequences:

- Product behavior matches the stakeholder's full Islamic finance requirement.
- Accounting and compliance controls are explicit and testable.
- Product-specific workflows prevent disguised conventional lending.
- Reports can support Sharia audit and management oversight.

Costs:

- More schema, workflow, and test coverage than a single-product shortcut.
- Legal, accounting, and Sharia governance owners must participate before production activation.
- Each product family requires its own operational procedures and training.

## References

- Domain requirements: `docs/domain/islamic-finance.md`
- Implementation backlog: `backlogs/islamic-finance-complete-implementation-backlog.md`
- AAOIFI Shariah Standards: https://aaoifi.com/shariah-standards-3/?lang=en
- AAOIFI Accounting Standards: https://aaoifi.com/accounting-standards-2/?lang=en
- IFSB standards and guiding principles: https://www.ifsb.org/standards-page/
- IFSB-31, Guiding Principles for Effective Supervision of Shariah Governance: https://www.ifsb.org/wp-content/uploads/2025/07/IFSB-31-Guiding-Principles-for-Effective-Supervision-of-Shariah-Governance.pdf
- IFSB-1, Guiding Principles of Risk Management for institutions offering Islamic financial services: https://www.ifsb.org/wp-content/uploads/2023/10/ifsb1.pdf
