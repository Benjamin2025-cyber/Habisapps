# Future Module: HR And Payroll

Stakeholder source: section 26, `Gestion des Ressources Humaines`.

## Stakeholder Intent

The stakeholder wants a centralized HR module connected to accounting, cash, credit, security, administration, and reporting. The requested scope includes:

- employee files;
- employment contracts;
- payroll;
- leave and absences;
- sanctions;
- salary advances;
- payroll deductions;
- social declarations;
- scanned HR documents;
- automatic accounting entries linked to personnel.

## Domain Boundary

This is not a staff-user administration feature. Existing `users`, roles, and staff agency assignments handle system access. HR manages the employment relationship.

The future HR model should separate:

- `users`: login, permissions, authentication;
- `staff_agency_assignments`: operational access and agency scope;
- `employees`: HR identity, employment facts, payroll eligibility;
- `employment_contracts`: legal employment terms;
- `payroll_runs`: calculated payroll periods;
- `payroll_entries`: per-employee payroll results;
- `employee_documents`: scanned contracts, IDs, certificates, sanctions, leave decisions.

## Core Workflows

### Employee File

Required behavior:

- generate automatic employee matricule;
- store name, photo, CNI reference, contacts, position, department, agency assignment, hire date, contract type, base salary, professional history, emergency contacts;
- attach scanned documents through `documents`;
- search employees by matricule, name, agency, department, and status;
- archive without deleting history.

Acceptance criteria:

- Employee public ID and matricule are immutable after creation.
- Employee agency history is preserved.
- Employee document paths are never exposed in API responses.
- Employee file access is permission-gated because it contains personal and payroll data.

### Contract Management

Required behavior:

- generate contracts from templates;
- handle CDD and CDI;
- track expiration dates;
- send alerts before expiration;
- preserve contract modification history.

Acceptance criteria:

- A contract has effective dates, status, template version, signed document, and approval trail.
- Renewals create new contract versions instead of rewriting old contracts.
- Expiry alerts are generated from active contract end dates.

### Payroll

The stakeholder lists CNPS, IRPP, CAC, centimes additionnels, acomptes, advances, bonuses, indemnities, overtime, absences, and sanctions.

Implementation principle:

- Payroll formulas must be jurisdiction-configured and dated.
- Do not hardcode Cameroon payroll tax formulas without legal validation.
- Payroll runs must snapshot rates and employee salary facts at calculation time.

Workflow:

1. Prepare payroll period.
2. Import or enter attendance/absence/overtime/bonus/deduction facts.
3. Calculate draft payroll.
4. Review exceptions.
5. Approve payroll run.
6. Post accounting entries.
7. Generate payslips, salary state, payroll journal, bank transfer file, and social/tax declaration outputs.

Acceptance criteria:

- Draft payroll does not post accounting.
- Approved payroll is immutable except through correction/reversal payroll runs.
- Payroll journal posting uses configured operation account mappings.
- Salary advances reduce payable salary through configured deductions.
- Employee net pay and employer charges are separated.

### Leave, Absence, Sanctions

Required behavior:

- attendance;
- lateness;
- absences;
- permissions;
- annual, sick, and maternity leave;
- hierarchical validation;
- leave calendar;
- alerts.

Acceptance criteria:

- Leave request has requester, period, type, status, approver, and decision timestamp.
- Absences can affect payroll only after validation.
- Sanctions can affect payroll only if explicitly configured as a deduction.

## Accounting Impact

Payroll approval should generate entries for:

- salary expense;
- employee net payable;
- employer social charges;
- CNPS payable;
- tax payable;
- salary advances recovery;
- provisions where applicable.

Open accounting decisions:

- chart accounts for each payroll component;
- whether salary advances are employee receivables or payroll deductions only;
- payroll reversal period rules;
- payment channel: bank transfer, cash, or internal account.

## Security And Privacy

HR data is sensitive.

Required controls:

- HR role permissions separate from user administration permissions;
- payroll-specific PII visibility;
- audit logs for salary changes, contract changes, payroll approval, and document access;
- no destructive deletes for employee, contract, payroll, or sanction records.

## Backlog

1. HR discovery and legal payroll validation.
2. Employee file schema and API.
3. Employee document workflow.
4. Contract templates and contract lifecycle.
5. Leave/absence/sanction workflows.
6. Payroll formula engine and payroll run workflow.
7. Payroll accounting mappings and posting.
8. Payroll reports and declarations.
9. Alerts for contracts, leave, and payroll deadlines.

## Implementation Review Decisions

The first implementation is an HR/payroll control foundation, not a complete Cameroon payroll declaration engine.

Hard requirements enforced after adversarial review:

- HR employees are separate from login users; `user_id` is optional linkage, not the employee master record.
- Employee API responses use public agency identifiers and do not expose document paths.
- Employee agency assignment history is recorded when the employee file is created.
- Employee and contract document attachments must use the existing `documents` table and must belong to the employee agency.
- Contract renewals create a new version and supersede the previous active contract instead of rewriting it.
- Dated contracts enqueue an internal contract-expiry alert.
- Payroll formula sets require a registered regulatory/legal source and maker-checker activation.
- Payroll runs cannot calculate without an active formula set covering the full payroll period.
- Payroll runs snapshot the formula set and rates used for calculation.
- Approved absence deductions require an approved leave/absence record for the employee; pending/rejected records cannot affect payroll.
- Draft payroll runs do not post journals; approval posts accounting using configured operation mappings.
- Payroll approval is maker-checker: the payroll creator cannot approve the same run.
- Correction payroll runs are linked to the approved source run, reverse the prior payroll journal, and post the replacement run.
- Final declaration exports are generated only from approved payroll runs and include a checksum plus source payroll run public ID.

Known remaining product gaps:

- Full CNPS/IRPP/CAC statutory formulas and declaration file layouts still require legal validation and source-backed configuration.
- Salary advances, sanctions, overtime, bonuses, and employer-charge accounting need deeper component-specific workflows.
