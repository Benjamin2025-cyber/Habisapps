# Laravel 13 API Guidance For This Repository

## Verified Baseline

- Framework: `Laravel Framework 13.6.0`
- PHP constraint: `^8.4`
- Auth package: `laravel/sanctum ^4.0`
- API docs package: `dedoc/scramble ^0.13.20`

Checked from:

- `php artisan --version`
- `composer.json`

Official references reviewed:

- https://laravel.com/docs/13.x/validation
- https://laravel.com/docs/13.x/sanctum
- https://laravel.com/docs/13.x/middleware
- https://laravel.com/docs/13.x/providers
- https://laravel.com/docs/13.x/container
- https://laravel.com/docs/13.x/logging
- https://laravel.com/docs/13.x/testing
- https://laravel.com/docs/13.x/responses
- https://laravel.com/docs/13.x/errors
- https://laravel.com/docs/13.x/eloquent-resources
- https://laravel.com/docs/13.x/authorization
- https://laravel.com/docs/13.x/routing
- https://laravel.com/docs/13.x/rate-limiting

## What Already Aligns Well

This API already follows several strong Laravel 13 patterns:

- Centralized JSON exception rendering in `bootstrap/app.php`.
- Custom API response envelope through `App\Support\ApiResponse`.
- Broad use of Form Requests instead of validating inline in controllers.
- API Resources instead of raw model serialization.
- Dedicated middleware for API versioning, server header stripping, and idempotency.
- Rate limiter definitions in `AppServiceProvider`.
- Sanctum token authentication for protected routes.
- Strong feature-test coverage for end-to-end API behavior.
- Explicit finance/domain guardrails in docs and config, which is a good fit for service-container-driven architecture later.

## Highest-Value Recommendations

### 1. Use Laravel authorization features more consistently

Laravel 13 guidance strongly favors policies, gates, and Form Request `authorize()` logic for resource authorization. This repo has good policy coverage in places, but many controllers still do manual `hasRole` / `hasPermissionTo` / scope checks inline.

Current state:

- Policies exist, for example `app/Policies/ClientPolicy.php`.
- Many controllers still bypass `authorize()` / policy methods and reimplement checks manually.

Recommendation:

- Standardize on policies plus `$this->authorize(...)` or `authorizeResource(...)` for resource controllers.
- Push simple mutation permission checks into Form Request `authorize()` methods.
- Keep only domain-specific cross-record integrity checks inside controllers / services.

Why it matters:

- Reduces duplicated permission logic.
- Makes controller methods narrower.
- Prevents authorization drift across modules.
- Makes tests easier to target by policy behavior.

Adversarial note:

Manual checks age badly in multi-agency systems. The code tends to pass tests while still drifting semantically between modules.

### 2. Introduce scoped route model binding for nested resources

Laravel routing supports scoped bindings for nested resources. This repo has many nested routes like client -> identity-document, guarantor, proxy, but relies heavily on controller-side checks.

Recommendation:

- Use scoped bindings or grouped route binding patterns for nested resources wherever parent-child ownership is mandatory.
- Treat controller-side ownership checks as defense in depth, not the primary guard.

Why it matters:

- Rejects cross-parent object access earlier in the request lifecycle.
- Simplifies controller code.
- Makes route contracts safer by default.

Adversarial note:

If nested binding is not scoped, a valid child ID from another parent can still resolve, and then every controller must remember to re-check ownership.

### 3. Move transaction-heavy business actions behind dedicated application services

Laravel’s container and provider model are well-suited to service-based orchestration. Several controllers already contain meaningful orchestration logic.

Recommendation:

- Keep controllers as transport adapters.
- Move multi-step workflows such as client creation, KYC transitions, journal submission, hold release, and future credit/cash flows into dedicated service/action classes.
- Bind interfaces for formula engines and sensitive workflow coordinators through the container when the domain solidifies.

Why it matters:

- Easier testing without HTTP.
- Better reuse from commands, jobs, or future internal APIs.
- Cleaner transaction boundaries.

Adversarial note:

Financial APIs become fragile when transaction logic lives mostly in controllers. It works until a console command, queue job, or webhook needs the same flow.

### 4. Tighten exception reporting and log context in `bootstrap/app.php`

Laravel 13’s error handling docs recommend using `withExceptions(...)` not only for rendering, but also for reporting behavior, deduplication, throttling, and global context.

Current state:

- JSON rendering is customized well.
- Exception reporting is still mostly default.

Recommendation:

- Add `$exceptions->dontReportDuplicates()`.
- Add `$exceptions->context(...)` with low-risk operational context such as app version, API version, tenant scope marker, and request correlation ID if available.
- Add throttling rules for noisy infrastructure exceptions when production traffic grows.

Why it matters:

- Cleaner logs.
- Better production triage.
- Less alert fatigue during outages.

Adversarial note:

Without deduplication and throttling, one dependency outage can swamp logs and hide the original failure mode.

### 5. Promote structured logging over ad hoc operational logs

Laravel logging docs encourage channel design and structured context. This repo already has structured security audit events, which is a strength.

Recommendation:

- Keep audit events separate from operational logs.
- Add a dedicated log channel strategy for production, likely `stack` -> `stderr` for containers plus optional Slack / external sink for critical alerts.
- Standardize contextual keys for request ID, actor public ID, agency public ID, public resource ID, and module.

Why it matters:

- Better observability in Docker / VPS deployments.
- Easier filtering and alerting.
- Cleaner separation between audit evidence and application diagnostics.

Adversarial note:

Audit trails do not replace production diagnostics. They answer “who changed what”, not “why the request path is degrading”.

### 6. Use API Resources more aggressively for conditional exposure

Laravel API resource docs emphasize conditional attributes and relationships. The repo already uses resources, but many resources still manually branch on `relationLoaded(...)` or expose fixed shapes.

Recommendation:

- Prefer resource helpers like conditional inclusion patterns for related data and sensitive fields.
- Consider Laravel 13 resource conventions like model `toResource()` / `toResourceCollection()` or `UseResource` attributes if the team wants stricter conventions.
- Keep internal IDs out of resource payloads consistently.

Why it matters:

- Makes serialization intent clearer.
- Reduces accidental overexposure.
- Keeps API contracts stable as models gain fields.

Adversarial note:

Resource classes are one of the easiest places to leak internal identifiers or state-machine internals during later feature growth.

### 7. Make rate limiting route-specific beyond auth

Laravel rate limiting is already used for auth endpoints. That is good, but insufficient for a professional API once external consumers and automation expand.

Recommendation:

- Add named rate limiters for sensitive write endpoints:
  - account activation / password reset already covered
  - document upload
  - client creation
  - journal creation / submit / reverse
  - audit browsing
  - reference-number reservation
- Rate-limit by actor identity where authenticated, by IP where anonymous.

Why it matters:

- Protects expensive or abuse-prone endpoints.
- Reduces accidental runaway automation.
- Lowers operational risk before introducing a gateway.

Adversarial note:

Auth throttling alone does not protect the business surface. Internal staff endpoints can still be abused by valid tokens.

### 8. Formalize token ability strategy in Sanctum

Laravel Sanctum supports token abilities and ability middleware. The repo issues token abilities from config, but most route protection is still guard-only.

Recommendation:

- Decide whether token abilities are authoritative or merely descriptive.
- If authoritative, protect high-risk routes with ability checks or explicit policy integration.
- Separate staff console tokens, machine tokens, and future service-to-service tokens by ability sets.

Why it matters:

- Prevents all bearer tokens from becoming equivalent.
- Helps future integrations and internal automation.

Adversarial note:

If abilities are minted but never enforced, they create false confidence and operational ambiguity.

### 9. Keep tests feature-heavy, but add non-HTTP tests around domain services

Laravel testing docs explicitly favor feature tests for confidence. This repo already does that well.

Recommendation:

- Keep HTTP feature tests as the main contract layer.
- Add unit / service tests only after orchestration moves out of controllers.
- For future financial workflows, test invariants at the service layer and transport behavior at the HTTP layer.

Why it matters:

- Avoids brittle over-mocking now.
- Preserves fast feedback later once domain services grow.

Adversarial note:

Too many controller-centric feature tests without extracted domain services eventually make refactors expensive because the only executable spec lives at HTTP level.

## Architecture Direction Recommended For This API

For this codebase, the most robust Laravel shape is:

1. Routes
   Thin, declarative, scoped, rate-limited, ability-aware.

2. Form Requests
   Input normalization, structural validation, simple authorization.

3. Controllers
   HTTP transport only: authorize, call service, return resource.

4. Application Services / Actions
   Transactions, orchestration, state transitions, invariants.

5. Domain Support Types
   Money, formula guards, workflow value objects, engine interfaces.

6. Resources
   Stable API contract and exposure boundary.

## Implementation Notes From Hardening Pass

Completed on 2026-05-04:

- Policy/Form Request authorization was expanded across CRM/KYC and accounting resources.
- Scoped route bindings were added for nested CRM/KYC child resources.
- Exception reporting now suppresses duplicate reports and adds only safe operational context.
- Production logging strategy is documented for Docker `stderr` deployment.
- Route-specific rate limiters were added for document upload, client creation, journal writes, audit browsing, and reference reservation.
- Sanctum token abilities are documented as descriptive/deferred, not authoritative.
- `AuditEventResource` no longer exposes internal activity-log integer IDs.
- KYC status persistence and account-hold release orchestration were moved into application services.

Deferred:

- Authoritative Sanctum ability enforcement remains deferred until token classes and least-privilege ability names are designed.

7. Middleware
   Cross-cutting concerns only: auth, idempotency, versioning, tracing, throttling.

8. Policies
   Canonical authorization decisions, especially for agency scope.

## Concrete Next Improvements For This Repository

### Controller Hardening Status

The controllers that previously relied on inline permission checks now route their top-level authorization through policies. Some scope-selection branches still live in the controller because they choose which records to query or mutate, but the permission decision itself is no longer hard-coded in each action.

Controllers may still perform direct model persistence for simple single-record writes or reads when that is the clearest transport-layer implementation. Multi-step persistence, cache invalidation, and cross-model orchestration should move into services or actions.

If the goal is a more professional Laravel API without unnecessary churn, these are the next best moves:

- Replace repeated inline permission checks in controllers with policy-based authorization.
- Add scoped route model binding to nested CRM and accounting routes.
- Extract multi-step controller workflows into service/action classes.
- Add exception reporting context and duplicate/throttle controls in `bootstrap/app.php`.
- Define production log channel strategy for Docker deployment.
- Add route-specific rate limiters for non-auth sensitive endpoints.
- Decide and enforce Sanctum token ability semantics.
- Keep internal IDs out of every resource and response path as new modules are added.

## Things To Avoid

- Fat controllers that own transactions, authorization, and serialization together.
- Controllers that coordinate multiple writes, cache invalidation, or workflow state across models.
- Raw model JSON in API responses.
- Role checks spread across controllers without policy centralization.
- Treating audit logging as a substitute for structured operational logging.
- Adding formula or accounting engines before the stakeholder formula decisions are finalized.
- Assuming “authenticated” means “authorized” in a multi-agency API.

## Confidence Notes

This guidance is based on Laravel 13 official docs and the current repository state on 2026-05-04.

Two repo-specific conclusions matter:

- This API already has a better-than-average Laravel foundation for a finance system.
- The biggest remaining architectural risk is not missing Laravel features; it is duplicated authorization / scoping logic living too close to controllers instead of becoming canonical policy + service behavior.
