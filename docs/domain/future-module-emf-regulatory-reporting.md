# Future Module: EMF Regulatory Accounting And Reporting

Stakeholder source: section 30, `Integration du Plan Comptable des EMF`.

## Stakeholder Intent

The stakeholder wants the software to integrate the regulatory chart of accounts for microfinance institutions in the CEMAC zone and produce financial/regulatory reports automatically.

Requested capabilities:

- include all EMF accounting accounts automatically;
- prevent coding errors;
- allow sub-accounts;
- link every operation to automatic accounting entries;
- journal all user actions;
- prevent fraudulent deletion;
- manage access rights by profile;
- produce balance, general ledger, journals, COBAC states, financial reports, HR reports, and performance reports;
- provide operation code directories and codification guides.

## Regulatory Boundary

This module needs regulatory validation. BEAC publishes COBAC microfinance instructions, including declarative reporting instructions:

- https://www.beac.int/supervision-bancaire/microfinance/instructions-de-microfinance/

Do not assume current report formats from memory. Regulatory report layouts, periodicity, and submission channels must be verified before implementation.

## Current Architecture Fit

Existing architecture already has:

- ledger accounts;
- journal entries and lines;
- operation codes;
- operation account mappings;
- EMF regulatory accounts;
- EMF ledger mappings;
- report runs.

This future module should strengthen and complete the regulatory layer, not duplicate the accounting ledger.

## Core Model

Recommended entities:

- `emf_regulatory_accounts`: official regulatory accounts and hierarchy.
- `ledger_accounts`: institution chart of accounts.
- `emf_ledger_account_mappings`: mapping from institution accounts to EMF accounts.
- `operation_codes`: official operation classifications.
- `operation_account_mappings`: debit/credit accounts for each operation.
- `report_definitions`: report metadata, version, frequency, schema.
- `report_runs`: generated report instance.
- `report_cells` or structured payloads: generated values and lineage.
- `codification_directories`: reference codes and labels.

## Workflows

### Chart Initialization

Acceptance criteria:

- Regulatory account reference data is loaded from versioned source files.
- Each regulatory account has code, label, class, parent, status, effective dates.
- Institution ledger accounts can map to regulatory accounts.
- Unmapped posted ledger accounts are report blockers.

### Operation Mapping

Acceptance criteria:

- Every automatic financial operation must declare operation code and required debit/credit mapping.
- Missing mapping fails before posting.
- Mapping is agency-aware when ledger accounts are agency-specific.
- Mapping changes do not rewrite historical journal entries.

### Regulatory Report Generation

Acceptance criteria:

- Report generation uses posted journals only.
- Report line items have traceability to ledger accounts and source journals.
- Report output is reproducible for a period.
- Report version is snapshotted.
- Adjustments after report generation require rerun or correction report.

### Submission Readiness

Acceptance criteria:

- Generated reports have maker-checker review.
- Approved reports are locked.
- Export formats are versioned.
- Submission metadata is stored: period, submitted by, submitted at, channel, reference.

## Reports

Minimum report groups:

- trial balance;
- general ledger;
- journals;
- COBAC/EMF regulatory states;
- financial statements;
- HR reports when HR module exists;
- performance reports;
- portfolio and delinquency reports;
- insurance reports when bancassurance exists.

## Controls

Required:

- no hard deletes for posted financial records;
- reversal workflows;
- immutable posted journal lines;
- audit logging;
- profile-based permissions;
- report lineage;
- exported report checksum.

## Backlog

1. Regulatory source collection and legal validation.
2. EMF regulatory account reference loader.
3. Codification directory model.
4. Operation code/mapping completeness gate.
5. Report definition schema.
6. Regulatory report generator.
7. Report review/submission workflow.
8. Export formats.
9. Automated report scheduling.

