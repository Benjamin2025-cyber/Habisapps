# GitHub Issues 8-9 Backend Remediation Backlog

Investigation date: 2026-06-06

Source repository: `Benjamin2025-cyber/Habisapps`

Source issues:

- GitHub issue #8: "Enhancement request - expose executable batch-procedure codes"
- GitHub issue #9: "Audit: security/domain events log the machine code as their description"

Method:

- Pulled the issue bodies from GitHub on 2026-06-06.
- Validated each claim against the current backend worktree.
- Created backlog items only for claims contradicted by current implementation evidence.

## Current-State Summary

- Issue #8 is legitimate. Executable batch-procedure code knowledge exists in backend execution dispatch, but there is no API catalog for the frontend to consume.
- Issue #9 is legitimate. Security/domain audit records currently store the same dotted machine code in both `event` and `description`.
- No code fix has been implemented in this backlog. This file defines the remediation scope and acceptance criteria.

## GHI-008: Expose Executable Batch-Procedure Code Catalog

Source issue: GitHub #8.

Status: Confirmed, fix pending.

Severity: Medium. The current behavior does not corrupt data, but it forces the frontend to hardcode backend execution capabilities and allows admins to create procedures that cannot execute.

### Evidence

- `app/Application/BatchRuns/ExecuteRegisteredBatchRun.php` owns executable-code knowledge through private code arrays and the loan-servicing hook support check.
- `routes/api/v1/auth.php` exposes `GET /batch-procedures`, create/show/update/status routes, and batch-run execution routes, but no executable-code catalog endpoint.
- `app/Http/Controllers/Api/V1/BatchProcedureController.php` validates `code` only as a unique string, not against an executable-code registry.
- `ExecuteRegisteredBatchRun::guardSupportedProcedure()` rejects unsupported procedure codes only at run execution time with `This batch procedure is not executable.`

### Contradiction Proof

- Assume the backend exposes the batch-procedure codes that can actually be executed.
- The current route table has no endpoint that returns executable procedure codes or executable metadata.
- The only authoritative executable-code list is private to execution dispatch.
- Therefore the frontend cannot discover executable codes from the API and must mirror backend source or accept late execution failures.

### Fix Backlog

- Introduce one authoritative executable batch-procedure registry shared by execution dispatch and API presentation.
- Include all currently executable normalized codes:
  `loan_arrears_assessment`, `loan_monthly_arrears_penalty`, `cash_close_verification`, `cash_daily_close`, `agency_cash_close`, `accounting_close_verification`, `accounting_daily_close`, `journal_close_verification`, plus every code supported by `ExecuteLoanServicingHooksBatch`.
- Expose the registry through a versioned authenticated endpoint, preferably `GET /api/v1/batch-procedures/executable-codes`.
- Return stable machine codes plus frontend-useful metadata: `code`, `label`, `description`, `group`, `default_schedule_type`, and `prerequisite_codes`.
- Add `executable` metadata to `BatchProcedureResource` and index responses so existing rows with unsupported codes can be warned on without a separate client-side join.
- Keep execution behavior strict: unsupported procedure codes must still fail execution with the existing 422 semantics until product decides whether create/update should reject non-executable codes.
- Regenerate API docs/OpenAPI after adding the endpoint and resource fields.

### Acceptance Criteria

- Feature test proves `GET /api/v1/batch-procedures/executable-codes` is authenticated and authorized consistently with batch-procedure browsing.
- Feature test proves the endpoint returns every code that `ExecuteRegisteredBatchRun` can dispatch, including aliases and loan-servicing hook codes.
- Unit or feature test proves execution dispatch and API catalog use the same registry source; adding a new handler code cannot require updating a second hardcoded frontend-facing list.
- Feature test proves response items include at least `code`, `label`, `description`, `group`, `default_schedule_type`, and `prerequisite_codes`.
- Feature test proves `GET /api/v1/batch-procedures` marks supported rows with `executable=true` and unsupported rows with `executable=false`.
- Feature test proves an unsupported procedure still returns the existing structured 422 execution failure, preserving current backend safety.
- OpenAPI/API docs expose the new catalog endpoint and the `executable` resource field.
- Frontend handoff note states the frontend must remove its hardcoded `batch-procedure-codes.ts` mirror and drive create-picker options plus unsupported-code warnings from the API.

## GHI-009: Give Security Audit Events Human-Readable Descriptions

Source issue: GitHub #9.

Status: Confirmed, fix pending.

Severity: Medium. The audit trail remains machine-readable, but human operators see duplicate dotted codes in the event and description columns, reducing audit usability.

### Evidence

- `app/Support/Security/SecurityAudit.php` calls `activity('security')->event($event)` and then `->log($event)`.
- `app/Http/Resources/AuditEventResource.php` returns both `event` and `description` directly from Spatie activity rows.
- `app/Application/Crm/ClientCrudWorkflow.php` records `crm.client.pii_list_viewed` and `crm.client.pii_viewed` through `SecurityAudit::record()`.

### Contradiction Proof

- Assume security/domain audit events expose a human-readable description while preserving machine event codes.
- `SecurityAudit::record()` writes the event machine code into both the `event` field and the Spatie activity description.
- `AuditEventResource` exposes both values as-is to the API.
- Therefore security/domain audit rows currently expose the same raw dotted code as both `event` and `description`, contradicting the frontend need for readable audit descriptions.

### Fix Backlog

- Extend `SecurityAudit::record()` to support a human-readable description while keeping the machine code in `event`.
- Prefer a central label registry for known security/domain events, with an optional explicit description parameter for events whose text needs runtime context.
- Preserve the machine code in the `event` column and/or sanitized properties for filtering, alert rules, and audit correlation.
- Provide readable labels for all current direct `SecurityAudit::record()` callers, at minimum `crm.client.pii_list_viewed`, `crm.client.pii_viewed`, `batch.procedure.created`, `batch.procedure.updated`, and `batch.procedure.status_changed`.
- Define fallback behavior for unmapped events. The fallback must be deliberate and documented, for example title-casing the dotted code or logging the raw code while marking it as unmapped in properties.
- Keep sensitive values out of descriptions and properties; descriptions must not include PII, phone numbers, OTPs, tokens, raw IPs, or internal numeric IDs.
- Regenerate API docs/OpenAPI only if the audit-event resource contract changes; if only values change, document the behavior in the audit/security domain docs.

### Acceptance Criteria

- Unit test proves `SecurityAudit::record('crm.client.pii_viewed', ...)` stores `event='crm.client.pii_viewed'` and a non-identical human-readable `description`.
- Unit test proves `SecurityAudit::record('crm.client.pii_list_viewed', ...)` stores a readable description and keeps request hashes/properties sanitized.
- Feature test for `GET /api/v1/audit-events` proves API responses expose the machine event code separately from the readable description.
- Regression test proves model CRUD audit events still serialize their existing readable verbs and are not broken by the security-audit change.
- Test proves unmapped event fallback behavior is deterministic, documented, and does not crash.
- Test proves audit descriptions do not leak sensitive properties when actor, subject, request IP, user agent, or caller properties are present.
- Existing frontend translation of standard model verbs remains compatible; no frontend change is required to display readable security descriptions.
- Documentation states that `event` is the stable machine key for filtering and `description` is the operator-facing text.

## Verification Commands

Run focused backend tests after implementing fixes:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/Module1AdministrationTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/FoundationOperationsTest.php
php artisan test --parallel --recreate-databases --filter=batch_procedure
php artisan test --parallel --recreate-databases --filter=audit
```

Run full verification before closing the backlog:

```bash
composer test
```
