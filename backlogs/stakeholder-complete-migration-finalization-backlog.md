# Stakeholder Complete Migration Finalization Backlog

Sources audited:

- `stakeholderResources/Database-Schema&Entity-Relationship-(ER)-Mapping.md`
- `stakeholderResources/definedModules.md`
- `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`
- Existing migrations in `database/migrations/`

Objective: finish the database migration layer so every stakeholder resource has a durable schema home. Formula outputs remain policy/config driven, but the schema must be ready to persist approved products, workflows, postings, and audit trails.

## Coverage Backlog

### MIG-FINAL-0100: Account Product And Recovery Taxonomy

- [x] Add structured account products/types instead of relying only on `customer_accounts.account_type`.
- [x] Add account behavior fields for minimum balance, recovery eligibility, ordinary savings/current/recovery classification, and ledger mapping.
- [x] Link customer accounts to the new product/type table while keeping existing `account_type` for compatibility.

### MIG-FINAL-0200: Loan Product, Loan Setup, And Workflow Completion

- [x] Extend `loan_products` with ER fields for amount limits, due date day, penalty grace days, grace-period limits, rates, insurance/tax/guarantee policy, floor amount, operation type, and ledger mapping.
- [x] Extend `loans` with setup actors, linked accounts, activity fields, applied rates, schedule parameters, setup charges, and projection fields required by the stakeholder UI.
- [x] Add loan approval workflow records for Montage, Comptable, Controle, and Direction steps.
- [x] Add collateral item records under existing collaterals.
- [x] Add loan transfer history for manager/portfolio mutation.
- [x] Add delinquency tracking records for overdue-client follow-up and promises to pay.

### MIG-FINAL-0300: Charges, Arrears, Penalties, And Automated Recoveries

- [x] Add a generic loan charge assessment table for dossier fees, tax, guarantee deposit, penalties, and non-insurance charges.
- [x] Add arrears/unpaid tracking linked to loans and optional schedule lines.
- [x] Add recovery account priority/mandate records.
- [x] Add recovery attempt history for automatic debit attempts across client accounts.

### MIG-FINAL-0400: Cash, Till, And Operation Codification Completion

- [x] Add operation code reference data for teller, accounting, loan, insurance, HR, FX, Islamic finance, SMS, report, and alert operations.
- [x] Extend tills with ledger account, daily state, balances, denomination requirement, nature, central-till flag, and cash limits.
- [x] Extend teller transactions with transaction date, event number, offset account, remitter/tirer fields, operation code, and description.
- [x] Extend till reconciliations with reconciliation date, theoretical balance, actual balance, and difference.

### MIG-FINAL-0500: Complete Insurance Domain

- [x] Add insurance partners.
- [x] Add insurance products and product coverage records.
- [x] Add insurance subscriptions, including loan-linked borrower insurance.
- [x] Add premium assessments and premium payment records.
- [x] Add insurance claims with incident, status, documents, and indemnity fields.

### MIG-FINAL-0600: HR And Payroll Domain

- [x] Add HR employee records linked to users where applicable.
- [x] Add employment contracts with lifecycle/status fields.
- [x] Add attendance records.
- [x] Add leave requests.
- [x] Add payroll runs, payslips, and payslip line items.
- [x] Add salary advances and sanctions/deductions.

### MIG-FINAL-0700: Manual FX / Multi-Currency Domain

- [x] Add supported currencies and daily exchange-rate records.
- [x] Add till-currency balance records for multi-currency cash drawers.
- [x] Add FX transactions with buy/sell direction, applied rate, margins, client identity, and accounting link.
- [x] Add FX stock movements for bank resale/replenishment and internal corrections.

### MIG-FINAL-0800: Islamic Finance Domain

- [x] Add Islamic product configuration for Mourabaha, Ijara, Salam, Istisna, Moudaraba, Moucharaka, and Islamic accounts.
- [x] Add Islamic financing contracts linked to clients and optional loans.
- [x] Add financed asset records.
- [x] Add profit/loss sharing terms.
- [x] Add Sharia compliance review records.

### MIG-FINAL-0900: EMF Chart, Regulatory Reporting, SMS, Alerts, And Dashboards

- [x] Add EMF regulatory account catalog entries and link them to local ledger accounts.
- [x] Add automatic operation-account mapping records.
- [x] Add report definitions and report runs for COBAC, financial, HR, and performance reporting.
- [x] Add dashboard definitions/widgets.
- [x] Add notification templates and notification deliveries.
- [x] Add SMS message records for SMS banking and repayment/RH alerts.

## Implementation Evidence

- [x] Migration file added: `database/migrations/2026_05_11_000000_finalize_stakeholder_complete_schema.php`.
- [x] Dedicated schema integrity test added: `tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php`.
- [x] README links this backlog.
- [x] `php artisan migrate:fresh --env=testing` passed.
- [x] Post-implementation `php artisan migrate:fresh --env=testing` passed on 2026-05-16 through migration `2026_05_16_040000_add_retry_state_to_notification_and_otp_deliveries`.
- [x] `php artisan migrate:rollback --step=1 --env=testing` passed for the new migration.
- [x] `php artisan migrate --env=testing` passed after rollback.
- [x] `php artisan test tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php` passed: 10 tests, 58 assertions.
- [x] Post-implementation schema verification passed: `php artisan test tests/Feature/Database/FoundationSchemaIntegrityTest.php tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php` passed with 34 tests, 96 assertions.
- [x] `php artisan test` passed after adding dedicated schema tests: 155 tests, 920 assertions.
- [x] Completion audit maps stakeholder sections 1-30 and ER tables 1.1-5.5 to schema evidence: `backlogs/stakeholder-complete-migration-completion-audit.md`.
