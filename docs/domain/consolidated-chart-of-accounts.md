# Consolidated Chart of Accounts (institution + agencies)

Status: implemented on branch `feat-entreprise`, 2026-08-05. Not yet merged to `main`.

This document is the reference for the institution/agency accounting structure. It
records what changed, which decisions were deliberate, and the invariants that
later work must not break. See also [accounting-ledger.md](accounting-ledger.md)
for the ledger tables and [agency-scope.md](agency-scope.md) for agency scoping.

---

## 1. The problem this solved

Testers could not configure a consolidated structure: an institution-level parent
account (e.g. `571000 – Caisse Globale`) grouping the operational accounts of each
agency (`571001`, `571002`, `571003`).

Root cause: `ledger_accounts.agency_id` was `NOT NULL`
(`2026_04_28_052156_add_tenant_scoped_documents_and_ledger_accounts.php:31`), so
an institution-level account could not exist at all. The API said as much —
"Ledger accounts must be attached to an agency **in this safe slice**" — meaning
this was a deliberately deferred path, not an oversight. Supporting evidence that
the nullable path was designed for: `LedgerAccount::$agency_id` was already typed
`int|null`, `index()` already unioned `agency_id IS NULL`,
`StoreLedgerAccountRequest` already accepted a null `agency_public_id`, and the
`down()` of that migration already re-created the two partial unique indexes.

Two claims in the original problem report (`habis-microfinance-accounting-structure-problem.md`)
were **wrong and were not acted on**:

1. *"The controller refuses to link a sub-account to a parent in another agency,
   which blocks the institution parent."* — Both guards already allowed a NULL-agency
   parent (`$parent->agency_id !== null && ...`). What they refuse is *cross-agency*
   parenting, which is correct and was kept.
2. *"`UNIQUE (agency_id, code)` must be relaxed."* — The opposite was needed. With
   the chosen coding model each agency has its own code, which that constraint already
   permits; what was missing was a guard for institution codes, because Postgres
   treats NULL `agency_id` values as distinct and would have allowed two `571000`s.

## 2. Decisions (and why)

| Decision | Rationale |
|---|---|
| **Distinct codes per agency** (`571000` institution, `571001/2/3` per agency) | Matches how the testers drew it. Works with the existing `UNIQUE (agency_id, code)`; only needs an added partial index for institution codes. The alternative (same code in every agency) makes a code ambiguous without its agency in every report. |
| **Institution = `agency_id IS NULL`**, not a parent FK | An EMF is one legal entity, so a scoping table would have exactly one row. More importantly the composite FK `journal_lines (ledger_account_id, agency_id) → ledger_accounts (id, agency_id)` makes institution accounts **unpostable for free**; an `institution_id` column would lose that and need a trigger. Also consistent with `accounting_days.scope_type = 'institution'`, which already used this encoding. |
| **`is_postable` column** rather than reusing `account_type` | `account_type` is a free-form `varchar(64)` with no semantics anywhere. The flag also covers *agency-level* grouping accounts, which the composite FK cannot protect. |
| **Grouping accounts consolidate by default** | A non-postable account can never have movements of its own, so a bare balance request would return 0. Defaulting to consolidation makes the tree work with no client change; `?consolidated=0` returns own-movements-only. |
| **Auto-convert a parent** when it gains its first child | Requiring the client to flip `is_postable` first would make adding a sub-account a two-call dance with a confusing error. Rejected only when the parent already carries movements. |
| **Institution profile as a singleton, no FKs** | The institution needed identity attributes (legal name, agrément, RCCM, NIU, head office) that had nowhere to live. Adding them as a profile keeps the blast radius near zero; nothing gained an `institution_id`. |
| **Declared currency / fiscal-year month are inert** | `config/money.php` stays authoritative for currency, and `accounting_days` / `accounting_calendar_days` for the calendar. Making the profile authoritative would ripple through every `?? 'XAF'` default and the whole accounting-day pipeline. |

## 3. What shipped

### Schema

- [`2026_08_05_000000_add_institution_level_ledger_accounts.php`](../../database/migrations/2026_08_05_000000_add_institution_level_ledger_accounts.php)
  - `agency_id` nullable again.
  - `UNIQUE (agency_id, code)` replaced by `uniq_agency_ledger_account_code`
    (`WHERE agency_id IS NOT NULL`) and `uniq_institution_ledger_account_code`
    (`WHERE agency_id IS NULL`).
  - `is_postable boolean NOT NULL DEFAULT true`.
  - `CHECK (agency_id IS NOT NULL OR is_postable = false)`.
  - `down()` **refuses to run** while institution accounts exist rather than deleting
    financial configuration.
- [`2026_08_05_000100_create_institution_profile_table.php`](../../database/migrations/2026_08_05_000100_create_institution_profile_table.php)
  - `institution_profile`, singular table name, non-sequence primary key,
    `CHECK (id = 1)`, and a fiscal-month range check.

### API

| Route | Notes |
|---|---|
| `POST /api/v1/ledger-accounts` | New `scope: "agency" \| "institution"` (default `agency`) and `is_postable`. Institution scope requires `ledger.scope.institution.manage`. |
| `PATCH /api/v1/ledger-accounts/{id}` | `is_postable` accepted; parent rules shared with create. |
| `GET /api/v1/ledger-accounts/{id}/balance` | `?consolidated=0\|1` overrides the default described above. `scope` in the response is `ledger_account` or `ledger_account_consolidated`. |
| `GET /api/v1/ledger-accounts/{id}/movements` | A grouping account lists its subtree's movements, so the statement agrees with its balance. |
| `POST /api/v1/report-runs` | `parameters.consolidated = true` on a trial balance emits the rolled-up tree with `scope`, `agency_public_id`, `parent_account_public_id`, `is_postable` per row. |
| `GET` / `PATCH /api/v1/institution` | Singleton, no identifier in the path. Classified `administration`, so it is editable before any accounting day exists. |

Ledger-account responses gained `scope` and `is_postable`.

### Where the rules live

- [`LedgerAccountController::parentError()`](../../app/Http/Controllers/Api/V1/LedgerAccountController.php)
  — the single place that decides whether a parent link is legal.
  `convertToGroupingAccount()` performs the auto-conversion (audited as
  `ledger.account.converted_to_grouping`).
- [`LedgerAccountHierarchy`](../../app/Support/Accounting/LedgerAccountHierarchy.php)
  — subtree resolution, read once per instance, cycle-safe. Used by both the balance
  calculator and the consolidated trial balance.
- [`AccountingBalanceCalculator::forLedgerAccount()`](../../app/Support/Accounting/AccountingBalanceCalculator.php)
  — `?bool $consolidated = null` means "decide from `is_postable`".
- [`ReportRunController::consolidatedTrialBalanceRows()`](../../app/Http/Controllers/Api/V1/ReportRunController.php)
  — the rollup. Grand totals are computed from the **posted** accounts only, never
  from the returned rows, or movements would be counted once per level of the tree.

### Unpostability is enforced at three layers

1. Composite FK — institution accounts, at the database.
2. [`JournalLineController`](../../app/Http/Controllers/Api/V1/JournalLineController.php)
   — 422 `domain.ledger_account_not_postable`, covering agency-level grouping accounts
   too and turning what would be a 500 into a validation error.
3. [`OperationAccountMappingController::resolveActiveLedgerAccount()`](../../app/Http/Controllers/Api/V1/OperationAccountMappingController.php)
   — a grouping account cannot be made an operation posting target in the first place.

`AgencyLedgerMappingResolver::ledgerValid()` was already strict (`agency_id === $agencyId`)
and needed no change.

### Institution profile

- [`InstitutionProfile`](../../app/Models/InstitutionProfile.php) — `current()` for read
  paths (never writes, returns null when unconfigured); `singleton()` for write paths
  (creates the empty row so the endpoint works on a fresh install). **Keep this split**:
  it is what stops report generation from writing.
- [`InstitutionProfileSeeder`](../../database/seeders/InstitutionProfileSeeder.php), wired
  into `DatabaseSeeder`. The row is intentionally left empty — the legal name and
  agrément appear on supervisory filings and must be entered, not guessed from `app.name`.
- `ReportRunController::institutionDeclarant()` snapshots the filing institution's
  identity into the EMF/COBAC trial balance and the mainlevée-de-garantie attestation.
  Unconfigured fields are **null, not invented**, and never block a report.

### Permissions

| Permission | Granted to | Notes |
|---|---|---|
| `ledger.accounts.view/create/update/archive` | platform-admin, **accountant**, **chief-accountant** | Maintaining the chart of accounts is an accounting job; previously platform-admin only. Scope is enforced by the policy, below. |
| `ledger.scope.institution.read` | platform-admin, user-admin, **chief-accountant** | Reads across agency charts, and unlocks consolidated institution figures. |
| `ledger.scope.institution.manage` | platform-admin, **chief-accountant** | In `RoleController::protectedPermissions()`. |
| `institution.profile.view` | platform-admin, accountant, auditor, **chief-accountant** | |
| `institution.profile.manage` | platform-admin, **chief-accountant** | In `protectedPermissions()`. |
| `accounting.scope.institution.manage` | platform-admin, **chief-accountant** | New. Replaces a hard `hasRole('platform-admin')` check on institution-scoped accounting days and calendars. In `protectedPermissions()`. |

### The two accounting roles

Before this work, every institution-level *write* in the system belonged to
`platform-admin` — the vendor, not the institution. That is still true of
`crm.scope.institution.manage` and institution accounting days, but no longer of the
chart of accounts:

- **`accountant`** — agency-scoped. Maintains its own agency's chart. Holds no
  institution-scope permission, so it can neither create grouping accounts nor read
  consolidated institution figures.
- **`chief-accountant`** (new; *chef comptable*, head office) — the institution's
  accounting authority. It owns:
  - the institution grouping chart and its deployment into every agency chart;
  - the **accounting period**, institution-wide and per agency — opening, closing and
    the institution calendar (the *arrêté comptable*);
  - **manual journal work** — entries and lines, review, posting and reversal
    (*opérations diverses* and corrections);
  - **posting configuration** — operation codes and operation→ledger mappings;
  - the **EMF/COBAC regulatory chart** and its mappings from the local chart;
  - consolidated reporting (`accounting.audit.view`) plus the product, batch and
    journal visibility needed to reconcile it;
  - the institution's declared identity (`institution.profile.manage`).

`chief-accountant` deliberately carries **no agency assignment**. Nothing requires one:
`users.agency_id` is nullable and `staff_agency_assignments` is optional — that is how
`platform-admin` already works. `ledger.scope.institution.manage` is what lets the role
write into any agency's chart, via
[`LedgerAccountController::canCreateInAgency()`](../../app/Http/Controllers/Api/V1/LedgerAccountController.php).
It also holds no agency-operational permissions (no disbursement, no repayments, no day
lifecycle) — widen it deliberately if the institution needs more.

Institution-scoped accounting days and calendars were previously reserved for
`platform-admin` by a hard role check in four places. That reservation is now expressed as
a permission, `accounting.scope.institution.manage`, resolved in one place —
[`AccountingScopeAccess`](../../app/Support/AccountingDay/AccountingScopeAccess.php) — and
consumed by `AccountingDayWorkflow::index()` and `::resolveScopeForRequest()`,
`AccountingDayPolicy::canAccessScope()` and `AccountingCalendarDayPolicy::canAccessScope()`.
`index()` mattered as much as the rest: it previously returned a blanket 403 to any actor
without an agency assignment, which is every head-office actor.

**What the chief accountant still cannot do, on purpose:**

| Withheld | Why |
|---|---|
| `accounting.days.reopen` | Reopening a closed period must not sit with whoever closed it. Platform-admin only. |
| `batch.runs.manage` / `batch.procedures.manage` | In `nonDelegableProtectedPermissions()` — platform-admin forever by design. The role holds the `view` counterparts, so it can see whether close-control batches passed; **if those batches are triggered manually rather than scheduled, closing a day still depends on a platform admin running them.** |
| Regulatory source registration, report-definition versioning, report-run review and submission | `RegulatoryReportingWorkflow` gates these on `hasRole('platform-admin')` directly — no permission exists, so this cannot be granted by config. The role *can* still generate EMF reports via `accounting.audit.view`. |
| Customer accounts and client data | `CustomerAccountPolicy` requires same-agency for non-admins, so grants would be inert; cross-agency client data is a privacy decision beyond this work. |
| Cash/teller and loan operations | Agency-operational; the agency `accountant` holds those for its own agency. |

**Control weakness inherited, not introduced:** `operation_account_mappings.approval_status`
is set directly through create/update, with no separate approve permission and no
maker-checker. So the chief accountant can author *and* approve the mappings that drive
automatic postings — exactly as platform-admin already could. Journal entries are not
affected: `JournalEntryWorkflow::approve()` rejects the maker and the submitter by user id,
so holding both `journal.entries.create` and `.review` cannot self-approve an entry. A
maker-checker step on operation mappings is worth a follow-up.

### Who may do what to the chart

Granting the accountant role write access made three latent scoping holes live, all
closed in the same change. Before, `show`/`update`/`destroy` had **no agency check at
all** and `store()` honoured any `agency_public_id` it was given — harmless only because
a single role held the permissions.

[`LedgerAccountPolicy`](../../app/Policies/LedgerAccountPolicy.php) now scopes by agency,
following the `CustomerAccountPolicy` pattern (`StaffAgencyScope::currentAgencyId`):

In the table below, "own agency" means the actor's `staff_agency_assignments` scope;
`chief-accountant` and `platform-admin` satisfy every institution row.

| Action | Own agency's accounts | Another agency's | Institution grouping accounts |
|---|---|---|---|
| view | yes | only with `ledger.scope.institution.read` | yes — agency accounts must be filed under them |
| balance / movements | yes (consolidated within the agency where relevant) | via view rules | computed, but **not returned** without `ledger.scope.institution.read`, since the figures consolidate every agency |
| create | yes | refused (422) unless `ledger.scope.institution.manage` | needs `ledger.scope.institution.manage` |
| update / archive | yes | forbidden | needs `ledger.scope.institution.manage` |

Two deliberate asymmetries:

- **An institution account is readable, but its consolidated figure is not returned to
  agency staff.** Consolidation itself is never conditional — the calculator always rolls
  up a grouping account's subtree, and no consolidation logic is permission-aware. What
  is gated is *reading the resulting number*: an institution account's children live in
  different agencies by design, so its consolidated balance is inherently cross-agency
  information. An agency accountant gets `200` on the account (needed to file children
  under it) and `403` on `/balance` and `/movements`. Gated in
  [`AccountingBalanceWorkflow::canReadInstitutionAggregate()`](../../app/Application/Accounting/AccountingBalanceWorkflow.php),
  not in the policy, because the policy answers "may you see this chart entry".
  *Agency-level* grouping accounts consolidate too and are **not** gated — their subtree
  never leaves the agency.
- **`ledger.scope.institution.manage` also permits creating into any agency's chart.**
  That is what deploying the institution chart across agencies will need (see next steps).

### i18n

New `domain.*` keys in `lang/en/domain.php` and `lang/fr/domain.php`
(`ledger_agency_account_requires_agency`, `ledger_institution_account_has_no_agency`,
`ledger_institution_account_not_postable`, `ledger_institution_parent_must_be_institution`,
`ledger_grouping_account_not_postable`, `ledger_parent_has_movements`,
`ledger_account_not_postable`). The obsolete "safe slice" string was removed from
`lang/en.json` and `lang/fr.json`.

## 4. Invariants — do not break these

1. **Institution scope stays `agency_id IS NULL`.** Do not add an `institution_id`
   FK to any table. The unpostability guarantee depends on it.
2. **Never sum consolidated report rows** to produce a total. Totals come from posted
   accounts only.
3. **A grouping account never receives an entry.** If you add a new posting path,
   check `is_postable` — the composite FK only protects the institution level.
4. **`InstitutionProfile::current()` in read paths, `singleton()` in write paths.**
5. **Cross-agency parenting stays refused.** Two agencies may share an institution
   parent; they may never parent each other's accounts.
6. **Every ledger-account read and write is agency-scoped.** A new route touching
   `ledger_accounts` must go through `LedgerAccountPolicy` (or an equivalent check) —
   permission alone is not authorisation now that a non-admin role holds these
   permissions.
7. `config/money.php` and `accounting_days` remain authoritative for currency and
   calendar; the profile's equivalents are declarative.

## 5. Not done / next steps

- **Chart provisioning (the biggest remaining win).** There is still no PCEMF chart
  seeder anywhere; each agency's chart is built by hand. The institution level now
  makes "define the institution chart once, derive each agency's detail accounts from
  it" possible — e.g. generate `571001` under `571000` per agency. This was explicitly
  scoped out. `chief-accountant` is the role that would drive it: it can already write
  into every agency's chart.
- **Maker-checker on operation-account mappings.** See the control weakness noted above:
  posting rules can be authored and approved by the same actor. Verified while reviewing
  this change, and worth knowing before anyone designs the fix: the 8-state
  `approval_status` enum on that table is not aspirational — a full lifecycle
  (submit/approve/reject/suspend/revoke/archive, with a transition table and per-decision
  audit rows) already exists over the *same* `operation_account_mappings` table, but only
  behind the Islamic-module routes. The generic
  [`OperationAccountMappingController`](../../app/Http/Controllers/Api/V1/OperationAccountMappingController.php)
  predates it and bypasses it: `store()` accepts `approval_status: "approved"` and stamps
  the author into `approved_by_user_id`, and `update()` accepts any of the 8 states in any
  direction. **No path enforces approver ≠ author**, the Islamic one included — its
  self-approval guard only fires for "material" subjects and a mapping is not one. So the
  work is mostly routing the generic path through a lifecycle and adding an
  `operation.mappings.approve` permission distinct from `create`, not inventing the state
  machine. Islamic finance is next-version, so treat that module as reference only.
- **`crm.scope.institution.manage` remains platform-admin.** Cross-agency client data was
  not revisited. If the `chief-accountant` precedent is right, that deserves the same
  question for a compliance role.
- **Regulatory reporting is gated by role, not permission.** `RegulatoryReportingWorkflow`
  uses `hasRole('platform-admin')` in four places, so registering COBAC sources and
  submitting returns cannot be delegated without a code change. Converting those to
  permissions would let head office own the filing chain end to end.
- **`public/docs/api.json`** is a committed generated artifact with no freshness test
  and is now stale (new routes, new permissions). Regenerate when convenient.
- **Institution accounting-day filter on statements.** Filtering a *grouping* account's
  statement by an agency-scoped accounting day 422s, because
  `accountingDayMatchesAgency()` requires an institution-scope day for a NULL-agency
  account. Pre-existing behaviour, left alone; revisit if consolidated statements need
  per-agency day filters.
- **Group consolidation (agencies as separate legal entities)** with inter-company
  elimination remains out of scope — case 2 of the original report. Today's model
  assumes **one EMF per database**; note that `2026_04_28_052156_...` uses "tenant" to
  mean *agency*, so a genuine multi-EMF deployment would mean revisiting every unique
  index and composite FK.

### Checked, not a problem

The EMF mapping gates (`ReportRunController::unmappedLedgerAccounts()` and
`MappingCompletenessGate`) are driven by posted journal lines and explicitly mapped
accounts. Institution accounts can never be posted to or mapped, so they cannot appear
as "unmapped" and cannot block EMF report generation.

## 6. Tests and verification

- [`tests/Feature/Module3AccountingArchitectureTest.php`](../../tests/Feature/Module3AccountingArchitectureTest.php)
  — 10 cases: institution creation, permission gating, both parent-direction rules,
  cross-agency refusal, auto-conversion, the movements guard, posting rejection,
  consolidated balances, consolidated trial balance, and code uniqueness in both
  namespaces.
- [`tests/Feature/Api/Module1AdministrationTest.php`](../../tests/Feature/Api/Module1AdministrationTest.php)
  — institution profile read/update, partial patch, currency normalisation, audit row,
  `manage` gating, second-row rejection, protected-permission listing.
- Role boundaries, in the same file: the accountant maintaining its own agency's chart;
  the accountant blocked from another agency's chart, the institution chart, and
  consolidated figures; the chief accountant creating institution accounts, deploying
  into every agency and reading consolidated balances **with no agency assignment**;
  the chief accountant opening and starting the close of the institution period and
  listing days without an agency assignment, while holding no reopen permission; the
  agency accountant still refused the institution period; and a journal entry without an
  agency rejected as 422 rather than failing at the database.
- [`tests/Feature/Api/RegulatoryReportingTest.php`](../../tests/Feature/Api/RegulatoryReportingTest.php)
  — declarant snapshotted into an EMF run; an unconfigured institution yields nulls
  without blocking the run.

Three assertions were updated rather than added:

- `test_ledger_account_creation_requires_agency_scope` checked the old
  "périmètre sécurisé" French string. Behaviour is unchanged, wording is not.
- `Module1AdministrationTest::test_operational_roles_receive_identity_and_balance_permissions`
  asserted that `accountant` must not hold `ledger.accounts.view` — a privilege-creep
  guard from an earlier fix, directly contradicted by the grant in §3. The loop now
  covers `teller` and `loan-officer` only and records why the accountant is excluded.
  **If that grant is ever narrowed, put the accountant back in that loop.**
- `test_chief_accountant_runs_the_institution_accounting_period` asserted
  `data.scope_type`; [`AccountingDayResource`](../../app/Http/Resources/AccountingDayResource.php)
  exposes that column as `scope`.

Incidental: `FoundationSchemaIntegrityTest::test_ledger_account_cannot_parent_itself`
still passes but now trips two constraints (self-parent *and* the new non-postable
check) instead of one.

### Corrections made after the suite was actually run

The two tests named above were **red as committed** — the suite had not been run to
completion before `681ac22`. Behaviour was correct in both cases; the tests were not.
Worth recording because one of them exposed a real precondition:

with the `scope` key corrected, `test_chief_accountant_runs_the_institution_accounting_period`
failed one line later on the close-control gate. `start-close` hard-requires active
procedures for `accounting_close_verification` and `cash_close_verification`, so the
test now seeds `BatchProcedureSeeder` first, as
[`AccountingDayLifecycleTest`](../../tests/Feature/AccountingDayLifecycleTest.php) does.
That is the platform-admin dependency in the withheld-permissions table above surfacing
as a test precondition: **the chief accountant holds every permission `start-close`
checks, and still cannot close a day until a platform admin has configured the
close-control procedures.**

Three level-9 findings in this change's own code were fixed rather than suppressed:
`->getQuery()->exists()` on two relations in `LedgerAccountController` became
`DB::table(...)->exists()` (larastan treats the forwarded `Builder::exists()` as a
static call — see also `orderBy`/`whereIn`); two redundant `is_string()` guards in
`ReportRunController::consolidatedTrialBalanceRows()`'s comparator were dropped; and the
consolidated trial-balance assertions moved off an `array<string, array>` lookup map,
which level 9 rejects on literal-key access, onto a `consolidatedRow()` helper that
fails naming the missing code.

Unrelated to this change but required to get a green suite:
[`InsuranceProductLifecycleTest`](../../tests/Feature/Api/InsuranceProductLifecycleTest.php)
had drifted red on a hard-coded `effective_on => '2026-08-01'` that became *past* on
2026-08-02, flipping a deferred-cancellation case into an immediate one. Its clock is
now pinned (`travelTo(FROZEN_NOW)`, 2026-06-06 — the period its ~47 fixed dates were
written for) rather than each date rewritten as an offset, since those dates encode
relationships to each other, not only to now. The rest of the suite was swept for the
same hazard: `InsuranceModuleTest`'s `ends_on` and `IslamicFinanceTest`'s
`delivery_date` are fixed dates too, but neither is ever compared against `now()`, so
neither can drift.

### Gate

All three clean as of 2026-08-05, on `feat-entreprise`:

```bash
vendor/bin/pint --test                    # passed
vendor/bin/phpstan analyse --no-progress  # no errors
composer test                             # OK (1039 tests, 14904 assertions)
```
