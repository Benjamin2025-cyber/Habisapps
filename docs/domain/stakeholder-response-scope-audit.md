# Stakeholder Response Scope Audit

This audit compares the original formula clarification guide with the stakeholder response in:

- `docs/domain/stakeholder-formula-questions.md`
- `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`

The original guide asks for calculation decisions needed before implementing loans, accounts, teller operations, and reports. It contains 25 formula sections. The stakeholder response answers those sections, but also introduces additional product modules, workflows, UI requests, and regulatory/reporting scope that were not part of the formula sign-off request.

## Scope Boundary

Treat the response as a formula clarification source only for sections 1-25, and only where the response directly answers the requested calculation decision fields.

Do not treat the added sections 26-30 or embedded operational notes as approved implementation scope. They require separate product discovery, architecture decisions, delivery sequencing, estimates, and sign-off.

## Clear Scope Creep

| Response area | New request | Why it is out of scope for formula sign-off | Disposition |
|---|---|---|---|
| Section 26, Gestion des Ressources Humaines | Full HR module: employee files, contracts, payroll, leave/absence, sanctions, salary advances, social declarations, HR document scanning, payroll accounting | The original guide does not ask for HR or payroll. This is a separate domain with legal, payroll-tax, document, approval, and accounting requirements. | Reject from formula scope. Track only as future HR discovery if explicitly commissioned. |
| Section 27, Bancassurance | Insurance products, subscriptions, premium payments, claims, insurer partners, insurance reports | The original guide asks only for loan insurance formula decisions. A full bancassurance module is a new product vertical. | Reject from formula scope. Keep only loan-insurance formula data relevant to section 8. |
| Section 28, Change de Devises | Manual FX transactions, exchange-rate setup, FX slips, FX register, multi-currency cash drawer, margin calculations | Current architecture keeps `XAF` as base currency and says multi-currency is out of scope unless a future ADR introduces it. | Reject from current scope. Requires a future multi-currency ADR and separate cash/accounting design. |
| Section 29, Finance Islamique | Islamic accounts, Sharia governance, Islamic financing products, asset traceability, alternative accounting setup | This is a distinct financing model and compliance program, not a clarification of the existing conventional loan formulas. | Reject from formula scope. Requires separate product architecture and legal/compliance discovery. |
| Section 30, Integration du Plan Comptable des EMF | Full CEMAC EMF chart integration, automatic COBAC states, financial/RH/performance reports, operation code directories | A chart of accounts exists in the accounting module scope, but automatic regulatory reporting and full EMF codification are broader than formula sign-off. | Split: chart-of-accounts alignment may belong to accounting; COBAC/RH/performance reporting and codification directories need separate scope approval. |
| Section 30, modules complementaires | SMS banking, automatic alerts, automatic reporting, executive dashboards | These are new communication/reporting modules, not formula decisions. | Reject from formula scope. Track only if added to roadmap by product decision. |

## Embedded Additions Inside Sections 1-25

| Response area | Added or expanded requirement | Risk | Disposition |
|---|---|---|---|
| Section 3, day-count convention | `Date de valeur proposee: 5 jours apres la date d'operation` | Value-date behavior changes transaction effective dating and accounting timing; it was not part of the day-count question. | Needs separate accounting/cash/loan posting decision before implementation. |
| Section 15, early repayment | `Automatiser toutes les recuperations`: debit credit account first, then any other client account; client identification by code | This creates an automatic cross-account recovery engine and account-linking requirement. It affects authorization, account holds, customer consent, reversals, audit, and failed debit handling. | Reject from formula scope. Requires a dedicated recovery/autodebit workflow design. |
| Section 17, accounting balance | New account categories: recovery accounts and ordinary savings accounts | Account taxonomy can be useful, but it is not a complete balance formula and may affect product/account design. | Capture as open account-type discovery, not approved formula. |
| Section 20, billetage | `Configurer l'interface de fermeture de caisse` | UI/workflow request, not a denomination formula. | Keep denomination decisions; route interface request to cash-operations backlog only after teller-session scope is approved. |
| Section 21, till theoretical balance | Inter-till transfer workflow via supply slip, accountant posting, Direction approval | This is workflow and approval design beyond the formula. | Requires separate teller-transfer workflow decision. |
| Section 22, till reconciliation difference | Zero tolerance, but closure may be blocked except for pending-transaction differences | The exception weakens the zero-tolerance rule and depends on unresolved pending-transaction semantics. | Do not implement until pending transaction status and day-close behavior are defined. |

## Formula Responses Still Not Implementation-Ready

Some answers in sections 1-25 provide useful direction but remain ambiguous or contradictory:

- Section 1 approves decimal customer-facing `XAF` values with no rounding, while the architecture currently assumes `XAF` as base currency and leaves rounding precision open. This must be converted into a precise storage/display standard.
- Section 2 says flat interest on initial principal, but the formula `Capital initial * taux / duree` is ambiguous unless `taux` is explicitly defined as total-period, annual, monthly, or per-cycle.
- Section 4 describes included components as `(Capital * taux) / duree + Taxes`, which appears to omit principal from the installment amount unless "capital" is implied elsewhere.
- Section 7 taxes `Capital + Interets`; taxing principal is materially different from taxing interest/fees and should be confirmed with accounting/legal.
- Section 10 defines penalties as `5,000 + 2% unpaid`, but also says the cap is determined by PAR and after 90 days the credit becomes CTX. CTX status and cap behavior are not a numeric penalty cap.
- Section 11 leaves `due_amount` and `total_unpaid_amount` formulas incomplete.
- Section 14 says "approved" but all capitalization trigger/formula/accounting fields remain `A preciser`.
- Sections 17-19 leave balance and movement formulas partially undefined.
- Section 25 says penalties are included in expected collection, but the stated expected formula is only `Capital prevu + Interets prevus`; the actual collection formula also appears inverted.

## Recommended Handling

1. Accept the response as raw stakeholder input, not as implementation approval.
2. Extract a clean formula decision table for sections 1-25 with statuses: approved, ambiguous, contradictory, or out-of-scope addition.
3. Keep formula gates in `config/formulas.php` closed until each calculation rule is precise, internally consistent, and mapped to an approved owner/date.
4. Create separate product-discovery items for HR/payroll, bancassurance, FX/multi-currency, Islamic finance, COBAC reporting, SMS banking, dashboards, and automatic alerts.
5. Do not add these new modules to the implementation roadmap unless the project scope, budget, timeline, architecture, and sign-off are explicitly updated.
