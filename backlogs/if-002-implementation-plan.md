# IF-002 Implementation Plan: Capture Local Regulatory Sign-Off

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-002
Proof-method: proof by contradiction

## IF-002 Source Requirement

Goal: require legal/compliance sign-off for Cameroon and CEMAC/COBAC treatment before production activation.

Proof-by-contradiction invariant: assume Islamic finance is activated without local regulatory sign-off. Activation must be impossible because legal clearance is missing.

Acceptance criteria:

- Store jurisdiction, regulator, opinion summary, allowed products, restrictions, accounting implications, responsible owner, approval date, and evidence.
- Link sign-off to product family and account type.
- Block production activation when sign-off is absent, expired, or restrictive.

Tests:

- Product can be configured in draft without sign-off.
- Product cannot activate without sign-off.
- Restrictive sign-off blocks disallowed product family.

## Architecture Context

Current product activation gate is implemented in `app/Application/IslamicFinance/IslamicProductReadinessService.php` and enforced during compliance review approval in `IslamicProductWorkflow::reviewCompliance()`.

This plan adds local-regulatory sign-off as an additional readiness gate in the same path, so "approved" status cannot be granted without both:

- active standards baseline (IF-001), and
- active local regulatory sign-off coverage (IF-002).

Interpretation note:

- In the current codebase, "production activation" for Islamic products is represented by transition to `islamic_products.status = approved` after compliance approval.
- Contract origination already requires approved product status, so blocking approval blocks practical production use.

## Completion Definition For This Plan

IF-002 is sound only if all are true:

- Islamic product drafts can be created and reviewed without sign-off.
- A product cannot become `approved` unless a valid sign-off exists for its product family.
- Expired, revoked, suspended, draft, or future-effective sign-off does not satisfy readiness.
- Restrictive sign-off blocks disallowed product families/account types.
- Evidence document and accountable owner are mandatory for active sign-off.
- Sign-off creation, lifecycle changes, link changes, and readiness-block events are audited.
- Account-type sign-off linkage is first-class in storage and validation now, even where immediate runtime gating is product-family-based.

## Phase 1: Data Model

### 1.1 `islamic_regulatory_signoffs` Table

Create migration:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_regulatory_signoffs_tables.php`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `jurisdiction` (`string(64)`) values include `cameroon`, `cemac`
- `regulator` (`string(64)`) values include `cobac`, `beac`, `minfi`, `other`
- `opinion_reference` (`string(191)`) memo/ref code
- `opinion_summary` (`text`) legal/compliance interpretation summary
- `approval_type` (`string(32)`) values: `allow_with_conditions`, `allow`, `deny`
- `restrictions` (`json()->nullable()`) structured restrictions and conditions
- `accounting_implications` (`text()->nullable()`) posting/reporting constraints
- `owner_type` (`string(32)`) values: `user`, `role`, `department`, `committee`
- `owner_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `owner_role` (`string(128)->nullable()`)
- `owner_department` (`string(128)->nullable()`)
- `owner_committee` (`string(128)->nullable()`)
- `approved_on` (`date`) legal approval date
- `effective_date` (`date`)
- `expiry_date` (`date()->nullable()`)
- `status` (`string(32)->default('draft')`) values: `draft`, `active`, `suspended`, `revoked`, `expired`, `retired`
- `document_id` (`foreignId()->constrained('documents')->restrictOnDelete()`)
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `activated_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `activated_at` (`timestamp()->nullable()`)
- `retired_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `retired_at` (`timestamp()->nullable()`)
- `retirement_reason` (`text()->nullable()`)
- `metadata` (`json()->nullable()`)
- timestamps

Key constraints:

- `jurisdiction` enum check
- `regulator` enum check
- `approval_type` enum check
- `status` enum check
- ownership XOR check (exactly one owner identity)
- `expiry_date IS NULL OR expiry_date > effective_date`
- active status requires activation actor and timestamp
- retired status requires retired fields and reason

Proof by contradiction:

- Assume an active sign-off has no legal evidence. Insert/update fails because `document_id` is required and must be active at activation.
- Assume sign-off ownership is anonymous text. Insert/update fails because owner XOR check requires one accountable owner form.
- Assume a deny decision can be accidentally used as permissive. Gate logic rejects `approval_type = deny`.

### 1.2 `islamic_regulatory_signoff_links` Table

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `islamic_regulatory_signoff_id` (`foreignId()->constrained('islamic_regulatory_signoffs')->cascadeOnDelete()`)
- `linkable_type` (`string(64)`) values: `product_family`, `account_type`
- `linkable_code` (`string(128)`)
- `restriction_mode` (`string(16)->default('allow')`) values: `allow`, `deny`
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- timestamps

Constraints:

- `linkable_type` enum check
- `restriction_mode` enum check
- unique key on (`islamic_regulatory_signoff_id`, `linkable_type`, `linkable_code`)

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

Proof by contradiction:

- Assume typo family `murabha` creates fake regulatory coverage. Validation rejects unsupported link code.
- Assume a sign-off with only deny links still counts as activation coverage. Gate logic rejects because no permissive active link applies.

## Phase 2: Workflow, Routes, Authorization

### 2.1 New Workflow

Create:

- `app/Application/IslamicFinance/IslamicRegulatorySignoffWorkflow.php`

Methods:

- `index(Request)` -> `GET /api/v1/islamic-regulatory-signoffs`
- `store(Request)` -> `POST /api/v1/islamic-regulatory-signoffs` (draft)
- `show(string $publicId)` -> `GET /api/v1/islamic-regulatory-signoffs/{publicId}`
- `updateDraft(Request, string $publicId)` -> `PUT /api/v1/islamic-regulatory-signoffs/{publicId}`
- `activate(Request, string $publicId)` -> `POST /api/v1/islamic-regulatory-signoffs/{publicId}/activate`
- `suspend(Request, string $publicId)` -> `POST /api/v1/islamic-regulatory-signoffs/{publicId}/suspend`
- `revoke(Request, string $publicId)` -> `POST /api/v1/islamic-regulatory-signoffs/{publicId}/revoke`
- `retire(Request, string $publicId)` -> `POST /api/v1/islamic-regulatory-signoffs/{publicId}/retire`
- `link(Request, string $publicId)` -> `POST /api/v1/islamic-regulatory-signoffs/{publicId}/links`
- `unlink(Request, string $publicId)` -> `DELETE /api/v1/islamic-regulatory-signoffs/{publicId}/links`

### 2.2 Validation Rules

`store/updateDraft` require:

- jurisdiction, regulator, opinion reference and summary
- approval type
- accounting implications (nullable, but present when `approval_type = allow_with_conditions` and restrictions reference accounting controls)
- owner identity by owner type
- approved/effective dates
- evidence document public id

`link` rules:

- link type in `product_family/account_type`
- code must be in approved registry values
- restriction mode in `allow/deny`
- only draft sign-off is link-mutable

`activate` rules:

- status must be `draft`
- evidence document must be active
- must have at least one link
- must have at least one `allow` link
- if `approval_type = deny`, activation blocked

`suspend/revoke/retire` rules:

- reason mandatory
- only active sign-offs can be suspended/revoked
- retired terminally removes from readiness eligibility

Proof by contradiction:

- Assume sign-off can be activated while unresolved as draft. Activation must fail.
- Assume restrictive sign-off with only deny links can be used for approval. Activation/readiness must fail.
- Assume revoked sign-off remains valid by timestamp. Readiness must still fail due to status gate.

### 2.3 Authorization

Phase-1 authorization follows existing platform-admin convention used in current Islamic finance workflows.

Declare target permissions now for migration path:

- `islamic.regulatory_signoffs.view`
- `islamic.regulatory_signoffs.create`
- `islamic.regulatory_signoffs.update`
- `islamic.regulatory_signoffs.activate`
- `islamic.regulatory_signoffs.suspend`
- `islamic.regulatory_signoffs.revoke`
- `islamic.regulatory_signoffs.retire`
- `islamic.regulatory_signoffs.link`

## Phase 3: Readiness Gate Integration

### 3.1 New Service

Create:

- `app/Application/IslamicFinance/IslamicRegulatorySignoffService.php`

Methods:

- `activationFailuresForProductFamily(string $productFamily, ?CarbonInterface $asOf = null): array`
- `activationFailuresForAccountType(string $accountType, ?CarbonInterface $asOf = null): array`

Gate rules:

- Eligible sign-off must be `active`
- `effective_date <= asOf`
- `expiry_date` absent or `> asOf`
- sign-off not suspended/revoked/retired
- evidence document active
- at least one applicable `allow` link for target family/type in scope
- no applicable `deny` link for same target on same as-of date
- if both allow and deny apply, deny wins

### 3.2 Wire Into Product Readiness

Update:

- `app/Application/IslamicFinance/IslamicProductReadinessService.php`

Inject `IslamicRegulatorySignoffService` and combine failures:

- standards baseline failures (existing IF-001 service)
- regulatory sign-off failures (new IF-002 service)

Behavior:

- drafts remain unaffected
- compliance approval path (`IslamicProductWorkflow::reviewCompliance`) continues to call readiness; now includes regulatory gate

Proof by contradiction:

- Assume sign-off is absent but product is approved. Impossible because readiness returns failures and compliance approval throws `StandardsBaselineFailure`-style gate error (rename error class if needed).
- Assume sign-off expires after creation but before approval. Readiness uses as-of date and blocks approval.
- Assume sign-off exists for account type only while product-family link is absent for product approval. Approval still fails because product approval gate requires product-family coverage.

## Phase 4: Routing and Controller Adapter

Update:

- `routes/api/v1/islamic_finance.php`
- `app/Application/IslamicFinance/IslamicFinanceWorkflowControllerAdapter.php`
- `app/Http/Controllers/Api/V1/IslamicFinanceController.php`

Expose sign-off endpoints through existing Islamic controller adapter pattern.

## Phase 5: Audit Events

Record with `SecurityAudit::record()`:

- `islamic.regulatory_signoff.created`
- `islamic.regulatory_signoff.updated`
- `islamic.regulatory_signoff.activated`
- `islamic.regulatory_signoff.suspended`
- `islamic.regulatory_signoff.revoked`
- `islamic.regulatory_signoff.retired`
- `islamic.regulatory_signoff.linked`
- `islamic.regulatory_signoff.unlinked`
- `islamic.regulatory_signoff.readiness_blocked`

Properties include sign-off public id, jurisdiction, regulator, status transition, link target, and reason.

## Phase 6: API Contracts

List response fields include:

- `public_id`
- `jurisdiction`
- `regulator`
- `approval_type`
- `status`
- `effective_date`
- `expiry_date`
- `approved_on`

Show response includes:

- all fields above
- opinion summary
- accounting implications
- owner descriptor
- evidence document public id
- links with type/code/restriction mode

Never expose internal numeric IDs.

## Phase 7: Tests

Add/extend feature tests in:

- `tests/Feature/Api/IslamicFinanceTest.php`

Minimum tests:

1. Draft product can be created and submitted for review request without regulatory sign-off.
2. Product approval (compliance decision `approve`) fails with `422` when no active sign-off covers product family.
3. Product approval fails when sign-off exists but is future-effective.
4. Product approval fails when sign-off is expired.
5. Product approval fails when sign-off is suspended or revoked.
6. Product approval fails when sign-off has deny restriction for target family.
7. Product approval passes when active sign-off contains matching allow link and no deny.
8. Link validation rejects unsupported family/type codes.
9. Active sign-off cannot be edited in place; mutation requires status transition flow.
10. Audit entries are created for lifecycle transitions and readiness block.
11. Sign-off link endpoint accepts valid `account_type` links and rejects invalid account-type codes.
12. `IslamicRegulatorySignoffService::activationFailuresForAccountType()` returns failures when account-type sign-off is absent/expired/restrictive and returns no failures when valid coverage exists.

Proof-by-contradiction alignment tests:

- `test_product_can_be_configured_in_draft_without_signoff`
- `test_product_cannot_be_approved_without_signoff`
- `test_restrictive_signoff_blocks_disallowed_product_family`

## Phase 8: Adversarial Review (Round 1)

Findings:

1. Risk: "production activation" in backlog could mean contract origination, not only product approval.
2. Risk: deny and allow precedence was unspecified.
3. Risk: sign-off scope might require both product family and account type simultaneously for some products.

Fixes applied in this plan:

1. Gate attached to readiness approval first (current activation seam); this is the decisive production gate in current architecture because origination requires approved product.
2. Explicit rule added: deny wins over allow for same target/as-of.
3. Service split by family/type with both methods so caller can enforce combined checks later.

Residual action:

- Add follow-up backlog note to call sign-off gate directly in future account-type activation/origination entry points that do not pass through product approval.

## Phase 9: Adversarial Review (Round 2)

Findings:

1. Ambiguity: status `expired` can be derived by date; storing it may drift.
2. Ambiguity: restriction JSON could become unbounded free text.
3. Naming: `StandardsBaselineFailure` may become semantically narrow once reused.

Fixes applied in this plan:

1. Eligibility uses date checks first; stored status alone never grants eligibility.
2. Restriction JSON limited to structured keys (conditions, prohibited_features, accounting_limits, notes) in request validation.
3. Implementation note added to rename generic gate exception (e.g., `ReadinessGateFailure`) if both standards and sign-off failures are merged.

## Phase 10: Adversarial Review (Round 3)

Findings:

1. Backlog requires linking sign-off to both product family and account type, but current approval gate only has product-family context.
2. Risk that implementers defer account-type logic entirely and ship only product-family linkage.

Fixes applied in this plan:

1. Account-type linkage remains mandatory in the IF-002 data model and link validation surface from day one.
2. Added explicit test requirements for account-type link validation and account-type service gating behavior, independent of product approval flow.
3. Added completion criterion that account-type linkage must be implemented as first-class persisted behavior even if some runtime entry points consume it later.

## Phase 11: Final Soundness Checklist

The plan is sound only if every answer is yes:

- Can a product draft exist without any regulatory sign-off? Yes.
- Can a product become approved with no regulatory sign-off coverage? No.
- Is "production activation" concretely mapped to current `approved` readiness gate for this codebase? Yes.
- Can future-effective, expired, suspended, revoked, retired, or draft sign-offs satisfy readiness? No.
- Can deny restrictions be bypassed by a concurrent allow link? No.
- Can unknown product family/account type codes create fake coverage? No.
- Are account-type links implemented and validated now (not deferred)? Yes.
- Can active sign-off exist without evidence and accountable owner? No.
- Are lifecycle and linking actions auditable? Yes.
- Is the gate reusable for later origination and account-activation checks? Yes.

## Implementation Evidence (2026-05-25)

Contradiction finding discovered:

1. IF-002 required readiness-block auditing at regulatory-signoff level (`islamic.regulatory_signoff.readiness_blocked`), but failures were only logged as generic product readiness blocks.

Fix applied:

1. Added gate-specific audit emission in `IslamicProductWorkflow::reviewCompliance()`:
   - when `islamic_regulatory_signoff` fails, record `islamic.regulatory_signoff.readiness_blocked` with review id and reasons.
2. Extended contradiction tests in `IslamicRegulatorySignoffTest` to assert this event is persisted for both:
   - pure sign-off failure path
   - combined standards + sign-off failure path.

Verification runs:

```bash
php artisan test --parallel --recreate-databases --filter IslamicRegulatorySignoffTest
```

Result:
- `OK (21 tests, 261 assertions)` in ~8.7s.

```bash
composer test
```

Result:
- `OK (565 tests, 8548 assertions)` in ~41.7s.
