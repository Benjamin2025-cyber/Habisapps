# Laravel 13 Professional API Hardening Backlog

This backlog turns `docs/laravel-13-api-guidance.md` into implementation work for this API. It is framework and architecture hardening, not a business module. It should be implemented before adding large formula-dependent modules, because the work reduces authorization drift, controller complexity, observability gaps, and API contract risk across all future modules.

Laravel baseline:

- Laravel Framework `13.6.0`
- PHP `^8.4`
- Sanctum `^4.0`
- Scramble `^0.13.20`

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.
- When Laravel provides a generator for an artifact, use the Laravel/Artisan command and record the exact command under the story.

## Implementation Status

Implemented and verified:

- [x] API-0101
- [x] API-0102
- [x] API-0103
- [x] API-0201
- [x] API-0202
- [x] API-0401
- [x] API-0402
- [x] API-0501
- [x] API-0502
- [x] API-0601
- [x] API-0301
- [x] API-0302
- [x] API-0701
- [x] API-0801

Verification completed during implementation:

- [x] `vendor/bin/pint --test`
- [x] `vendor/bin/phpstan analyze`
- [x] `php artisan test`
- [x] `php artisan scramble:export`

## Guiding Rules

- [x] Do not change business behavior while doing framework hardening unless a test proves the existing behavior is unsafe.
- [x] Prefer Laravel 13 native mechanisms: policies, Form Requests, scoped route bindings, middleware, service container bindings, API Resources, exception configuration, rate limiters, and feature tests.
- [x] Use Laravel/Artisan scaffolding commands whenever Laravel provides them, then adjust manually.
- [x] Record exact Artisan commands used under the relevant story.
- [x] Keep API responses backward-compatible unless the current response leaks internal IDs, sensitive data, or ambiguous authorization semantics.
- [x] Keep finance formula behavior out of scope. This backlog must not implement balance, posting, interest, fee, repayment, cash, or reporting formulas.
- [x] Every completed epic must include an adversarial review before moving to the next epic.

## Epic 1: Policy-First Authorization

- [x] API-0101: Inventory and classify authorization checks.

As a maintainer, I want a clear map of current authorization paths so policy migration does not create permission regressions.

Acceptance criteria:

- [x] Controllers with inline `hasRole`, `can`, `hasPermissionTo`, and agency-scope checks are listed.
- [x] Existing policies and Form Request `authorize()` methods are mapped to their controllers.
- [x] Each endpoint is classified as policy-ready, request-authorized, mixed, or manual-only.
- [x] Risky manual-only endpoints are prioritized.
- [x] No code behavior changes are made in this story.

Command log:

- [x] No generator command expected unless adding a documentation artifact. Artifact added manually: `docs/architecture/authorization-inventory.md`.

- [x] API-0102: Move resource authorization into policies and Form Requests.

As an API maintainer, I want authorization decisions centralized so multi-agency rules do not drift between controllers.

Acceptance criteria:

- [x] Accounting resource access checks use policies where practical for ledger accounts, customer accounts, account holds, journal entries, journal lines, sectors, and sub-sectors.
- [x] Accounting create/update permission checks live in Form Request `authorize()` methods where practical.
- [x] Agency-scope authorization remains fail-closed for accounting list/store relationship checks.
- [x] Accounting controllers retain only domain integrity checks that require resolved related records.
- [x] Focused tests prove existing accounting allowed and denied cases still behave the same.
- [x] CRM/KYC controllers with manual action maps are migrated where practical without weakening self-review and override rules.

Command log:

- [x] No `make:policy` or `make:request` command used; existing policies and Form Requests were hardened.

Adversarial review:

- [x] Searched migrated CRM/accounting controllers for leftover duplicated scope helpers and raw resource permission checks.
- [x] Fixed the finding that nested CRM create endpoints still used controller-local parent-client scope helpers by adding `createForClient` policy methods.
- [x] Fixed the final verification finding that staff assignment transfer creation accepted a `transfer_from_assignment_public_id` outside the actor agency scope.
- [x] Left explicit override and PII checks in controllers because they gate non-resource-sensitive branches and audit behavior.
- [x] Verified serially with `php artisan test --filter=PolicyAuthorizationHardeningTest`, `php artisan test --filter=Module2CrmKycTest`, and `php artisan test --filter=Module3AccountingArchitectureTest`.

- [x] API-0103: Add policy regression tests for high-risk resources.

As a reviewer, I want executable proof that policy migration did not widen access.

Acceptance criteria:

- [x] Tests cover platform-admin access, agency-scoped access, institution-read access, and denied cross-agency access.
- [x] Tests cover mutation denial for users with read-only permission.
- [x] Tests include at least one accounting resource and one CRM/KYC resource.
- [x] Tests assert JSON 403 responses do not leak internals.

Command log:

- [x] `php artisan make:test Api/PolicyAuthorizationHardeningTest`

## Epic 2: Scoped Route Model Binding

- [x] API-0201: Introduce scoped binding for nested CRM resources.

As an API consumer, I want nested resource URLs to be structurally safe so a child record from another parent cannot resolve under the wrong parent.

Acceptance criteria:

- [x] Nested client routes are reviewed for identity documents, guarantors, and proxies.
- [x] Scoped route binding rejects cross-parent resource access.
- [x] Controller-level ownership checks remain as defense in depth.
- [x] Tests prove a valid child public ID from another client is rejected.
- [x] API documentation still exports cleanly.

Command log:

- [x] No generator command expected; test added to existing `tests/Feature/Api/PolicyAuthorizationHardeningTest.php`.
- [x] `php artisan scramble:export`

Adversarial review:

- [x] Valid nested child URLs still pass through existing CRM/KYC feature tests.
- [x] Wrong-parent nested child public IDs fail closed with `404`.
- [x] Controller ownership checks were intentionally retained as defense in depth.

- [x] API-0202: Introduce scoped binding for accounting relationships where safe.

As an API maintainer, I want accounting route bindings to reject structurally invalid resource combinations before mutation logic runs.

Acceptance criteria:

- [x] Accounting routes are reviewed for nested or relationship-bound resources.
- [x] No new nesting was introduced because current accounting endpoints are flat public-ID routes and adding nested alternatives would expand the API surface.
- [x] Journal line, account hold, and customer account access remains public-ID based.
- [x] Existing tests prove invalid cross-scope resource combinations fail closed.

Command log:

- [x] No route or test scaffolding command used.

Review artifact:

- [x] `docs/architecture/scoped-route-binding-review.md`

## Epic 3: Application Services And Transaction Boundaries

- [x] API-0301: Extract CRM/KYC orchestration from controllers.

As a maintainer, I want complex CRM/KYC workflows outside controllers so they can be reused and tested without HTTP.

Acceptance criteria:

- [x] Candidate workflows are identified before extraction.
- [x] KYC status persistence and review creation moved into `App\Application\Crm\UpdateClientKycStatus`.
- [x] Transaction boundary lives in the service/action, not the controller.
- [x] Controller authorizes, checks request/evidence controls, calls service, returns resource.
- [x] Existing feature tests remain green.
- [x] New non-HTTP tests cover service invariants.

Command log:

- [x] `php artisan make:class Application/Crm/UpdateClientKycStatus`
- [x] `php artisan make:test Application/Crm/UpdateClientKycStatusTest --unit`

- [x] API-0302: Extract accounting lifecycle orchestration from controllers.

As a future finance implementer, I want accounting lifecycle rules isolated from HTTP so later jobs, commands, or integrations cannot bypass invariants.

Acceptance criteria:

- [x] Candidate workflows include journal submission, journal reversal, hold release, and customer account ledger-link changes.
- [x] Account hold release moved into `App\Application\Accounting\ReleaseAccountHold`.
- [x] Service tests cover invariants currently protected by feature tests.
- [x] HTTP tests still prove transport and authorization behavior.
- [x] No formula-dependent behavior is introduced.

Command log:

- [x] `php artisan make:class Application/Accounting/ReleaseAccountHold`
- [x] `php artisan make:test Application/Accounting/ReleaseAccountHoldTest --unit`

Adversarial review:

- [x] Confirmed extracted transaction boundaries now live in application services.
- [x] Confirmed formula-dependent behavior was not introduced.
- [x] Verified with `php artisan test --filter=UpdateClientKycStatusTest`, `php artisan test --filter=ReleaseAccountHoldTest`, `php artisan test --filter=Module2CrmKycTest`, and `php artisan test --filter=Module3AccountingArchitectureTest`.

## Epic 4: Exception Reporting And Structured Observability

- [x] API-0401: Harden exception reporting configuration.

As an operator, I want production exception reporting to be useful under failure, not noisy or leaky.

Acceptance criteria:

- [x] `bootstrap/app.php` keeps standardized JSON rendering for API routes.
- [x] Duplicate exception reporting is suppressed where Laravel supports it.
- [x] Safe global context is added for logs, such as API version and request correlation ID.
- [x] No PII, raw phone numbers, tokens, OTPs, passwords, internal integer IDs, or request bodies are added to global exception context.
- [x] Tests prove production API exceptions return generic JSON.

Command log:

- [x] `php artisan make:test Api/ProductionExceptionRenderingTest`

- [x] API-0402: Define production log channel and context strategy.

As an operator, I want logs to work well in Docker and VPS deployment without mixing audit evidence with diagnostics.

Acceptance criteria:

- [x] Production logging defaults are documented for Docker deployment.
- [x] Operational logs and security audit trails remain conceptually separate.
- [x] Structured context keys are documented and used consistently for new code.
- [x] Critical alert channel strategy is documented, even if not enabled by default.
- [x] Tests or static review prove no sensitive values are logged by new context code.

Command log:

- [x] No generator command expected; documentation added manually.

Review artifact:

- [x] `docs/operations/logging-strategy.md`

Adversarial review:

- [x] Confirmed exception context only includes `app_env`, `api_version`, sanitized `request_id`, and `route_name`.
- [x] Confirmed no request body, token, password, OTP, phone number, or internal integer ID is added globally.
- [x] Verified with `php artisan test --filter=ProductionExceptionRenderingTest`.

## Epic 5: Rate Limiting And Token Abilities

- [x] API-0501: Add route-specific rate limiters beyond auth.

As an operator, I want sensitive authenticated endpoints protected from runaway valid-token usage.

Acceptance criteria:

- [x] Named rate limiters exist for document upload, client creation, journal writes, audit browsing, and reference-number reservation.
- [x] Authenticated limits use actor identity where available.
- [x] Anonymous limits use IP address.
- [x] Tests prove throttled endpoints return standardized JSON 429 responses.
- [x] Existing auth rate limiters remain unchanged.

Command log:

- [x] `php artisan make:test Api/RouteRateLimitHardeningTest`

- [x] API-0502: Decide and enforce Sanctum token ability semantics.

As a security reviewer, I want token abilities to be either enforced or explicitly documented as non-authoritative.

Acceptance criteria:

- [x] Current token abilities from config are documented.
- [x] A decision is recorded: abilities are descriptive/deferred.
- [x] Authoritative ability enforcement is intentionally not enabled in this hardening pass.
- [x] Deferred docs explain that authorization is currently role/permission based, not token-ability based.
- [x] Missing-ability denial tests are not applicable because enforcement is deferred.

Command log:

- [x] No test scaffolding command used for API-0502.

Review artifacts:

- [x] `docs/security/sanctum-token-abilities.md`

Adversarial review:

- [x] Confirmed auth rate limiters were not changed.
- [x] Confirmed new limiter keys prefer user public IDs and fall back to IP.
- [x] Confirmed token abilities are not described as authoritative while routes do not enforce them.
- [x] Verified with `php artisan test --filter=RouteRateLimitHardeningTest`.

## Epic 6: API Resource Contract Hardening

- [x] API-0601: Audit and harden API Resources.

As an API consumer, I want stable response contracts that expose public identifiers and safe facts only.

Acceptance criteria:

- [x] All API Resources are reviewed for internal integer IDs.
- [x] All API Resources are reviewed for accidental PII exposure.
- [x] Relationship output uses explicit public IDs or nested resources, not raw model serialization.
- [x] Conditional exposure is used where caller permissions affect sensitive fields.
- [x] Tests cover high-risk audit event resource IDs.
- [x] `php artisan scramble:export` passes after changes.

Command log:

- [x] No `make:resource` or `make:test` command used; existing resource and test files were updated.

Review artifact:

- [x] `docs/architecture/api-resource-contract-audit.md`

Adversarial review:

- [x] Static scan found `AuditEventResource` internal integer IDs.
- [x] Fixed by removing `id`, `subject_id`, and `causer_id` from API output.
- [x] Fixed the follow-up finding that raw audit `properties` could still expose nested internal IDs or sensitive values by sanitizing resource properties recursively.
- [x] Verified with `php artisan test --filter=PolicyAuthorizationHardeningTest` and `php artisan scramble:export`.

## Epic 7: Testing Architecture

- [x] API-0701: Add service-level tests where orchestration is extracted.

As a maintainer, I want HTTP tests for contracts and service tests for domain invariants.

Acceptance criteria:

- [x] Existing feature tests remain the contract safety net.
- [x] New service/action classes have focused non-HTTP tests.
- [x] Tests cover authorization-sensitive denial paths at HTTP level.
- [x] Tests cover invariant-sensitive workflows at service level.
- [x] Helpers reduce duplicated setup without hiding important domain facts.

Command log:

- [x] `php artisan make:test Application/Crm/UpdateClientKycStatusTest --unit`
- [x] `php artisan make:test Application/Accounting/ReleaseAccountHoldTest --unit`

Adversarial review:

- [x] Confirmed service-level tests target extracted application services rather than duplicating controller-only assertions.
- [x] Confirmed HTTP feature tests remain the authorization and API contract safety net.
- [x] Confirmed test helpers keep agency, role, and domain setup explicit enough for authorization-sensitive reviews.

## Epic 8: Documentation And Migration Plan

- [x] API-0801: Maintain architecture guidance and implementation runbook.

As a future implementer, I want the Laravel 13 guidance and this backlog to stay aligned as work is completed.

Acceptance criteria:

- [x] `docs/laravel-13-api-guidance.md` is updated when implementation decisions differ from the initial recommendations.
- [x] This backlog is updated after each completed epic.
- [x] Any consciously deferred recommendation is documented with a reason. Current controller authorization has been converted to policies; only controller-local scope branches remain where they select records rather than decide permission.
- [x] Operational runbooks mention any new rate limiters, logging conventions, or token-ability requirements.
- [x] Final verification commands are recorded in this backlog.

Command log:

- [x] No generator command expected.

Review artifact:

- [x] `docs/operations/laravel-13-hardening-runbook.md`

Adversarial review:

- [x] Confirmed the docs now name the controller families intentionally left manual.
- [x] Confirmed the runbook records the new rate limiters, logging defaults, token-ability stance, and extracted services.
- [x] Confirmed the backlog keeps the remaining verification gates visible until the final run completes.

## Recommended Implementation Order

- [x] Epic 1 first: policy-first authorization, because it reduces security drift before route and service refactors.
- [x] Epic 2 second: scoped route model binding, because it narrows resource resolution before controller extraction.
- [x] Epic 4 third: exception/logging hardening, because it improves production safety with limited business risk.
- [x] Epic 5 fourth: rate limiting and token abilities, because it changes security surface and needs stable authorization semantics.
- [x] Epic 6 fifth: API Resource hardening, because it may affect response contracts and docs.
- [x] Epic 3 sixth: service extraction, because it is higher churn and safer after authorization is canonical.
- [x] Epic 7 seventh: service-level testing expands naturally once services exist.
- [x] Epic 8 continuous: docs and backlog updates after each epic.

## Out Of Scope

- [x] Business module implementation.
- [x] Formula engines, balance calculations, posting workflows, cash workflows, loan workflows, or reporting metrics.
- [x] Frontend work.
- [x] Database redesign unless required by a specific hardening story and reviewed separately.
- [x] Rewriting the whole API to resource controllers in one pass.

## Completion Gate

- [x] All non-deferred stories are checked.
- [x] Every completed story has evidence in tests, docs, or code review notes.
- [x] `vendor/bin/pint --test` passes.
- [x] `vendor/bin/phpstan analyze` passes.
- [x] `php artisan test` passes.
- [x] `php artisan scramble:export` passes.
- [x] Final adversarial review finds no unresolved high or medium risks introduced by the hardening work.
