# Agency Scope And Multi-Branch Operations

This document defines how the system supports multiple agencies/branches inside one microfinance institution. It is written from the viewpoint of operational actors so implementation does not accidentally leak data across branches or block valid head-office workflows.

## Scope Model

Decision:

- The platform supports one institution with multiple agencies/branches.
- This is agency scoping, not full multi-tenancy.
- Agency ownership must be explicit on operational records.

Core hierarchy:

- Institution
- Region or zone
- Agency/branch
- Staff assignment
- Customer/account/loan/till operational records

Recommended entities:

- `regions`: code, name, status.
- `agencies`: code, name, type, region_id, city, status, manager_id.
- `user_agency_assignments`: user_id, agency_id, role/scope, starts_at, ends_at, is_primary.

Reasoning:

- A staff member may be temporarily assigned to another agency.
- Historical operations must remain linked to the agency where they occurred, even if staff later moves.

## Actor Viewpoints

### Teller / Cashier

Needs:

- Access only to assigned till sessions.
- Ability to perform deposits/withdrawals only for allowed accounts.
- Ability to view today’s transactions for their till/session.
- No access to other tellers’ cash drawer details unless authorized.

Rules:

- Teller transactions must use the agency of the till/session.
- Teller cannot post to a closed till session.
- Teller cannot operate a till in another agency unless explicitly assigned there.
- Teller reversals/cancellations require elevated permission.

### Loan Officer / Gestionnaire

Needs:

- Access to assigned clients and loan portfolio.
- Ability to create/update loan applications in assigned agency.
- Ability to record follow-ups, promises to pay, and delinquency interactions.

Rules:

- Loan officer can only act on loans assigned to them or their agency unless elevated permission exists.
- Portfolio transfers must be explicit records.
- Reassignment must not rewrite historical actor fields.

### Branch Manager / Chef D'Agence

Needs:

- Oversight of staff, clients, accounts, loans, and tills in their agency.
- Ability to approve or supervise agency-level operations depending on role.
- Ability to view agency reports and operational exceptions.

Rules:

- Branch manager scope defaults to their agency.
- Cross-agency access requires regional/head-office permission.
- Branch manager can approve agency operations only when workflow rules allow that role at that step.

### Regional Manager / Zone Supervisor

Needs:

- Read access across agencies in assigned region.
- Portfolio and performance rollups by branch.
- Exception monitoring and possibly reassignment approval.

Rules:

- Regional scope should be represented explicitly, not inferred from agency names.
- Regional staff can view child agencies in their region.
- Write permissions must be narrower than read permissions unless explicitly granted.

### Head Office / Direction

Needs:

- Institution-wide visibility.
- Final credit approval where required.
- Product configuration, chart of accounts, batch supervision, and reporting.

Rules:

- Head-office actions must still record the operational agency of the affected record.
- Institution-wide permission should be explicit and rare.
- Head-office staff should not accidentally create agency-owned records without specifying agency context.

### Accountant / Comptable

Needs:

- Access to ledger postings, journal entries, account configuration, and financial reports.
- Ability to approve accounting workflow steps and manual entries.

Rules:

- Ledger postings must carry `agency_id` where the financial event belongs to a branch.
- Chart of accounts may be global, but postings are agency-contextual.
- Manual postings require agency context, actor, reference, and reason.
- Cross-agency postings require special handling and explicit balancing rules.

### Auditor / Internal Control

Needs:

- Read-only access across assigned scope.
- Ability to inspect audit trail, reversals, approvals, rejected transactions, and reconciliation differences.

Rules:

- Auditor access should not mutate business records.
- Audit exports must preserve agency, actor, timestamp, original values, and current state.
- Sensitive PII access by auditors should itself be logged.

### System Administrator

Needs:

- Manage staff access, roles, permissions, agency structure, and system configuration.

Rules:

- System admin does not automatically imply permission to post financial transactions.
- Administrative access and financial authority must be separate permissions.
- Agency reassignment and role changes must be audited.

## Agency Ownership Rules

Records that must have `agency_id`:

- users or staff assignment records
- clients
- customer accounts
- loans
- loan applications
- loan schedules through loan relationship
- guarantors where agency-owned
- proxies through account/client relationship
- tills
- teller sessions
- teller transactions
- journal entries
- cash reconciliations
- batch runs if agency-specific
- delinquency follow-ups
- loan transfers

Records that may be global:

- roles and permissions
- loan product catalog, unless products differ by agency
- chart of accounts
- sectors and sub-sectors
- currency denominations, unless agency-specific currency support is added
- batch procedure definitions

Rule:

- Global configuration can be referenced by agency records, but agency records must not lose their agency context.

## Query Scoping Defaults

Default:

- Operational queries must be agency-scoped.
- Cross-agency queries require explicit permission and explicit scope parameter.

Implementation guidance:

- Do not rely only on frontend filtering.
- Enforce agency scope in policies, query builders, and action classes.
- Tests must prove users cannot access another agency’s clients, accounts, loans, tills, transactions, documents, or reports.
- Head-office and auditor permissions must be tested separately from branch permissions.

Recommended scopes:

- `own`: records assigned to the actor.
- `agency`: records in actor’s active agency.
- `region`: records in actor’s assigned region.
- `institution`: records across all agencies.

## Reference Numbering

Open decision:

- Confirm whether business references are globally unique or agency-prefixed.

Recommendation:

- Public API identifiers should use `public_id`.
- Human-facing references should be agency-prefixed and globally unique.

Examples:

- Agency: `AG001`
- Customer matricule: `AG001-C-000001`
- Account number: `AG001-ACC-000001`
- Loan number: `AG001-LN-000001`
- Teller event number: `AG001-EVT-20260426-000001`
- Journal reference: `AG001-JRN-20260426-000001`

Rules:

- Reference generation must be transactional.
- Never generate financial references only in memory without a database uniqueness guarantee.
- Never reuse references after cancellation or reversal.

## Cross-Agency Operations

### Portfolio Transfer

Use case:

- Loans or clients move from one manager to another, possibly across agencies.

Rules:

- Create a transfer record with source agency, target agency, source manager, target manager, reason, actor, and timestamp.
- Do not rewrite historical manager/agency on past transactions.
- Decide whether active loan/account agency changes or only assignment changes.

### Customer Transfer

Use case:

- A customer moves branch.

Rules:

- Customer agency change requires approval and audit.
- Existing accounts/loans may remain at original agency unless explicitly transferred.
- Documents and KYC history remain attached to the customer and visible according to new scope rules.

### Cash Transfer Between Agencies

Use case:

- Physical cash moves from one branch/till to another.

Rules:

- Requires a source and destination agency/till.
- Requires in-transit state.
- Requires balanced ledger postings.
- Requires sender confirmation and receiver confirmation.
- Difference/loss handling requires approval and audit.

### Inter-Agency Accounting Entries

Use case:

- One agency operation affects another agency’s books.

Rules:

- Must be explicitly marked as inter-agency.
- Must include both source and destination agency.
- Must balance at institution level.
- If branch-level reporting must balance, use inter-branch clearing accounts.

## Ledger Agency Semantics

Decision:

- Ledger accounts are global by default.
- Journal entries carry `agency_id`.
- Journal lines may carry `agency_id` if a single journal entry can affect multiple agencies.

Recommended early rule:

- Keep one `agency_id` on `journal_entries` and prohibit multi-agency journal entries until inter-agency accounting is explicitly designed.

When inter-agency support is added:

- Add line-level agency context or dedicated inter-agency transfer records.
- Use clearing accounts.
- Require dual confirmation or elevated approval.

## Documents And PII Across Agencies

Rules:

- Staff can access customer documents only within their authorized scope.
- Head-office/auditor access to PII must be logged.
- Customer transfer must not duplicate document files.
- File metadata stays linked to the owning customer/document record.
- Raw storage paths must never be exposed.

## Batch And End-Of-Day

Open decision:

- Confirm whether end-of-day runs per agency, globally, or both.

Recommendation:

- Batch procedure definitions are global.
- Batch runs are agency-specific when they process agency-owned cash/accounts/loans.
- A global batch can orchestrate agency-specific batch runs.

Rules:

- A failed agency batch must not silently mark global EOD as complete.
- Batch runs must record agency, status, started_at, completed_at, errors, and triggering actor/system.
- Cash reconciliation should block agency close when unresolved differences exceed configured tolerance.

## Reporting

Required reporting dimensions:

- agency
- region
- manager/loan officer
- teller/till
- product
- sector/sub-sector
- customer status
- loan status
- accounting period

Rules:

- Branch reports default to agency scope.
- Regional reports aggregate child agencies.
- Institution reports aggregate all agencies.
- Reports must indicate whether data is posted/final or includes pending transactions.

## Tests Required Before Feature Release

Every agency-aware feature needs tests for:

- Same-agency allowed access.
- Other-agency denied access.
- Regional/head-office allowed read access where configured.
- Write access denied for read-only elevated roles.
- Agency context required on creation.
- Historical agency context preserved after staff or customer transfer.
- Reference uniqueness under concurrent creation.
- Financial posting cannot cross agency unless the operation explicitly supports it.

