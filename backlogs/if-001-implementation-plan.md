# IF-001 Implementation Plan: Islamic Finance Standards Registry

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-001
Proof-method: proof by contradiction

## IF-001 Source Requirement

Goal: record the standards, legal opinions, policies, and internal decisions that govern Islamic finance behavior.

Proof-by-contradiction invariant: assume a product is approved against no identifiable standard. Approval must be impossible because the product has no active standards baseline.

Acceptance criteria:

- Store standard source, title, version or publication date where known, scope, owner, effective date, expiry date, and attachment.
- Allow linking standards to product families, account types, accounting mappings, contract templates, and screening policies.
- Require standards baseline before product readiness approval.
- Audit creation, amendment, activation, expiry, and retirement.

Tests:

- Product readiness fails without active standards baseline.
- Expired standard blocks new approvals.
- Standards amendment creates an audit event.

## Architecture Context

The existing Islamic finance code currently uses raw `DB::table()` queries, workflows extending `BaseController`, inline `Validator::make()` validation, and `SecurityAudit::record()` for audit events. This plan follows those conventions while extracting the standards-baseline check into a reusable service so later readiness and approval flows cannot bypass it.

The plan deliberately avoids implementing standards as free-form fields on `islamic_products`. Standards are shared governance records with links to the artifacts they govern.

## Completion Definition For This Plan

IF-001 is sound only if all of these are true:

- A standard cannot become active without source, title, owner, effective date, and evidence attachment.
- A future-effective, expired, retired, or draft standard cannot satisfy a readiness baseline.
- A product family, account type, accounting mapping, contract template, or screening policy can be linked to one or more standards.
- Product readiness approval cannot pass without at least one active, currently effective, unexpired standard linked to the product family.
- Link validation prevents nonsense links from creating a fake baseline.
- Active standards are not mutated in place; amendments create a new version and audit the before/after relationship.
- Creation, amendment, activation, expiry, and retirement are visible in `activity_log`.

## Phase 1: Data Model

### 1.1 `islamic_standards` Table

Create `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_standards_tables.php`.

| Column | Type | Notes |
|---|---|---|
| `id` | `id()` | Internal PK |
| `public_id` | `ulid()->unique()` | External reference |
| `source` | `string(64)` | `AAOIFI`, `IFSB`, `COBAC`, `CEMAC`, `INTERNAL`, `LEGAL_OPINION`, `SHARIA_DECISION`, `POLICY` |
| `reference` | `string(128)` | Standard/code/reference, e.g. `IFSB-31`, `AAOIFI-SS-8`, internal memo reference |
| `title` | `string(255)` | Standard or decision title |
| `version` | `string(64)->nullable()` | Version where known |
| `publication_date` | `date()->nullable()` | Publication/adoption/decision date where known |
| `scope_summary` | `text()` | Required human-readable applicability summary |
| `owner_type` | `string(32)` | `user`, `role`, `department`, `committee` |
| `owner_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | Required when `owner_type = user` |
| `owner_role` | `string(128)->nullable()` | Required when `owner_type = role` |
| `owner_department` | `string(128)->nullable()` | Required when `owner_type = department` |
| `owner_committee` | `string(128)->nullable()` | Required when `owner_type = committee` |
| `effective_date` | `date()` | Date from which the standard can govern approvals |
| `expiry_date` | `date()->nullable()` | Null means no configured expiry |
| `status` | `string(32)->default('draft')` | `draft`, `active`, `expired`, `retired`, `superseded` |
| `document_id` | `foreignId()->constrained('documents')->restrictOnDelete()` | Required evidence attachment |
| `supersedes_standard_id` | `foreignId()->nullable()->constrained('islamic_standards')->nullOnDelete()` | Previous standard version |
| `created_by_user_id` | `foreignId()->constrained('users')->restrictOnDelete()` | Creator |
| `activated_by_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | Activator |
| `activated_at` | `timestamp()->nullable()` | Activation time |
| `retired_by_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | Retirement actor |
| `retired_at` | `timestamp()->nullable()` | Retirement time |
| `retirement_reason` | `text()->nullable()` | Required when retired |
| `metadata` | `json()->nullable()` | Structured notes such as URL, publication body, local interpretation |
| `created_at` / `updated_at` | timestamps | Laravel timestamps |

Database constraints:

```sql
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_source_valid
  CHECK (source IN ('AAOIFI', 'IFSB', 'COBAC', 'CEMAC', 'INTERNAL', 'LEGAL_OPINION', 'SHARIA_DECISION', 'POLICY'));

ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_owner_type_valid
  CHECK (owner_type IN ('user', 'role', 'department', 'committee'));

ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_owner_required
  CHECK (
    (owner_type = 'user' AND owner_user_id IS NOT NULL AND owner_role IS NULL AND owner_department IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'role' AND owner_role IS NOT NULL AND owner_user_id IS NULL AND owner_department IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'department' AND owner_department IS NOT NULL AND owner_user_id IS NULL AND owner_role IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'committee' AND owner_committee IS NOT NULL AND owner_user_id IS NULL AND owner_role IS NULL AND owner_department IS NULL)
  );

ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_status_valid
  CHECK (status IN ('draft', 'active', 'expired', 'retired', 'superseded'));

ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_dates_valid
  CHECK (expiry_date IS NULL OR expiry_date > effective_date);

ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_activation_fields_valid
  CHECK (
    status <> 'active'
    OR (activated_by_user_id IS NOT NULL AND activated_at IS NOT NULL)
  );

ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_retirement_fields_valid
  CHECK (
    status <> 'retired'
    OR (retired_by_user_id IS NOT NULL AND retired_at IS NOT NULL AND retirement_reason IS NOT NULL AND retirement_reason <> '')
  );
```

Proof by contradiction:

- Assume a standard has no evidence attachment. Insert must fail because `document_id` is not nullable.
- Assume ownership is unaccountable free text. Insert must fail unless exactly one configured owner identity is present.
- Assume a retired standard has no reason. Update must fail because retirement fields are required.

### 1.2 `islamic_standard_links` Table

| Column | Type | Notes |
|---|---|---|
| `id` | `id()` | Internal PK |
| `public_id` | `ulid()->unique()` | External reference |
| `islamic_standard_id` | `foreignId()->constrained('islamic_standards')->cascadeOnDelete()` | Parent standard |
| `linkable_type` | `string(64)` | `product_family`, `account_type`, `accounting_mapping`, `contract_template`, `screening_policy` |
| `linkable_code` | `string(128)` | Family/code/public identifier depending on link type |
| `created_by_user_id` | `foreignId()->constrained('users')->restrictOnDelete()` | Link creator |
| `created_at` | timestamp | Creation time |

Database constraints:

```sql
ALTER TABLE islamic_standard_links ADD CONSTRAINT islamic_standard_links_type_valid
  CHECK (linkable_type IN ('product_family', 'account_type', 'accounting_mapping', 'contract_template', 'screening_policy'));

ALTER TABLE islamic_standard_links ADD CONSTRAINT islamic_standard_links_unique
  UNIQUE (islamic_standard_id, linkable_type, linkable_code);
```

Allowed `product_family` codes:

- `mourabaha`
- `ijara`
- `ijara_wa_iqtina`
- `salam`
- `istisnaa`
- `moudaraba`
- `moucharaka`

Allowed `account_type` codes:

- `islamic_current_account`
- `islamic_savings_account`
- `islamic_investment_account`

Validation for other link types:

- `accounting_mapping`: `linkable_code` must resolve to an existing `operation_account_mappings.public_id`; the mapping must join to an `operation_codes` row whose `module = islamic_finance`. Operation-code-only links are not accepted for this link type because IF-001 explicitly requires standards to link to accounting mappings.
- `contract_template`: until IF-032 creates templates, allow reserved codes only from the approved product-family template code set, e.g. `mourabaha_contract_template`, and mark them as `reserved`. When IF-032 lands, switch validation to actual template public IDs.
- `screening_policy`: until IF-020 creates policies, allow reserved codes only from the approved screening policy code set, e.g. `islamic_general_screening_policy`, and mark them as `reserved`. When IF-020 lands, switch validation to actual policy public IDs.

Proof by contradiction:

- Assume the registry can only link standards to products. False: link validation must accept all five IF-001 link types.
- Assume an arbitrary typo such as `murabha` creates a fake product baseline. False: link validation rejects unknown product-family and account-type codes.
- Assume a standard is linked to a generic operation code instead of an approved accounting mapping. False: `accounting_mapping` links must resolve to actual mapping public IDs.

### 1.3 Optional Link Metadata

If implementation needs to distinguish reserved links from real object links before IF-020/IF-032 exist, add nullable JSON `metadata` to `islamic_standard_links` with keys:

- `identifier_type`: `code`, `public_id`, or `reserved_code`.
- `reserved_until_backlog`: `IF-020`, `IF-032`, or null.
- `notes`.

This is optional for IF-001, but if reserved links are used, metadata must make that explicit.

## Phase 2: Workflow, Routes, And Authorization

### 2.1 New Workflow

Create `app/Application/IslamicFinance/IslamicStandardWorkflow.php`.

Methods:

| Method | Route | Description |
|---|---|---|
| `index(Request)` | `GET /api/v1/islamic-standards` | List with filters: source, status, linkable type, linkable code, owner type |
| `store(Request)` | `POST /api/v1/islamic-standards` | Create draft standard |
| `show(string $publicId)` | `GET /api/v1/islamic-standards/{publicId}` | Show standard with links |
| `updateDraft(Request, string $publicId)` | `PUT /api/v1/islamic-standards/{publicId}` | Update only while draft |
| `amend(Request, string $publicId)` | `POST /api/v1/islamic-standards/{publicId}/amend` | Create new draft version that supersedes an active standard |
| `activate(Request, string $publicId)` | `POST /api/v1/islamic-standards/{publicId}/activate` | Activate current or future-effective standard |
| `retire(Request, string $publicId)` | `POST /api/v1/islamic-standards/{publicId}/retire` | Retire active/expired/future-effective standard |
| `link(Request, string $publicId)` | `POST /api/v1/islamic-standards/{publicId}/links` | Link draft standard to governed object/family |
| `unlink(Request, string $publicId)` | `DELETE /api/v1/islamic-standards/{publicId}/links` | Remove link from draft standard only |

### 2.2 Validation Rules

`store` and `updateDraft`:

- `source`: required, in `AAOIFI`, `IFSB`, `COBAC`, `CEMAC`, `INTERNAL`, `LEGAL_OPINION`, `SHARIA_DECISION`, `POLICY`.
- `reference`: required, string, max 128.
- `title`: required, string, max 255.
- `version`: sometimes, nullable, string, max 64.
- `publication_date`: sometimes, nullable, date.
- `scope_summary`: required, string, max 4000.
- `owner_type`: required, in `user`, `role`, `department`, `committee`.
- `owner_user_public_id`: required if `owner_type = user`, exists in `users.public_id`.
- `owner_role`: required if `owner_type = role`, string, max 128.
- `owner_department`: required if `owner_type = department`, string, max 128.
- `owner_committee`: required if `owner_type = committee`, string, max 128.
- `effective_date`: required, date.
- `expiry_date`: sometimes, nullable, date, after `effective_date`.
- `document_public_id`: required, exists in active `documents.public_id`.
- `metadata`: sometimes, nullable, array.

`amend`:

- Same payload as `store`, except omitted fields may default from the source standard.
- Requires source standard status `active` or `expired`.
- Creates a new `draft` standard with `supersedes_standard_id` set.
- Copies links from the source standard unless request supplies explicit replacement links.
- Does not mutate the source standard until the amendment is activated.

`link`:

- `linkable_type`: required, in all five IF-001 types.
- `linkable_code`: required, string, max 128.
- `linkable_identifier`: sometimes, in `code`, `public_id`, `reserved_code`.
- Validate code according to the rules in section 1.2.
- Standard must be `draft`; active standards are amended through a new version instead of mutating links in place.

`activate`:

- No payload required.
- Standard must be `draft`.
- `document_id` must point to an active document.
- Must have at least one valid link.
- For product-readiness coverage, must have at least one `product_family` or `account_type` link. A standard linked only to an accounting mapping/template/policy is useful but cannot satisfy product/account readiness alone.
- Sets `activated_by_user_id` and `activated_at`.
- If activating an amendment whose `effective_date <= today`, supersede the previous active standard in the same transaction after the new standard is active.
- If activating an amendment whose `effective_date > today`, keep the previous standard active until the replacement effective date. The future-effective standard is active for governance scheduling but must not satisfy readiness until its effective date.
- A future-effective amendment must not create a baseline gap. The old standard remains usable until it expires, is retired, or is superseded on the replacement effective date.

`retire`:

- `reason`: required, string, max 4000.
- Standard must be `active` or `expired`.
- Sets status `retired`, `retired_by_user_id`, `retired_at`, and `retirement_reason`.

### 2.3 Authorization

Use the current platform-admin convention for the first implementation, but define permission names now so the endpoint can be moved to permission-based authorization without changing routes:

- `islamic.standards.view`
- `islamic.standards.create`
- `islamic.standards.update`
- `islamic.standards.activate`
- `islamic.standards.retire`
- `islamic.standards.link`

Initial guard:

- `platform-admin` can perform all operations.
- Tests must prove non-admin users cannot mutate standards.

Proof by contradiction:

- Assume an active baseline is silently altered by editing a link. False: active links cannot be mutated; amendment creates a new draft version.
- Assume a standard without attachment is activated. False: activation checks active document evidence.

## Phase 3: Reusable Standards Baseline Service

Create `app/Application/IslamicFinance/IslamicStandardsBaselineService.php`.

### 3.1 Core Methods

```php
public function hasActiveBaseline(string $linkableType, string $linkableCode, ?CarbonInterface $asOf = null): bool;

public function activationFailuresForProductFamily(string $productFamily, ?CarbonInterface $asOf = null): array;

public function activationFailuresForAccountType(string $accountType, ?CarbonInterface $asOf = null): array;
```

`hasActiveBaseline()` query requirements:

- Join `islamic_standard_links` to `islamic_standards`.
- `linkable_type = $linkableType`.
- `linkable_code = $linkableCode`.
- `status = active`.
- `effective_date <= $asOfDate`.
- `expiry_date IS NULL OR expiry_date > $asOfDate`.
- Linked document exists and `documents.status = active`.

Failure messages must distinguish:

- No standard linked.
- Linked standard exists but is draft/retired/superseded.
- Linked standard is future-effective.
- Linked standard is expired.
- Linked evidence document is missing or archived.
- A future-effective replacement exists, but the currently effective predecessor remains the valid baseline until replacement date.

Proof by contradiction:

- Assume a future-effective standard satisfies readiness. False: `effective_date <= asOfDate` is required.
- Assume an expired standard satisfies readiness. False: `expiry_date > asOfDate OR NULL` is required.
- Assume the standard record exists but its document has been archived. False: active document join is required.

### 3.2 Product Readiness Integration

Create or update `app/Application/IslamicFinance/IslamicProductReadinessService.php`.

Minimum IF-001 method:

```php
public function activationFailures(object $product): array;
```

For now it must check:

- `contract_type` maps to a supported product family.
- `IslamicStandardsBaselineService::hasActiveBaseline('product_family', $product->contract_type)` is true.

When IF-030/IF-031 are implemented, this service becomes the broader readiness checklist. IF-001 must still use it now so product approval does not hardcode the standards query in only one controller path.

Modify `IslamicProductWorkflow::reviewCompliance()`:

- Before setting product status to `approved`, call `IslamicProductReadinessService::activationFailures($product)`.
- If failures exist, reject with 422 and do not update product status or review status to approved.
- Keep maker-checker behavior unchanged.

Proof by contradiction:

- Assume a future route approves product readiness without calling `reviewCompliance()`. The reusable readiness service gives that route the same baseline gate; the plan must require new readiness transitions to call the service.
- Assume `reviewCompliance()` approves a product with no baseline. The readiness service returns a failure and the transaction aborts before status change.

## Phase 4: Lifecycle And Expiry Handling

### 4.1 Query-Time Validity Is The Hard Gate

Do not rely on a scheduled job to enforce expiry. All approval/readiness checks must evaluate validity at query time using `effective_date`, `expiry_date`, `status`, and active document evidence.

### 4.2 Expiry Status Upkeep

Add optional lifecycle upkeep helpers or console command only for lifecycle hygiene/reporting. They must not be required for correctness.

Expiry rules:

- Select `status = active` and `expiry_date <= today`.
- Update to `expired`.
- Write `islamic.standard.expired` audit event with actor `null` and metadata `actor_fallback = system` or equivalent properties.
- Tests for approval must pass even if this helper never runs, because query-time validity is authoritative.

Supersession rules:

- Select active future-effective amendment records where `effective_date <= today` and `supersedes_standard_id` points to a still-active predecessor.
- Update the predecessor to `superseded` only when the replacement is currently effective.
- Write `islamic.standard.superseded` audit event with actor `null` and metadata `actor_fallback = system` or equivalent properties.
- Readiness checks must still be correct even if the helper never runs: query-time validity must choose a currently effective active standard and exclude future-effective replacements.

Proof by contradiction:

- Assume the expiry job never runs. Expired standard still cannot approve a product because readiness checks evaluate date validity directly.
- Assume the expiry job runs without a user. Audit still records a system event.
- Assume a future-effective replacement is activated today and supersedes the current standard today. False: the predecessor cannot be marked `superseded` until the replacement effective date.

## Phase 5: Audit Events

All mutation events use `SecurityAudit::record()`.

| Event | Trigger | Required properties |
|---|---|---|
| `islamic.standard.created` | After draft create | `standard_public_id`, `source`, `reference`, `owner_type`, `document_public_id` |
| `islamic.standard.updated` | After draft update | `standard_public_id`, `changed_fields`, `before`, `after` |
| `islamic.standard.amended` | After amendment draft create | `old_standard_public_id`, `new_standard_public_id`, `changed_fields` |
| `islamic.standard.activated` | After activate | `standard_public_id`, `source`, `reference`, `effective_date`, `expiry_date`, `linkable_count` |
| `islamic.standard.superseded` | When activating amendment supersedes previous standard | `old_standard_public_id`, `new_standard_public_id` |
| `islamic.standard.expired` | Expiry upkeep changes status | `standard_public_id`, `previous_status`, `new_status`, `expiry_date` |
| `islamic.standard.retired` | After retire | `standard_public_id`, `previous_status`, `new_status`, `reason` |
| `islamic.standard.linked` | After link create | `standard_public_id`, `linkable_type`, `linkable_code` |
| `islamic.standard.unlinked` | After link remove | `standard_public_id`, `linkable_type`, `linkable_code` |

Audit must occur inside or immediately after successful transactions. Failed attempts do not need mutation events, but validation failures should return precise API errors.

Proof by contradiction:

- Assume a standard amendment is invisible to auditors. False: amendment creates a new draft version and records `islamic.standard.amended` with old and new public IDs.
- Assume activation overwrites the only active record without history. False: activating an amendment supersedes the previous standard and records `islamic.standard.superseded`.

## Phase 6: API Response Shape

Responses must use public IDs only.

Standard payload:

```json
{
  "public_id": "01...",
  "source": "AAOIFI",
  "reference": "AAOIFI-SS-8",
  "title": "Murabaha standard",
  "version": "2024",
  "publication_date": "2024-01-01",
  "scope_summary": "Applies to Mourabaha product family.",
  "owner": {
    "type": "committee",
    "label": "Sharia Board"
  },
  "effective_date": "2026-05-24",
  "expiry_date": null,
  "status": "active",
  "document_public_id": "01...",
  "supersedes_standard_public_id": null,
  "links": [
    {"public_id": "01...", "type": "product_family", "code": "mourabaha"}
  ],
  "created_at": "2026-05-24T00:00:00+01:00"
}
```

Do not expose internal numeric IDs.

## Phase 7: Routes And Files

Files to create:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_standards_tables.php`
- `app/Application/IslamicFinance/IslamicStandardWorkflow.php`
- `app/Application/IslamicFinance/IslamicStandardsBaselineService.php`
- `app/Application/IslamicFinance/IslamicProductReadinessService.php`

Files to modify:

- `app/Application/IslamicFinance/IslamicFinanceWorkflowControllerAdapter.php`
- `app/Http/Controllers/Api/V1/IslamicFinanceController.php`
- `routes/api/v1/islamic_finance.php`
- `app/Application/IslamicFinance/IslamicProductWorkflow.php`
- `config/security.php` or role seeder if permissions are made explicit immediately
- `tests/Feature/Api/IslamicFinanceTest.php`

Routes to add inside existing `auth:sanctum` Islamic finance route group:

- `GET /api/v1/islamic-standards`
- `POST /api/v1/islamic-standards`
- `GET /api/v1/islamic-standards/{standardPublicId}`
- `PUT /api/v1/islamic-standards/{standardPublicId}`
- `POST /api/v1/islamic-standards/{standardPublicId}/amend`
- `POST /api/v1/islamic-standards/{standardPublicId}/activate`
- `POST /api/v1/islamic-standards/{standardPublicId}/retire`
- `POST /api/v1/islamic-standards/{standardPublicId}/links`
- `DELETE /api/v1/islamic-standards/{standardPublicId}/links`

## Phase 8: Tests

### Test 1: Product readiness fails without active standards baseline

Given a draft Mourabaha product and no standard linked to `product_family = mourabaha`, when a compliance review is approved, then approval returns 422, product remains draft, review remains pending or rejected according to implementation decision, and error says no active standards baseline.

Contradiction disproved: a product cannot be approved against no identifiable standard.

### Test 2: Future-effective standard blocks approval

Given an active standard linked to `mourabaha` with `effective_date` tomorrow, when the product compliance review is approved today, approval returns 422 with a future-effective baseline failure.

Contradiction disproved: a standard not yet in force cannot govern approvals.

### Test 3: Expired standard blocks new approvals

Given an active standard linked to `mourabaha` with `expiry_date` yesterday, when product compliance review is approved, approval returns 422 and says no currently active standards baseline.

Contradiction disproved: an expired standard cannot count toward baseline, even if status remains `active`.

### Test 4: Archived attachment blocks baseline

Given an active standard linked to `mourabaha` whose attached document is archived, when product compliance review is approved, approval returns 422 because evidence is not active.

Contradiction disproved: a standard without active evidence cannot govern approvals.

### Test 5: Valid baseline allows approval

Given an active standard linked to `mourabaha`, with `effective_date <= today`, no expiry or future expiry, and active document evidence, when product compliance review is approved by a different actor, product status becomes `approved`.

Contradiction preserved: the gate blocks invalid baselines but does not block valid ones.

### Test 6: Standards amendment creates audit event and does not mutate active standard

Given an active standard, when amendment endpoint is called with a changed title or scope, then a new draft standard is created with `supersedes_standard_id`, the original remains unchanged, and `activity_log` contains `islamic.standard.amended`.

Contradiction disproved: active baseline history cannot be silently rewritten.

### Test 7: Draft update creates audit event

Given a draft standard, when its scope or owner is updated, then `activity_log` contains `islamic.standard.updated` with changed fields.

Contradiction disproved: draft amendment is visible to auditors.

### Test 8: Activation requires attachment and link

Given a draft standard with no active document or no links, when activation is called, then activation fails with 422 and status remains draft.

Contradiction disproved: an unevidenced or unscoped standard cannot become a baseline.

### Test 9: Link supports all IF-001 link targets

For each link type `product_family`, `account_type`, `accounting_mapping`, `contract_template`, and `screening_policy`, a valid link request succeeds and an invalid code request fails.

Contradiction disproved: the implementation is not product-only and cannot use typos as fake baselines.

For `accounting_mapping`, the valid request must use an `operation_account_mappings.public_id` whose operation code module is `islamic_finance`; an operation-code-only value must fail.

Contradiction disproved: a standard cannot claim to govern a concrete accounting mapping when it only points to a generic operation code.

### Test 10: Non-admin cannot mutate standards

Given a non-admin authenticated user, all create/update/activate/retire/link/unlink requests return forbidden.

Contradiction disproved: untrusted staff cannot define governance standards.

### Test 11: Retired and superseded standards do not satisfy readiness

Given a standard linked to `mourabaha` with status `retired` or `superseded`, product approval fails with no active standards baseline.

Contradiction disproved: historical standards cannot govern new approvals.

### Test 12: Future-effective amendment does not create baseline gap

Given an active current standard linked to `mourabaha` and a future-effective amendment that supersedes it, when product compliance review is approved before the replacement effective date, approval succeeds through the current standard and the current standard remains active.

Contradiction disproved: scheduling a replacement cannot accidentally remove the current valid baseline.

### Test 13: Future-effective amendment becomes the valid baseline only on effective date

Given an active current standard and an active future-effective amendment that supersedes it, when the as-of date reaches the replacement effective date, readiness uses the replacement standard and lifecycle upkeep may mark the predecessor superseded with an audit event.

Contradiction disproved: future replacements are not used early and old standards are not kept as current after replacement date.

### Test 14: API responses expose public IDs only

Standard list/show/create/update/activate responses include public IDs and do not expose internal `id`, `document_id`, `created_by_user_id`, or other numeric foreign keys.

Contradiction disproved: API consumers cannot depend on internal numeric IDs.

## Phase 9: Test Execution Instructions For IF-001

Use the narrowest relevant test loop while implementing IF-001. This is not an MVP limitation; it is a feedback-loop rule. The full behavior still needs coverage, but unrelated slow paths should not control every implementation iteration.

Recommended implementation loop:

```bash
php artisan test tests/Feature/Api/IslamicFinanceTest.php --filter standards --compact
php artisan test --filter IslamicFinanceTest --compact
```

If IF-001 receives a dedicated standards-registry test file, run it directly:

```bash
php artisan test tests/Feature/Api/IslamicStandardsTest.php --compact
```

Run unit/service-level checks first when changing `IslamicStandardsBaselineService`, `IslamicProductReadinessService`, DTOs, validators, or policy helpers:

```bash
composer test:unit
```

Run the full suite through Laravel-managed parallel databases only:

```bash
composer test
```

Equivalent explicit command:

```bash
php artisan test --parallel
```

If parallel test databases are stale or schema-drifted, recreate them:

```bash
php artisan test --parallel --recreate-databases
```

Profile slow tests before guessing:

```bash
composer test:profile
php artisan test tests/Feature/Api/IslamicFinanceTest.php --profile
```

Command rules:

- Put `--parallel` before path arguments: `php artisan test --parallel tests/Feature/Api/IslamicFinanceTest.php`.
- Do not pass multiple file paths to `php artisan test`; use a directory, `--filter`, a testsuite, or run files one at a time.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently against `habis_finance_api_test`; they race migrations and create false failures.
- Treat `composer test` as the full-suite command; it currently uses Laravel parallel testing instead of the old hour-scale serial run.
- Known current full-suite failures outside IF-001 are tracked in `docs/operations/laravel-test-performance.md`; do not let unrelated failures hide IF-001 regressions.

Proof by contradiction for these rules:

- Assume concurrent non-parallel test commands are acceptable. They share the same test database, so migrations and table creation race each other, producing failures unrelated to IF-001. Therefore concurrent non-parallel commands are invalid for investigation or verification.
- Assume the serial full suite is the correct default loop. A full run can consume implementation-scale time before showing a local IF-001 failure. Therefore narrow tests plus a parallel full-suite gate are the correct sequence.
- Assume multiple file paths can be passed to `php artisan test`. Laravel's command accepts a single path argument, so the command fails before executing the intended tests. Therefore multi-file targeting must use filters, directories, suites, or separate commands.

## Phase 10: Explicit Non-Goals For IF-001

These are not implemented in IF-001, but IF-001 must leave safe hooks for them:

- Full Sharia authority membership model: IF-010.
- Full compliance case management: IF-012.
- Actual contract template registry: IF-032.
- Actual Haram screening policy registry: IF-020.
- Full accounting mapping approval workflow: IF-051.

Reserved link codes are acceptable only when marked as reserved metadata and tested. They must not be treated as proof that later backlog items are implemented.

## Final Soundness Checklist

Before implementation starts, the plan is sound only if every answer is yes:

- Can a product be approved with no standard linked to its product family? No.
- Can a future-effective standard approve a product today? No.
- Can an expired, retired, superseded, or draft standard approve a product? No.
- Can a standard with archived or missing document evidence approve a product? No.
- Can a typo product family create a fake baseline? No.
- Can a standard link to all IF-001-required target classes? Yes.
- Can an active standard be changed in place? No.
- Can auditors see creation, update, amendment, activation, expiry, retirement, linking, and unlinking? Yes.
- Can readiness logic be reused outside the current compliance-review endpoint? Yes.
