# API Localization

The API returns backend-owned, user-facing text in the caller's requested
locale while keeping all machine-readable contract values stable. This document
describes the negotiation rules and the frontend contract.

## Supported locales

- `en` (English) — default and fallback.
- `fr` (French) — active frontend language.

Supported locales are configured centrally in `config/localization.php`
(`API_SUPPORTED_LOCALES`, default `en,fr`) and can be changed without code edits.

## Locale negotiation

For each API request the locale is resolved by `App\Http\Middleware\SetApiLocale`
in this priority order:

1. **`X-Locale` header** — if present and supported (e.g. `X-Locale: fr`).
2. **`Accept-Language` header** — best supported language match
   (e.g. `Accept-Language: fr-CM,fr;q=0.9,en;q=0.8` resolves to `fr`).
3. **`API_DEFAULT_LOCALE`** (defaults to `APP_LOCALE`, i.e. `en`).
4. **`API_FALLBACK_LOCALE`** (defaults to `APP_FALLBACK_LOCALE`, i.e. `en`).

Behaviour notes:

- Regional locales are normalized: `fr-CM` resolves to `fr` when `fr` is supported.
- Unsupported (`es`) or malformed (`*** invalid ***`) locale values are ignored
  and fall back to English. A bad locale header never fails the request.
- The locale applies only to the current request; global config is not mutated.
- Missing French keys automatically fall back to the English value.

## What is translated

- The response envelope `message`.
- Validation error messages (Laravel rules + custom messages).
- Authentication, authorization, throttle, not-found, and idempotency messages.
- Maintenance / write-lock messages (database restore, accounting-day lock).
- User-facing domain workflow error messages.
- Backend-generated display labels intended for frontend rendering.

## What is NEVER translated

- JSON keys: `success`, `message`, `data`, `errors`, `meta`, `pagination`.
- Machine values: enum/status values, permission names, audit event keys,
  public ids, account/reference numbers, idempotency keys, operation/contract
  type codes, product/scheme proper names.
- Database-backed business content (client/product/account names, notes,
  descriptions, stored notification text, journal narrations). Not localized in
  this phase.

## Response shape is stable across locales

Only text values change between locales; the JSON structure never does.

### Success — `GET` with `X-Locale: en`

```json
{
  "success": true,
  "message": "Success",
  "data": { "...": "..." },
  "meta": { "pagination": { "current_page": 1, "per_page": 25, "total": 0, "last_page": 1 } }
}
```

### Success — same request with `X-Locale: fr`

```json
{
  "success": true,
  "message": "Succès",
  "data": { "...": "..." },
  "meta": { "pagination": { "current_page": 1, "per_page": 25, "total": 0, "last_page": 1 } }
}
```

### Validation error (422) — `X-Locale: fr`

```json
{
  "success": false,
  "message": "Échec de la validation",
  "errors": {
    "email": ["Le champ email est obligatoire."],
    "amount": ["Le champ amount doit être un nombre."]
  }
}
```

Field keys (`email`, `amount`) remain machine-readable request field names — they
are never translated.

### Not found (404) — `X-Locale: fr`

```json
{ "success": false, "message": "Ressource introuvable" }
```

## Optional locale metadata

When `API_LOCALE_META_ENABLED=true`, responses include the resolved locale under
`meta.locale` (alongside any pagination metadata) for frontend diagnostics. It is
disabled by default and does not affect response shape when off.

```json
{ "success": true, "message": "Succès", "data": {}, "meta": { "locale": "fr", "pagination": { "...": "..." } } }
```

## Frontend integration guidance

- Request French by sending `X-Locale: fr` (or a `fr*` `Accept-Language`).
- **Do not branch application logic on localized `message` text.** Use the stable
  `success` boolean, the HTTP status code, and machine values in `data`/`errors`
  (codes, statuses, enums) for logic.
- Response parsing does not change between locales — only displayed text does.
- Database-backed content (names, descriptions) is returned as stored and is not
  translated in this phase.

## Translation sources

- `lang/{en,fr}/api.php` — envelope and generic API messages.
- `lang/{en,fr}/validation.php` — Laravel validation rule messages and attributes.
- `lang/{en,fr}/domain.php`, `accounting_day.php`, `database_management.php`,
  `system.php` — keyed domain messages.
- `lang/{en,fr}.json` — compatibility dictionary for legacy English sentence
  keys when they are passed as the API envelope `message`.

`ApiResponse` localizes only the envelope `message`. It does not recursively
translate `data`, `errors`, or `meta`, because those payloads can contain
machine values and database-backed content that must remain byte-stable across
locales. Domain and validation code should translate user-facing payload text
before passing it to the response helper.

Remaining dynamic/concatenated messages awaiting a placeholder refactor are
catalogued in `backlogs/api-localization-followup.md`.
