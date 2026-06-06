# Batch-Procedure Executable Catalog & Audit-Event Descriptions

Covers the backend remediation for GitHub issues #8 and #9
(see `backlogs/github-issues-8-9-backlog.md`).

## GHI-008 — Executable batch-procedure catalog

### Single source of truth

`App\Application\BatchRuns\BatchProcedureRegistry` is the one authoritative list
of batch-procedure codes the backend can execute. It is consumed by:

- **Execution dispatch** — `ExecuteRegisteredBatchRun` routes a run to its handler
  via `BatchProcedureRegistry::handlerFor()`, and `ExecuteLoanServicingHooksBatch`
  derives both `supports()` and its portfolio/notification variant from the
  registry. Neither executor keeps its own hardcoded code list anymore.
- **API presentation** — the catalog endpoint and the `executable` flag on
  `BatchProcedureResource` read the same registry.

Adding support for a new executable code means adding **one** entry in
`BatchProcedureRegistry::definitions()`. There is no second frontend-facing list
to update.

### Catalog endpoint

`GET /api/v1/batch-procedures/executable-codes`

- Authenticated (Sanctum) and authorized exactly like batch-procedure browsing
  (`viewAny` on `BatchProcedure`, i.e. `batch.procedures.view` or
  `batch.procedures.manage`).
- Returns `data.executable_codes`, a list of items with:
  `code`, `label`, `description`, `group`, `default_schedule_type`,
  `prerequisite_codes`.

### `executable` flag

`BatchProcedureResource` (index + show) now includes a boolean `executable`.
Rows whose `code` is in the registry are `executable: true`; rows with an
unsupported code are `executable: false`. Code matching is normalized
(case-insensitive, hyphen/underscore interchangeable).

### Execution safety is unchanged

Creating or updating a batch procedure with a non-executable code is still
allowed (product has not decided whether to reject at create/update time).
Executing a run for an unsupported code still fails with the existing
`422 Unprocessable` and message `This batch procedure is not executable.`.

### Frontend handoff note

- **Remove the hardcoded `batch-procedure-codes.ts` mirror.** The frontend must
  no longer duplicate the backend's executable-code list in source.
- Drive the create/edit picker options from
  `GET /api/v1/batch-procedures/executable-codes` (`code` + `label` + `group`,
  with `default_schedule_type` and `prerequisite_codes` as form defaults/hints).
- Use the `executable` flag on batch-procedure rows to warn operators about
  existing procedures whose code can never execute — no client-side join against
  a mirrored list is required.

## GHI-009 — Human-readable audit-event descriptions

### Contract

For security/domain audit rows (`log_name = security`):

- **`event`** is the stable machine key — a dotted code such as
  `crm.client.pii_viewed`. It is what you filter, alert, and correlate on
  (`GET /api/v1/audit-events?event=<code>`). It never changes wording.
- **`description`** is the operator-facing, human-readable text such as
  `Client PII record viewed`. It is for display only; do not parse or filter on
  it.

`AuditEventResource` exposes both fields. They are now distinct values for
security events (previously both held the same dotted code).

### Where descriptions come from

`SecurityAudit::record()` resolves the description through
`App\Support\Security\SecurityEventCatalog`:

1. If a caller passes an explicit `description`, it is accepted only after the
   backend rejects sensitive-looking runtime values such as phone numbers, OTPs,
   tokens, raw IPs, or long internal numeric IDs.
2. Otherwise a curated label is looked up for the event code.
3. Unmapped codes fall back to a **deterministic** title-cased rendering of the
   dotted code (e.g. `some.new_event` → `Some New Event`). This never throws and
   keeps the raw machine key recoverable from `event`.

Catalog and fallback descriptions are derived from the **event code alone**.
They never incorporate actor, subject, request, or caller property values. Any
explicit description is treated as display text only and must pass the same
no-sensitive-values guard before it can be persisted. Request context is stored
only as salted-free SHA-256 hashes (`ip_hash`, `user_agent_hash`) in properties.

### Compatibility

- Model CRUD audit events (Spatie `LogsActivity` via `HasAuditLog`) are
  unaffected: their `description` is still the standard verb (`created`,
  `updated`, `deleted`). No frontend change is needed to display them.
- Security event filtering by `event` is unchanged. Only the `description`
  *value* for security events became human-readable, so the API resource
  contract did not change shape — OpenAPI was regenerated for the new
  batch-procedure endpoint and `executable` field only.
