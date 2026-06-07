# Issue #13 — Backend translation (i18n) review notes & bugs

Source: GitHub issue #13 (Benjamin2025-cyber/Habisapps) "Backend translation (i18n)
— review notes & bugs". Investigation date: 2026-06-07.

This backlog tracks the four issues and the minor note raised in the review, and
defines the acceptance criteria each fix must satisfy. Companion documents:
`backlogs/api-localization-backlog.md` and `backlogs/api-localization-followup.md`.

## Guiding constraints (apply to every item)

- **English output must stay byte-identical.** Every converted message's `en`
  translation value must render exactly the same string the old
  concatenation/`sprintf()` produced, so the existing English-asserting test
  suite stays green. Placeholders replace the interpolated variables only.
- **Machine values stay untranslated.** Joined lists (e.g. unsupported filter
  keys), operation codes, statuses, currencies, public ids pass through as
  placeholder *values*; only the surrounding sentence is translated.
- **`ApiResponse` only translates the envelope `message`.** Errors placed in
  `errors`/`data` must be localized at the call site via `__()` before they are
  handed to the response helper (the helper preserves those payloads verbatim).
- **en/fr key parity** must hold for every translation file.

---

## I13-1 — API default locale should be French (Medium)

`config/localization.php` resolves `default_locale` from `API_DEFAULT_LOCALE`,
falling back to `APP_LOCALE=en`. No `API_*` locale vars are set in `.env.example`.
Any caller that omits `X-Locale`/a French `Accept-Language` gets English. For an
EMF Cameroun product the safe default is French.

**Work:** set `API_DEFAULT_LOCALE=fr` in `.env.example` (and document the locale
vars), keep `API_FALLBACK_LOCALE=en`. Add the supporting `API_*` vars to
`.env.example` so they are discoverable.

**Acceptance criteria:**
- With no `X-Locale` and no `Accept-Language`, the API default locale resolves to
  `fr`; fallback stays `en`.
- `.env.example` documents `API_DEFAULT_LOCALE`, `API_FALLBACK_LOCALE`,
  `API_SUPPORTED_LOCALES`, `API_LOCALE_HEADER`, `API_LOCALE_META_ENABLED`.
- A feature test asserts the default (no-header) locale is French and fallback is
  English.

**Files:** `config/localization.php` (no logic change needed — env-driven),
`.env.example`.

---

## I13-2 — Humanize/translate validation attribute names (Low–Medium, UX)

`lang/{en,fr}/validation.php` have `'attributes' => []`, so `:attribute` renders
the raw snake_case field name (`Le champ phone_number …`).

**Work:** populate `attributes` in both files with friendly labels for common
fields (email, password, amount, currency, phone_number, business_date, etc.).
English labels are humanized lower-case words; French labels are translated.

**Acceptance criteria:**
- `attributes` is non-empty in both `en` and `fr` and the two key sets are equal.
- A validation error on a humanized field renders the friendly label in both
  locales (e.g. `Le champ numéro de téléphone est obligatoire.`).
- Field *keys* in the error bag remain machine-readable (unchanged).

**Files:** `lang/en/validation.php`, `lang/fr/validation.php`.

---

## I13-3 — Convert dynamic/concatenated domain messages to keyed translations (Medium)

The remaining user-facing messages built with concatenation / `sprintf()` /
`implode()` are still English even under `X-Locale: fr`. Convert each catalogued
message (see `backlogs/api-localization-followup.md`) to a keyed translation with
placeholders.

**Scope (in):** the modules catalogued in `api-localization-followup.md` — Loans,
Journal/Accounts, Cash/Teller, Islamic finance (origination + governance), CRM,
Staff/Role, HR/Payroll, FX, Regulatory/Reporting/Batch, Insurance, and
system-generated Notification template guards, plus the shared
`PhysicalCashAmount` value object.

**Scope (out — intentionally not translated):**
- Internal defensive type guards that should never surface to an end user, e.g.
  `RuntimeException('Expected integer database value for …')` /
  `'Expected non-empty string for <column>'` in `AssessLoanSetupCharges`,
  `IslamicComplianceCaseService`, `IslamicContractTemplateWorkflow`,
  `IslamicMappingApprovalWorkflow`. These are programmer/DB-integrity assertions,
  not request-validation, and are not in the catalogue.
- Developer/configuration errors in Support utilities not in the catalogue:
  `OtpService`, `OtpDeliveryChannelManager`, `FormulaEngineManager`,
  `ReferenceNumberGenerator` (`Unsupported OTP …`, `Formula driver [..] is not
  registered`, `Reference sequence [..] is not configured`). These fire on
  misconfiguration, not on user input.
- Low-level value-object invariants not in the catalogue: `MoneyAmount`
  (`Currency mismatch: expected %s, got %s.`) and `JournalEntryDraft`
  (`Journal entry is not balanced: …`). These guard arithmetic correctness over
  machine currency codes (a bug if they fire), not request validation; the
  user-facing journal "must be balanced" message lives in `JournalEntryWorkflow`
  and is already keyed.
- Database-stored business content and machine contract values (per the
  follow-up doc's "Out of scope").

**Approach:**
1. Each message becomes `__('<group>.<key>', [':placeholder' => $value])`.
2. Keys live in per-module lang files to keep them organized and reviewable:
   `loans`, `cash_journal`, `islamic_finance`, `islamic_governance`, `crm`,
   `reporting`, `insurance`, `notifications`. The shared "unsupported filter
   keys" message reuses the existing `domain.unsupported_filter_keys`.
3. `en` value = exact original English; `fr` value = French translation.
4. List/`implode` values are machine values → passed as a `:keys`/`:items`
   placeholder, not translated.

**Acceptance criteria:**
- Every catalogued dynamic user-facing message is wrapped in `__()` with
  placeholders; no concatenation/`sprintf` of an English literal with a runtime
  variable remains at those call sites.
- Each new lang file has identical `en`/`fr` key sets (parity test extended to
  cover them).
- English rendered output is unchanged (full suite stays green).
- Representative French regression assertions exist for high-traffic modules
  (loan status transition, FX inactive currency, unsupported filter keys, …).
- The follow-up catalogue still exists (referenced by an existing test).

---

## I13-4 — `database_management.php` keyed vs full-sentence duplication (Low)

`lang/{en,fr}/database_management.php` carries both short keys (`disabled_env`, …)
and duplicated full-English-sentence keys for the same messages. Standardize call
sites on the short keys and drop the literal-sentence duplicates (or, where a code
path genuinely throws the literal sentence, leave a documented comment).

**Work:** find every call site that throws/uses the literal-sentence keys, point
it at the short key, then remove the duplicate sentence keys. Verify nothing else
references the literal sentences.

**Acceptance criteria:**
- No duplicate "short key + identical full-sentence key" pairs remain, OR a file
  comment documents why a sentence key must stay.
- All affected code paths render the same English/French strings as before.
- en/fr parity preserved (parity test already covers this file).

**Files:** `lang/{en,fr}/database_management.php`, affected call sites.

---

## I13-5 (minor) — Remove dead `api.locale` middleware alias

`bootstrap/app.php` registers the `api.locale` alias for `SetApiLocale`, but the
global `prepend(SetApiLocale::class)` is what actually runs it and no route group
uses the alias. Remove the unused alias for clarity (behavior unchanged).

**Acceptance criteria:**
- The `api.locale` alias is removed and no route references it.
- Locale negotiation behavior is unchanged (LocalizationTest still passes).

**Files:** `bootstrap/app.php`.

---

## Test Execution

Run in this order:

```bash
# focused localization + representative module suites
php artisan test tests/Feature/Api/LocalizationTest.php
composer test            # full parallel suite (final gate)

# quality gates
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Notes:
- Do not pass multiple file paths to a single `php artisan test` invocation.
- `composer test` is the full parallel gate and must pass before completion.
- For phpstan, run a single full `analyse`; never add `disableMigrationScan` and
  never kill it mid-run.
