# API Localization Backlog

Investigation date: 2026-06-07.

## Context

The frontend is translated, but the backend API currently returns English system text in response messages, validation errors, authorization failures, exception responses, and API-owned labels. This creates an inconsistent user experience because translated frontend screens still display English backend messages during errors, empty states, confirmations, and operational workflows.

This backlog covers backend localization for API-owned text only. Database content is intentionally excluded for now because translating stored business data requires a separate strategy for persistence, fallback, search, auditability, and migration.

## Current-State Evidence

- `config/app.php` already defines `APP_LOCALE`, `APP_FALLBACK_LOCALE`, and `APP_FAKER_LOCALE`.
- There is no `lang/` or `resources/lang/` directory in the repository.
- `app/Support/ApiResponse.php` hardcodes English defaults such as `Success`, `Resource created successfully`, `Validation failed`, `Unauthorized`, `Forbidden`, and `Resource not found`.
- Controllers and workflows use `ValidationException::withMessages()` with hardcoded English strings.
- Middleware and exception paths return hardcoded English API messages in some places.
- API response keys use stable English machine keys such as `success`, `message`, `data`, `errors`, and `meta`.
- Database-backed values, audit metadata, permissions, statuses, and public ids are used as machine-readable contract values and should not be translated in this scope.

## Scope

Implement API localization so backend-owned user-facing text can be returned in the caller's requested locale while preserving stable API contracts.

In scope:

- Locale negotiation for API requests.
- Translation files for supported API locales.
- Localized response envelope messages.
- Localized Laravel validation messages and custom validation attributes.
- Localized authorization, authentication, throttling, not-found, and server-safe exception messages.
- Localized domain workflow errors returned to frontend users.
- Localized backend-owned display labels where the API currently generates human-readable labels.
- Tests for locale negotiation, fallback behavior, response shape stability, and translated messages.
- API documentation describing localization behavior.

Out of scope:

- Translating database-backed business records.
- Storing translated versions of client names, product names, account names, notes, descriptions, comments, audit records, or uploaded files.
- Translating machine contract keys or enum values used by the frontend for logic.
- Per-user persisted language preferences unless already available through existing user settings.
- Automatic machine translation.
- Translating CLI output for operators.

## Localization Policy

Default behavior:

- API responses default to English when no locale is requested.
- A supported requested locale controls API-owned user-facing text for that request.
- Unsupported or malformed locale requests fall back to the configured fallback locale.
- The response JSON structure remains stable across all locales.

Locale source priority:

- Explicit `X-Locale` header, if present and supported.
- `Accept-Language` header, using the best supported language match.
- Authenticated user's preferred locale only if such a field already exists or is added in a reviewed future task.
- `APP_LOCALE`.
- `APP_FALLBACK_LOCALE`.

Required non-translated values:

- JSON keys: `success`, `message`, `data`, `errors`, `meta`, `pagination`.
- Machine codes: statuses, types, permissions, public ids, route names, audit event keys, enum values, idempotency keys, reference numbers.
- Database-backed content unless a future database-translation feature explicitly owns that field.
- Arbitrary strings inside `data`, `errors`, or `meta`; those fields may contain database-backed content or machine values and must not be recursively translated by shared response helpers.

Required translated values:

- API envelope `message`.
- Validation error messages.
- Authorization/authentication/throttle/not-found messages.
- User-facing workflow and domain error messages.
- Backend-generated display labels intended for frontend rendering.

## API-I18N-001: Localization Configuration

Create a central localization configuration for API behavior.

Suggested config:

- `config/localization.php`
- `API_SUPPORTED_LOCALES=en,fr`
- `API_DEFAULT_LOCALE=en`
- `API_FALLBACK_LOCALE=en`
- `API_LOCALE_HEADER=X-Locale`
- `API_LOCALE_META_ENABLED=false`

Acceptance criteria:

- Supported locales are configured centrally and can be changed without code edits.
- English remains the default and fallback locale.
- Locale values are normalized safely, for example `fr-CM` can resolve to `fr` when `fr` is supported.
- Invalid locale values are ignored, not trusted blindly.
- Tests cover default locale, supported locale, regional locale matching, unsupported locale, and malformed locale values.

## API-I18N-002: API Locale Negotiation Middleware

Add middleware that resolves and applies the request locale.

Suggested middleware:

- `App\Http\Middleware\SetApiLocale`

Required behavior:

- Reads `X-Locale` and `Accept-Language`.
- Selects the first supported locale according to the configured priority.
- Calls `app()->setLocale($locale)` for the request lifecycle.
- Does not mutate global config permanently.
- Does not fail requests because of unsupported locale headers.

Acceptance criteria:

- API routes use the locale middleware.
- `X-Locale: fr` returns French API messages.
- `Accept-Language: fr-CM,fr;q=0.9,en;q=0.8` resolves to `fr` when `fr` is supported.
- Unsupported `Accept-Language` falls back to English.
- Middleware is covered by feature tests.

## API-I18N-003: Translation File Structure

Add translation files for API-owned messages.

Required files:

- `lang/en/api.php`
- `lang/fr/api.php`
- `lang/en/validation.php`
- `lang/fr/validation.php`
- Optional domain files such as `lang/en/auth.php`, `lang/fr/auth.php`, `lang/en/domain.php`, and `lang/fr/domain.php`

Required key groups:

- Generic envelope messages.
- Authentication and authorization messages.
- Validation messages and attribute names.
- Rate limiting and idempotency messages.
- Document/media-storage messages.
- Database-management messages.
- Accounting-day lock and maintenance messages.
- Role and permission management messages.
- CRM/client/staff workflow messages.
- Loan/accounting/cash workflow messages as encountered.

Acceptance criteria:

- All translation files return arrays and load through Laravel's translator.
- Translation keys are stable and descriptive.
- Missing French keys fall back to English.
- No user-facing API message is stored only as a hardcoded default in `ApiResponse`.
- Tests prove English and French files contain the same required top-level key sets for core API messages.

## API-I18N-004: Localize ApiResponse Envelopes

Update the shared response helper to translate default API messages.

Required behavior:

- `ApiResponse::success()` defaults to `__('api.success')`.
- `ApiResponse::created()` defaults to `__('api.created')`.
- `ApiResponse::error()` defaults to `__('api.error')`.
- `ApiResponse::notFound()` defaults to `__('api.not_found')`.
- `ApiResponse::unauthorized()` defaults to `__('api.unauthorized')`.
- `ApiResponse::forbidden()` defaults to `__('api.forbidden')`.
- `ApiResponse::unprocessable()` defaults to `__('api.validation_failed')`.
- `ApiResponse::tooManyRequests()` defaults to `__('api.too_many_requests')`.

Acceptance criteria:

- Existing callers that pass explicit messages continue to work.
- Existing JSON envelope shape is unchanged.
- `GET` pagination metadata behavior is unchanged.
- Feature tests assert translated default messages for English and French.
- Static search confirms the default messages are not hardcoded English strings in `ApiResponse`.
- `ApiResponse` only translates the envelope `message`; it does not recursively translate `data`, `errors`, or `meta`.
- Regression tests prove payload strings that look like translation keys, for example `api.success`, remain unchanged when returned as machine or stored values.

## API-I18N-005: Localize Validation Errors

Add full validation localization for request classes and manual validation exceptions.

Required behavior:

- Laravel default validation rule messages exist for supported locales.
- Custom request attributes are translated where useful.
- `ValidationException::withMessages()` uses translation keys for user-facing text.
- Error bag structure remains unchanged: field names map to arrays of messages.
- Field keys remain machine-readable request field names, not translated labels.

Acceptance criteria:

- Required-field, invalid-format, enum, exists, unique, min/max, date, numeric, file, and mime validation errors are translated.
- Manual validation exceptions in workflows and controllers use translation keys.
- Tests cover representative request validation in English and French.
- API docs continue to show the same request and error structure.

## API-I18N-006: Localize Authentication, Authorization, And Security Errors

Translate security-related API messages without weakening the security posture.

Required behavior:

- Authentication failure messages are localized.
- Authorization failure messages are localized.
- Throttle and too-many-request responses are localized.
- Idempotency middleware responses are localized.
- Maintenance/write-lock responses are localized.
- Generic server errors remain safe and do not expose internals in any locale.

Acceptance criteria:

- Unauthorized and forbidden responses return localized `message` values.
- Security-sensitive errors do not reveal more detail in translated locales than in English.
- Idempotency conflict, in-progress, and replay errors are translated.
- Database restore lock and accounting-day registration lock messages are translated.
- Tests cover English/French behavior for unauthenticated, unauthorized, throttled, idempotency, and lock responses.

## API-I18N-007: Localize Domain Workflow Errors

Replace hardcoded English domain errors returned to frontend users with translation keys.

Initial target areas:

- Staff and agency assignment validation.
- Client and KYC workflow validation.
- Document upload/download/media-storage errors.
- Role and permission management errors.
- Accounting-day and registration lock errors.
- Database-management backup/restore errors.
- Loan, account, teller, cash, and journal workflow validation.
- Islamic finance workflow readiness and approval errors.

Required behavior:

- Domain services throw translated validation messages only at API boundaries or use exceptions that carry stable error keys.
- Internal logs and audit events may keep machine-readable codes.
- User-facing error messages are localized based on the request locale.

Acceptance criteria:

- No newly touched controller/workflow introduces hardcoded English user-facing API messages.
- Representative domain errors are translated in English and French.
- Error codes, statuses, and machine enum values remain untranslated.
- Tests cover at least one localized domain error in each high-traffic module.

## API-I18N-008: Preserve Machine Contract Values

Define and enforce what must remain language-neutral.

Required behavior:

- Response keys remain unchanged.
- Enum/status values remain unchanged.
- Permission names remain unchanged.
- Audit event keys remain unchanged.
- Public ids, account numbers, reference numbers, and idempotency keys remain unchanged.
- Frontend display labels can be localized separately from machine values when needed.

Acceptance criteria:

- Tests prove representative status/type/permission values do not change between `en` and `fr`.
- API documentation clearly separates machine values from localized display text.
- No resource class translates database-backed names or descriptions in this phase.
- Static/code review checks flag translation attempts for audit event keys and permission names.
- Response helpers preserve `data`, `errors`, and `meta` payload values byte-for-byte unless a caller explicitly localizes a user-facing error message before passing it in.

## API-I18N-009: Optional Locale Metadata

Optionally expose the resolved API locale for frontend diagnostics.

Required behavior:

- Controlled by config such as `API_LOCALE_META_ENABLED`.
- When enabled, responses include the resolved locale in metadata without disturbing existing pagination metadata.
- When disabled, responses do not include locale metadata.

Suggested response shape:

```json
{
  "success": true,
  "message": "Succès",
  "data": {},
  "meta": {
    "locale": "fr"
  }
}
```

Acceptance criteria:

- Locale metadata can be enabled or disabled by configuration.
- Locale metadata merges safely with existing pagination metadata.
- Existing tests that assert response shape remain stable when the config is disabled.
- Tests cover enabled and disabled metadata modes.

## API-I18N-010: Exception Rendering Integration

Ensure framework and domain exceptions produce localized API responses.

Required behavior:

- Not found/model not found responses are localized.
- Authorization exceptions are localized.
- Validation exceptions are localized.
- HTTP exceptions use localized safe messages where appropriate.
- Unexpected exceptions return a localized generic message in production.

Acceptance criteria:

- API exceptions use the same envelope conventions as existing API responses.
- Model-not-found responses do not expose model class names.
- Production generic errors are translated and safe.
- Debug-mode behavior remains useful for developers without leaking in production.
- Tests cover 404, 403, 422, 429, and generic 500-safe rendering.

## API-I18N-011: Notification And Email API Text Boundary

Review notification-related text returned by API endpoints.

Required behavior:

- API-visible notification titles/messages generated by backend use translation keys when they are system-generated.
- Stored notification records are not retroactively translated in this phase.
- Email/SMS body translation is deferred unless the same translation files can support it safely without changing delivery semantics.

Acceptance criteria:

- New system-generated notification text can be generated in the request/user locale where applicable.
- Existing stored notification content remains unchanged.
- API tests clarify whether notification text is stored text or generated display text.
- A follow-up backlog is created if persisted multilingual notifications are required.

## API-I18N-012: API Documentation And Frontend Contract

Document localization behavior for frontend integration.

Required documentation:

- Supported locales.
- Locale header behavior.
- `Accept-Language` behavior.
- Fallback behavior.
- Which fields are translated.
- Which fields are never translated.
- Example English and French success/error responses.
- Validation error response examples.
- Guidance for frontend: do not branch logic on localized messages.

Acceptance criteria:

- API documentation includes localization notes.
- `php artisan scramble:export` still succeeds.
- Frontend can request French messages without changing response parsing.
- Documentation warns that database content is not translated in this phase.

## API-I18N-013: Test Coverage And Quality Gates

Add regression coverage for localization behavior.

Suggested focused tests:

```bash
php artisan test tests/Feature/Api/LocalizationTest.php
php artisan test tests/Feature/Api/AuthTest.php
php artisan test tests/Feature/Api/StaffUserManagementTest.php
php artisan test tests/Feature/Api/AdminDatabaseManagementTest.php
php artisan test tests/Feature/Api/MediaStorageR2Test.php
```

Quality gates:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan scramble:export
```

Acceptance criteria:

- Tests prove locale negotiation works.
- Tests prove response shape stability across locales.
- Tests prove fallback behavior works.
- Tests prove validation messages are localized.
- Tests prove machine values remain stable across locales.
- Tests prove response helpers do not translate payload values that are not envelope messages.
- Static search or review checks identify remaining hardcoded English API messages for follow-up.

Adversarial review finding fixed on 2026-06-07:

- `ApiResponse` previously translated every string in `data` and `errors`. That violated API-I18N-008 because database-backed values or machine values matching a translation key could change across locales. The helper now localizes only the envelope `message`; regression coverage asserts that `data`, `errors`, and `meta` values such as `api.success` remain unchanged in French responses.

## Implementation Notes

- Prefer Laravel's built-in translator and `lang/` files.
- Keep translation keys explicit; avoid using English sentences as keys for domain messages.
- Avoid translating database-backed content in resources.
- Avoid translating audit event keys, permission names, enum values, and machine statuses.
- For domain services, prefer stable domain error codes plus localized rendering at the API boundary where practical.
- Keep `APP_LOCALE=en` and `APP_FALLBACK_LOCALE=en` unless there is a deliberate deployment decision.
- Do not make frontend logic depend on translated messages.
- Add French first if that is the frontend's active translated language, then expand supported locales deliberately.
