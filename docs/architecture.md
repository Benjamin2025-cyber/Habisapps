# Architecture Rules

This project is initialized as a Laravel API foundation for a future microfinance platform. These rules exist to keep early code simple, auditable, and easy to harden later.

## Application shape

- Controllers stay thin. They validate input, authorize, call one application action, and return a response.
- Application use cases live in `app/Http/Actions`. One action should represent one business operation.
- Repositories are optional, not mandatory. Use them only when a query or persistence workflow is reused across multiple actions or would otherwise create duplicated query logic.
- Eloquent remains the default persistence API. Do not introduce repositories to wrap trivial `find`, `create`, or `update` calls.
- Data transfer objects should use `spatie/laravel-data` once request / response payloads become non-trivial or shared across endpoints.
- Domain events are for meaningful business facts, not simple controller-to-controller coordination.
- Exceptions should be specific. Throw framework or domain exceptions with clear intent; let `bootstrap/app.php` translate them into API responses.

## Action rules

- Prefer one public `execute()` method per action.
- Use `BaseAction::inTransaction()` only when the action changes persistent state across multiple statements.
- Do not open transactions in controllers.
- Avoid side effects before the transaction commits.

## DTO policy

- Use Laravel validation directly for simple one-endpoint payloads.
- Introduce Data objects when the payload is reused, nested, versioned, or needs transformation.
- Keep DTOs immutable in practice: validate, transform, pass into actions, return resources.

## Event policy

- Emit events for business milestones that matter to audit, notification, or downstream processing.
- Do not emit events for every CRUD operation by default.
- Queue listeners only when the work is non-blocking and safe to run after commit.

## Exception policy

- Validation failures stay as validation exceptions.
- Authorization failures stay as authorization exceptions.
- Business rule violations should use explicit domain exceptions once those rules exist.
- Do not return ad hoc JSON error arrays from deep inside the application layer.

## Formula policy

- Formula-dependent services must fail closed until the matching stakeholder policy is approved in `config/formulas.php`.
- Use `FormulaPolicyRegistry::requireApproved(...)` before implementing interest, repayment allocation, balance, penalty, cash reconciliation, or reporting calculations.
- Value objects and ledger draft validation may be built before approval because they enforce invariants without choosing formulas.
- See `docs/domain/formula-guardrails.md`.

## Deferred decisions

- UUID primary keys are out of scope for the current foundation. The default project convention is integer `id` keys.
- Domain developer docs now recommend adding public immutable IDs, preferably ULIDs, to externally exposed business resources while retaining internal integer IDs unless a future ADR changes this.
- Multitenancy is out of scope for the current foundation. Tenant scoping must not be added piecemeal without a dedicated architecture decision.
