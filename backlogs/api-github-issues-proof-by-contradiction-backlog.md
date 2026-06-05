# API GitHub Issues - Proof-by-Contradiction Remediation Backlog

Date: 2026-06-05

Scope: API repo issues from `Benjamin2025-cyber/Habisapps` issues #2 through #6. Frontend screenshots are treated only as external evidence of expected fields and workflows; the defects below are backend API contracts, migrations, seed data, or workflow behavior.

## Method

For each issue, the audit assumes the codebase is correct, then checks what would have to be true for that assumption to hold. Where the required condition is absent from the API code, the issue is confirmed and converted into backlog-ready work.

## API-ISSUE-002 - Accounting-Day Close Can Deadlock When Teller Sessions Are Still Open

Source: GitHub issue #2, critical.

Contradiction tested: starting accounting-day close cannot trap the day in `closing`, because either start-close refuses open teller sessions, teller close/reconciliation is allowed while closing, or the close flow auto-closes sessions.

Evidence:

- `routes/api/v1/accounting.php:35` to `routes/api/v1/accounting.php:43` marks accounting-day lifecycle actions as `day_lifecycle`.
- `routes/api/v1/accounting.php:151` and `routes/api/v1/accounting.php:156` leave teller-session close and reconciliation without an accounting-day classification.
- `app/Application/AccountingDays/AccountingDayWorkflow.php:198` to `app/Application/AccountingDays/AccountingDayWorkflow.php:203` changes the day to `closing` before close readiness is resolved.
- `app/Application/AccountingDays/AccountingDayWorkflow.php:226` to `app/Application/AccountingDays/AccountingDayWorkflow.php:229` explicitly states registrations are blocked after start-close.
- `app/Application/AccountingDays/AccountingDayWorkflow.php:253` to `app/Application/AccountingDays/AccountingDayWorkflow.php:280` can keep the day in `closing` when readiness or close-control batches block final close.

Conclusion: confirmed. The API can enter `closing` before open teller sessions are resolved, while teller-session close/reconciliation remains classified like ordinary registration by omission.

Recommended backend decision for v1: block `start-close` before changing the day status when open teller sessions exist. Do not auto-close sessions in v1 because it creates audit and cash-accountability ambiguity.

Acceptance criteria:

- `POST /accounting-days/{accountingDay}/start-close` returns `422` when the targeted day/scope has open teller sessions.
- The failed response includes a stable machine code such as `open_teller_sessions`, the number of open sessions, and enough public identifiers for the frontend/admin to route operators to the blocking sessions.
- When start-close is blocked by open sessions, the accounting day remains `open` or `reopened`; it must not become `closing`, must not increment `write_lock_version`, and must not create close-control batch runs.
- Teller-session close and reconciliation continue to work while the day is still open/reopened.
- After all teller sessions are closed/reconciled, start-close can move the day to `closing`.
- Regression tests cover the deadlock scenario: open teller session, start-close attempt, asserted `422`, day still open, teller close succeeds, start-close succeeds afterward.

Implementation notes:

- Prefer adding a preflight in `AccountingDayWorkflow::startClose()` before the transaction that sets status to `closing`.
- Reuse the same scope/date rules used by cash close verification so the preflight and final close controls cannot disagree.
- Keep any future alternative, such as allowing teller close during `closing`, behind an explicit product decision because it changes accounting-day lock semantics.

## API-ISSUE-003 - Required Close-Control Batch Procedures Are Not Seeded

Source: GitHub issue #3, critical.

Contradiction tested: fresh installs can run accounting-day close controls because required executable batch procedures are seeded.

Evidence:

- `app/Application/AccountingDays/AccountingDayWorkflow.php:414` to `app/Application/AccountingDays/AccountingDayWorkflow.php:419` hard-requires `accounting_close_verification` and `cash_close_verification`.
- `app/Application/AccountingDays/AccountingDayWorkflow.php:426` to `app/Application/AccountingDays/AccountingDayWorkflow.php:440` records `missing_procedure` when no active `BatchProcedure` exists.
- `app/Application/BatchRuns/ExecuteRegisteredBatchRun.php:14` to `app/Application/BatchRuns/ExecuteRegisteredBatchRun.php:29` lists executable procedure codes for loan arrears, cash close, and accounting close.
- `database/seeders/DatabaseSeeder.php:11` to `database/seeders/DatabaseSeeder.php:18` seeds roles, reports, and bootstrap admin only; it does not seed batch procedures.

Conclusion: confirmed. A fresh seeded environment can have executable batch code but no active `batch_procedures` rows for the close workflow to call.

Recommended backend decision for v1: add an idempotent required batch-procedure seeder and call it from `DatabaseSeeder`.

Acceptance criteria:

- `php artisan migrate:fresh --seed` creates active `BatchProcedure` rows for `accounting_close_verification` and `cash_close_verification`.
- The seeder is idempotent: repeated runs update the same logical procedures and do not create duplicate codes, including hyphen/underscore variants.
- Seeded procedure codes match the normalization expected by `AccountingDayWorkflow::executeCloseControlRuns()`.
- The seeder also covers every code supported by `ExecuteRegisteredBatchRun`, or the backlog/implementation explicitly documents why only close-control procedures are seeded for v1.
- Day-close tests on a fresh seeded database no longer fail with `missing_procedure`.
- A feature test asserts that start-close creates or reuses close-control `BatchRun` rows when the required seeded procedures exist.

Implementation notes:

- Use `updateOrCreate` keyed by normalized procedure code.
- Seed stable names/descriptions/status and any prerequisite metadata required by the executor.
- Keep procedure status `active` for the two close-control procedures required by accounting-day close.

## API-ISSUE-004 - Guarantor And Proxy KYC Evidence Is Still Single-Image / Untyped

Source: GitHub issue #4.

Contradiction tested: guarantor and proxy KYC evidence follows the same typed recto/verso identity-document contract already used for client identity documents.

Evidence:

- `app/Models/ClientGuarantor.php:13` to `app/Models/ClientGuarantor.php:33` has `document_id` only; no document type and no back-document relationship.
- `app/Http/Requests/Api/V1/StoreClientGuarantorRequest.php:20` to `app/Http/Requests/Api/V1/StoreClientGuarantorRequest.php:28` accepts only `document_public_id`.
- `app/Http/Requests/Api/V1/UpdateClientGuarantorRequest.php:23` to `app/Http/Requests/Api/V1/UpdateClientGuarantorRequest.php:31` accepts only `document_public_id`.
- `app/Models/ClientProxy.php:42` to `app/Models/ClientProxy.php:68` has free-form `proxy_id_document_type` and a single `document_id`.
- `app/Http/Requests/Api/V1/StoreClientProxyRequest.php:25` validates `proxy_id_document_type` only as nullable string.
- `app/Http/Requests/Api/V1/UpdateClientProxyRequest.php:28` also validates `proxy_id_document_type` only as nullable string.
- `app/Support/Crm/IdentityDocumentTypeCatalog.php:19` to `app/Support/Crm/IdentityDocumentTypeCatalog.php:26` already defines accepted document types and required face counts.
- `app/Http/Resources/ClientIdentityDocumentResource.php` exposes `back_document_public_id`, while guarantor/proxy resources expose only a single `document_public_id`.

Conclusion: confirmed unless stakeholders intentionally want weaker guarantor/proxy evidence than client identity evidence. The current API does not enforce the catalog or support two-sided documents for guarantors/proxies.

Recommended backend decision for v1: extend guarantor and proxy KYC evidence to reuse the client identity-document catalog and recto/verso rules. Avoid inventing a separate document-type vocabulary.

Acceptance criteria:

- Guarantor records support a catalog-backed `document_type`, `document_public_id` front face, and nullable `back_document_public_id`.
- Proxy records support catalog-backed `proxy_id_document_type`, existing front document linkage, and nullable `back_document_public_id` or a clearly named proxy-specific equivalent.
- Store and update requests validate document types with `IdentityDocumentTypeCatalog::keys()`.
- For document types whose catalog `required_faces` is `2`, verification cannot complete unless both front and back documents are present.
- Back document must be different from the front document.
- Front and back documents must be active and belong to the same agency scope as the client record.
- API resources return front and back document public IDs and the validated document type.
- Existing records with only `document_id` remain readable after migration; new validation requirements apply to verification or new submissions according to the product decision.
- Feature tests cover valid one-face document types, valid two-face document types, invalid document types, same-document front/back rejection, cross-agency document rejection, and verification blocking when a required back face is missing.

Implementation notes:

- Mirror the strongest existing behavior in `ClientIdentityDocumentController` rather than duplicating ad hoc validation.
- Add nullable columns first for backwards compatibility, then enforce verification-time completeness.
- Decide field names before migration. For proxy, prefer compatibility with existing `proxy_id_document_type` while adding the back-face field.

## API-ISSUE-005 - Loan-Product Penalty Fields Are Writable But Not Used By The Penalty Engine

Source: GitHub issue #5.

Contradiction tested: loan-product penalty fields are meaningful because the penalty engine consumes them, either from the current product or from the loan snapshot.

Evidence:

- `app/Http/Requests/StoreLoanProductRequest.php:60` to `app/Http/Requests/StoreLoanProductRequest.php:63` accepts `penalty_formula_type`, `penalty_formula_base`, `penalty_value_type`, and `penalty_value`.
- `app/Http/Requests/UpdateLoanProductRequest.php:140` to `app/Http/Requests/UpdateLoanProductRequest.php:143` accepts the same fields on update.
- `app/Support/Finance/LoanProductFormulaPolicySnapshotter.php:95` to `app/Support/Finance/LoanProductFormulaPolicySnapshotter.php:99` snapshots those product penalty fields into the loan policy snapshot.
- `app/Application/Loans/AssessLoanArrearsAndPenalties.php:191` to `app/Application/Loans/AssessLoanArrearsAndPenalties.php:193` calculates penalty as fixed amount plus percent.
- `app/Application/Loans/AssessLoanArrearsAndPenalties.php:255` to `app/Application/Loans/AssessLoanArrearsAndPenalties.php:267` reads fixed and percentage penalty values from global config, not from the product fields or loan snapshot.
- `app/Application/Loans/AssessLoanArrearsAndPenalties.php:245` to `app/Application/Loans/AssessLoanArrearsAndPenalties.php:252` uses only `penalty_grace_days` from the product.

Conclusion: confirmed. The API contract implies product-level penalty configuration, but the executable arrears/penalty engine ignores it except for grace days.

Recommended backend decision for v1: wire the product penalty fields into arrears assessment using the loan snapshot as the source of truth. Do not silently keep writable fields that do not affect behavior.

Acceptance criteria:

- Product penalty fields have documented semantics for all accepted enum values in `LoanProduct::PENALTY_FORMULA_TYPES`, `LoanProduct::PENALTY_FORMULA_BASES`, and `LoanProduct::PENALTY_VALUE_TYPES`.
- Arrears assessment reads penalty settings from `loans.formula_policy_snapshot.product_terms` when present, so historical loans are not changed by later product edits.
- If a loan has no snapshot penalty terms, the engine falls back to current product fields; if neither exists, it falls back to approved global formula-policy config.
- Percentage penalties apply to the selected formula base, with explicit tests for at least `overdue_amount`, `unpaid_scheduled_due`, and one principal-based base.
- Fixed penalties use `penalty_value` as a money amount in minor units or an explicitly documented decimal money convention.
- Invalid or incomplete product penalty combinations fail validation before product activation or before loan creation.
- Regression tests prove two products with different penalty terms produce different arrears penalties under the same arrears scenario.
- Existing config-driven tests continue to pass for products with no penalty fields.

Implementation notes:

- Centralize interpretation in a small resolver/service so request validation, snapshotting, and the penalty engine share the same meaning.
- Keep formula-policy approval as a gate, but let product terms provide the product-specific parameters.
- If stakeholders reject product-level penalties for v1, remove these fields from writable requests/resources and document them as out of scope instead. That is a product decision, not a frontend-only fix.

## API-ISSUE-006 - Client Photo Lists Require N Authenticated Blob Fetches

Source: GitHub issue #6, enhancement.

Contradiction tested: client list/profile responses already provide a frontend-friendly image URL or thumbnail, avoiding one authenticated file request per displayed avatar.

Evidence:

- `app/Models/Client.php:35` to `app/Models/Client.php:39` stores a profile photo document link.
- `app/Http/Resources/ClientResource.php:34` to `app/Http/Resources/ClientResource.php:38` returns only `profile_photo_document_public_id`; it does not return a URL or thumbnail URL.
- `routes/api/v1/auth.php:63` to `routes/api/v1/auth.php:66` exposes document metadata and authenticated file download routes only.
- `app/Http/Controllers/Api/V1/DocumentController.php:187` to `app/Http/Controllers/Api/V1/DocumentController.php:209` streams the original document file after authenticated authorization.
- `app/Http/Resources/DocumentResource.php:25` to `app/Http/Resources/DocumentResource.php:38` returns metadata only and no signed file URL.

Conclusion: confirmed as an enhancement. The API is functional but inefficient for avatar-heavy UI, print, and export flows.

Recommended backend decision for v1.1: add a short-lived signed thumbnail URL for client profile photos, exposed by `ClientResource` only when the actor is authorized to view operational identity.

Acceptance criteria:

- `ClientResource` includes `profile_photo_url` or `profile_photo_thumbnail_url` when a client has an active profile-photo document and the requester can view operational identity.
- The URL serves a thumbnail-sized image suitable for avatars and list views, not the full original document.
- The URL can be used by a browser image tag without a bearer token, but only while the temporary signature is valid.
- Tampered or expired URLs return `403`.
- Missing, archived, non-image, or unauthorized profile photos return `null`.
- The thumbnail response sets correct `Content-Type`, conservative cache headers, and does not expose cross-agency files.
- Feature tests cover authorized URL generation, redacted identity response, expired/tampered URL rejection, cross-agency rejection, and non-image fallback.
- The existing authenticated `documents/{document}/file` route remains available for full-document preview/download.

Implementation notes:

- A signed thumbnail endpoint is safer than exposing permanent file paths.
- Generate signed URLs only from already-authorized resource serialization, then validate signature and document constraints again at fetch time.
- Consider adding signed `file_url` to `DocumentResource` later, but keep this issue focused on profile-photo thumbnails.

## Prioritization

1. API-ISSUE-002 and API-ISSUE-003 are release blockers because they can prevent accounting-day close on a fresh or realistic environment.
2. API-ISSUE-005 is high priority because it exposes a configurable product contract that currently does not affect penalty calculation.
3. API-ISSUE-004 is high priority for KYC correctness, but it needs a quick stakeholder confirmation if guarantor/proxy evidence is intentionally weaker than client identity evidence.
4. API-ISSUE-006 is a performance/usability enhancement and should not block core v1 unless client avatar-heavy screens are part of the mandatory demo flow.

## Verification Checklist

- Every open API issue from #2 to #6 is represented in this backlog.
- Each item has a contradiction test, API evidence, conclusion, recommended fix path, and acceptance criteria.
- No item assumes the issue belongs in the frontend repo.
- No item assumes bancassurance is part of v1 delivery.
