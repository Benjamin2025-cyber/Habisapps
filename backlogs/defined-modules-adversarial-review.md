# Defined Modules Adversarial Review

Review target:

- `stakeholderResources/definedModules.md`
- `backlogs/defined-modules-implementation-audit.md`
- Module completion backlogs added from the audit
- Current application routes/controllers/models/tests

Review posture: assume the audit missed requirements, over-credited implementation, or hid schema-only work behind optimistic language.

## Findings

### Finding 1: Module 1 staff identity was credited too broadly

The audit correctly credits staff auth, OTP, role, status, and assignment management. However, stakeholder and ER context include richer personnel identity/profile concepts than the current `users` implementation exposes: gender, birth date/place, title/function, professional profile, portfolio assignment, supervisor hierarchy, and richer staff documents. Some of these now belong naturally to the HR module from later stakeholder responses, but Module 1 still needs a clear handoff.

Action taken:

- Added explicit staff professional-profile and HR handoff work to `backlogs/module-1-administration-completion-backlog.md`.

### Finding 2: Module 1 agency structure misses some ER-level structural fields

Current agencies cover code, name, region, city, branch name, contacts, address lines, creation date, status, and manager. ER context also mentions branch type, PO box, fax, and geographic description. These may not block current APIs, but they are still stakeholder-resource fields.

Action taken:

- Added agency structural metadata completion to `backlogs/module-1-administration-completion-backlog.md`.

### Finding 3: Module 2 client profiling missed photo and business-activity precision

The audit credited client profiling as mostly complete. That is fair for the safe API, but adversarially it missed stakeholder-resource detail: photo, business start date/activity date, business address, father/mother names, home phone, and exact identity fields from the ER resource. Some identity fields are handled through identity-document records, but photo and business-activity data need explicit completion work.

Action taken:

- Added client photo/business-profile completion work to `backlogs/module-2-crm-completion-backlog.md`.
- Updated `backlogs/defined-modules-implementation-audit.md` to mention the gap.

### Finding 4: Module 3 sector/sub-sector implementation was over-credited

Sector and sub-sector reference CRUD exists, but `backlogs/module-3-accounting-architecture-backlog.md` still has `DEV-0502` open for safe integration with client metadata. The audit did not make that open item visible enough.

Action taken:

- Added sector/client classification completion work to `backlogs/module-3-accounting-completion-backlog.md`.
- Updated the audit to list client-sector integration as missing.

### Finding 5: Module 3 PCG/EMF expectations conflict with current agency-scoped ledger assumptions

The audit mentioned EMF chart mapping, but did not emphasize the design risk: current ledger constraints favor agency-scoped ledger accounts, while regulatory chart-of-accounts catalogs are usually institution-level reference structures. This is already noted in older backlogs but needed explicit completion coverage.

Action taken:

- Added shared/global chart design decision to `backlogs/module-3-accounting-completion-backlog.md`.

### Finding 6: Module 4 backlog needed loan-specific guarantor obligations

Module 4 collateral/guarantee coverage mentioned collateral and collateral items, but loan-specific guarantor obligations are distinct from Module 2 guarantor identity records. Without this, guarantor identity could be confused with legal obligation on a loan.

Action taken:

- Added loan guarantee obligation work to `backlogs/module-4-credit-loans-backlog.md`.

### Finding 7: Module 5 cash infrastructure now has schema fields that the safe API still rejects

The stakeholder-completion migration added full till fields such as ledger account, limits, central till flag, and currency. The safe Module 5 API intentionally rejects those deferred fields today. The audit stated this generally, but the completion backlog must ensure the API catches up with the expanded schema.

Action taken:

- `backlogs/module-5-cash-operations-completion-backlog.md` already covers this under till configuration completion. No extra patch needed.

### Finding 8: Stakeholder-added modules 26-30 are outside `definedModules.md` but no longer outside project knowledge

The user's current request specifically targeted `definedModules.md` Modules 1-5. However, the codebase now has schema and discovery material for HR, insurance, FX, Islamic finance, EMF reporting, SMS, alerts, and dashboards from later stakeholder responses. These should not be silently mixed into Modules 1-5 completion, but they need separate implementation backlogs before they are treated as product-ready.

Action taken:

- Existing discovery/completion coverage remains in stakeholder migration and Islamic finance backlogs.
- Follow-up needed: create separate full implementation backlogs for HR, insurance, FX, EMF/reporting/SMS/dashboard domains when the project moves beyond Modules 1-5.

## Updated Assessment

The original audit was directionally correct but too high-level in places. After this adversarial review, the known omissions have been added to the relevant completion backlogs.

No code changes were required for this review; it was a planning/backlog hardening pass.

## 2026-05-16 Implementation Follow-Up

The findings above were later implemented through the Module 1-5 completion backlogs:

- Module 1 staff profile handoff, agency metadata, batch execution controls, SMS/OTP provider, and notification retry state are implemented.
- Module 2 KYC policy, encrypted identity documents, profile/business fields, guarantor strategy, account-specific proxy mandates, and collection metadata handoff are implemented.
- Module 3 sector integration, EMF catalog/mapping, operation mappings, posting, balances, statements, and reporting foundations are implemented.
- Module 4 loan application APIs, approval, schedules, setup charges, collateral, guarantee obligations, disbursement, repayment, early settlement, recovery, arrears, delinquency, transfers, and reporting are implemented.
- Module 5 till configuration, teller sessions, cash movement, reconciliation, manual cash journals, reversals, and cash-close integration are implemented.

Remaining verification limitation: the full `php artisan test` suite was intentionally not rerun to completion after the latest implementation pass because it was cancelled as too slow. Current evidence is the focused module tests, schema integrity tests, route registration, `vendor/bin/phpstan analyse --memory-limit=1G`, and `vendor/bin/pint --test`.
