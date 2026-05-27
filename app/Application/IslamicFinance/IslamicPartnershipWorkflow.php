<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IslamicPartnershipWorkflow extends BaseController
{
    public const TYPE_MOUDARABA = 'moudaraba';

    public const TYPE_MOUCHARAKA = 'moucharaka';

    public const TYPES = [self::TYPE_MOUDARABA, self::TYPE_MOUCHARAKA];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LIQUIDATED = 'liquidated';

    public const STATUS_TERMINATED = 'terminated';

    public const ROLE_CAPITAL_PROVIDER = 'capital_provider';

    public const ROLE_ENTREPRENEUR = 'entrepreneur';

    public const ROLE_JOINT_PARTNER = 'joint_partner';

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    public function storePartnership(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'islamic_financing_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_financings,public_id'],
            'partnership_type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'governance_rights' => ['sometimes', 'nullable', 'array'],
            'reporting_cadence' => ['required', 'string', 'max:32'],
            'loss_rules' => ['sometimes', 'nullable', 'array'],
            'exit_terms' => ['sometimes', 'nullable', 'array'],
            'expected_total_capital_minor' => ['required', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($validated): object {
                $financingId = null;
                $financingPublicId = is_string($validated['islamic_financing_public_id'] ?? null) ? $validated['islamic_financing_public_id'] : null;
                if ($financingPublicId !== null && $financingPublicId !== '') {
                    $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first(['id']);
                    if (! is_object($financing) || ! is_numeric($financing->id)) {
                        throw new InvalidArgumentException('Linked Islamic financing is invalid.');
                    }
                    $financingId = (int) $financing->id;
                }
                $id = DB::table('islamic_partnerships')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $financingId,
                    'partnership_type' => (string) $validated['partnership_type'],
                    'governance_rights' => isset($validated['governance_rights']) && is_array($validated['governance_rights']) ? json_encode($validated['governance_rights'], JSON_THROW_ON_ERROR) : null,
                    'reporting_cadence' => (string) $validated['reporting_cadence'],
                    'loss_rules' => isset($validated['loss_rules']) && is_array($validated['loss_rules']) ? json_encode($validated['loss_rules'], JSON_THROW_ON_ERROR) : null,
                    'exit_terms' => isset($validated['exit_terms']) && is_array($validated['exit_terms']) ? json_encode($validated['exit_terms'], JSON_THROW_ON_ERROR) : null,
                    'status' => self::STATUS_DRAFT,
                    'expected_total_capital_minor' => (int) $validated['expected_total_capital_minor'],
                    'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnerships')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Partnership could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.created', actor: $actor, properties: [
            'partnership_public_id' => $this->rowString($row, 'public_id'),
            'partnership_type' => $this->rowString($row, 'partnership_type'),
        ], request: $request);

        return $this->respondCreated($this->partnershipPayload($row), 'Partnership registered');
    }

    public function addPartner(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'partner_role' => ['required', 'string', 'in:'.self::ROLE_CAPITAL_PROVIDER.','.self::ROLE_ENTREPRENEUR.','.self::ROLE_JOINT_PARTNER],
            'partner_reference' => ['required', 'string', 'max:128'],
            'profit_share_ratio' => ['required', 'numeric', 'min:0', 'max:1'],
            'loss_share_ratio' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'expected_contribution_minor' => ['required', 'integer', 'min:0'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                if ($this->rowString($partnership, 'status') !== self::STATUS_DRAFT) {
                    throw new InvalidArgumentException('Partners can only be added in draft status.');
                }
                $type = $this->rowString($partnership, 'partnership_type');
                $role = (string) $validated['partner_role'];
                if ($type === self::TYPE_MOUDARABA && $role === self::ROLE_JOINT_PARTNER) {
                    throw new InvalidArgumentException('Moudaraba partnership requires capital_provider/entrepreneur roles, not joint_partner (IF-043 rule).');
                }
                if ($type === self::TYPE_MOUCHARAKA && in_array($role, [self::ROLE_CAPITAL_PROVIDER, self::ROLE_ENTREPRENEUR], true)) {
                    throw new InvalidArgumentException('Moucharaka partnership requires joint_partner role; capital_provider/entrepreneur roles belong to Moudaraba (IF-043 rule).');
                }
                $id = DB::table('islamic_partnership_partners')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'partner_role' => $role,
                    'partner_reference' => (string) $validated['partner_reference'],
                    'profit_share_ratio' => (float) $validated['profit_share_ratio'],
                    'loss_share_ratio' => isset($validated['loss_share_ratio']) && is_numeric($validated['loss_share_ratio']) ? (float) $validated['loss_share_ratio'] : null,
                    'expected_contribution_minor' => (int) $validated['expected_contribution_minor'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_partners')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Partner could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership_partner' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->partnerPayload($row), 'Partner added');
    }

    public function storeContribution(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'partner_public_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'contributed_on' => ['required', 'date'],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                $partner = DB::table('islamic_partnership_partners')->where('public_id', (string) $validated['partner_public_id'])->first();
                if (! is_object($partner) || (int) $partner->islamic_partnership_id !== $this->rowInt($partnership, 'id')) {
                    throw new InvalidArgumentException('Partner does not belong to this partnership.');
                }
                $id = DB::table('islamic_partnership_contributions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'islamic_partnership_partner_id' => (int) $partner->id,
                    'amount_minor' => (int) $validated['amount_minor'],
                    'contributed_on' => (string) $validated['contributed_on'],
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('islamic_partnerships')->where('id', $this->rowInt($partnership, 'id'))->update([
                    'contributed_total_capital_minor' => DB::raw('contributed_total_capital_minor + '.(int) $validated['amount_minor']),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_contributions')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Contribution could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership_contribution' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.contribution.recorded', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
            'contribution_public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
        ], request: $request);

        return $this->respondCreated($this->contributionPayload($row), 'Contribution recorded');
    }

    public function activatePartnership(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                if ($this->rowString($partnership, 'status') !== self::STATUS_DRAFT) {
                    throw new InvalidArgumentException('Only draft partnerships can be activated.');
                }
                $expected = $this->rowInt($partnership, 'expected_total_capital_minor');
                $contributed = $this->rowInt($partnership, 'contributed_total_capital_minor');
                if ($contributed < $expected) {
                    throw new InvalidArgumentException(sprintf('Partnership activation requires contributed capital (%d) to match or exceed expected (%d) — IF-043 contribution gate.', $contributed, $expected));
                }
                $partners = DB::table('islamic_partnership_partners')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->get(['id', 'partner_role', 'expected_contribution_minor']);
                if ($partners->isEmpty()) {
                    throw new InvalidArgumentException('Partnership requires at least one partner.');
                }
                $partnershipType = $this->rowString($partnership, 'partnership_type');
                if ($partnershipType === self::TYPE_MOUDARABA) {
                    $hasCapitalProvider = $partners->contains(static fn (object $partner): bool => (string) ($partner->partner_role ?? '') === self::ROLE_CAPITAL_PROVIDER);
                    $hasEntrepreneur = $partners->contains(static fn (object $partner): bool => (string) ($partner->partner_role ?? '') === self::ROLE_ENTREPRENEUR);
                    if (! $hasCapitalProvider || ! $hasEntrepreneur) {
                        throw new InvalidArgumentException('Moudaraba activation requires both capital_provider and entrepreneur partners (IF-043 role gate).');
                    }
                }
                if ($partnershipType === self::TYPE_MOUCHARAKA) {
                    if ($partners->count() < 2) {
                        throw new InvalidArgumentException('Moucharaka activation requires at least two joint partners (IF-043 role gate).');
                    }
                    $invalidRole = $partners->contains(static fn (object $partner): bool => (string) ($partner->partner_role ?? '') !== self::ROLE_JOINT_PARTNER);
                    if ($invalidRole) {
                        throw new InvalidArgumentException('Moucharaka activation requires joint_partner role for every partner (IF-043 role gate).');
                    }
                    $missingExpectedContribution = $partners->contains(static fn (object $partner): bool => ! is_numeric($partner->expected_contribution_minor ?? null) || (int) $partner->expected_contribution_minor <= 0);
                    if ($missingExpectedContribution) {
                        throw new InvalidArgumentException('Moucharaka activation requires each partner to have a positive expected contribution (IF-043 contribution structure gate).');
                    }
                }
                $partnerIdsWithContribution = DB::table('islamic_partnership_contributions')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->whereNotNull('evidence_document_public_id')
                    ->pluck('islamic_partnership_partner_id')
                    ->unique()
                    ->values();
                $expectedPartnerIds = DB::table('islamic_partnership_partners')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->where('expected_contribution_minor', '>', 0)
                    ->pluck('id')
                    ->all();
                foreach ($expectedPartnerIds as $partnerId) {
                    if (! $partnerIdsWithContribution->contains($partnerId)) {
                        throw new InvalidArgumentException('All partners with expected contributions must have evidence-backed contributions — IF-043 contribution evidence gate.');
                    }
                }
                DB::table('islamic_partnerships')->where('id', $this->rowInt($partnership, 'id'))->update([
                    'status' => self::STATUS_ACTIVE,
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnerships')->where('id', $this->rowInt($partnership, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Partnership could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.activated', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
        ], request: $request);

        return $this->respondSuccess($this->partnershipPayload($row), 'Partnership activated');
    }

    public function storeReport(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'period_code' => ['required', 'string', 'max:64'],
            'distributable_profit_minor' => ['required', 'integer', 'min:0'],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                if ($this->rowString($partnership, 'status') !== self::STATUS_ACTIVE) {
                    throw new InvalidArgumentException('Reports can only be filed for active partnerships.');
                }
                $id = DB::table('islamic_partnership_reports')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'period_code' => (string) $validated['period_code'],
                    'distributable_profit_minor' => (int) $validated['distributable_profit_minor'],
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'approval_status' => 'approved',
                    'reported_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_reports')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Report could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership_report' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->reportPayload($row), 'Partnership report filed');
    }

    public function storeProfitDeclaration(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'period_code' => ['required', 'string', 'max:64'],
            'amount_minor' => ['required', 'integer', 'min:1'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                if ($this->rowString($partnership, 'status') !== self::STATUS_ACTIVE) {
                    throw new InvalidArgumentException('Profit can only be declared for active partnerships.');
                }
                $blockingLoss = DB::table('islamic_partnership_losses')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->where('blocks_distribution', true)
                    ->exists();
                if ($blockingLoss) {
                    throw new InvalidArgumentException('Profit declaration blocked: unresolved blocking loss/misconduct events (IF-043 distribution gate).');
                }
                $report = DB::table('islamic_partnership_reports')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->where('period_code', (string) $validated['period_code'])
                    ->where('approval_status', 'approved')
                    ->first(['id', 'distributable_profit_minor']);
                if (! is_object($report)) {
                    throw new InvalidArgumentException('Profit declaration requires an approved report for the same period (IF-043 declaration gate).');
                }
                $amount = (int) $validated['amount_minor'];
                $distributable = (int) $report->distributable_profit_minor;
                if ($amount > $distributable) {
                    throw new InvalidArgumentException(sprintf('Declared amount (%d) exceeds distributable profit in approved report (%d).', $amount, $distributable));
                }
                $id = DB::table('islamic_partnership_profit_declarations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'islamic_partnership_report_id' => (int) $report->id,
                    'period_code' => (string) $validated['period_code'],
                    'amount_minor' => $amount,
                    'declared_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_profit_declarations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Declaration could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership_profit_declaration' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.profit_declared', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
            'declaration_public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
        ], request: $request);

        return $this->respondCreated($this->declarationPayload($row), 'Profit declared');
    }

    public function storeLoss(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'loss_type' => ['required', 'string', 'in:ordinary,misconduct'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'blocks_distribution' => ['sometimes', 'boolean'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                $id = DB::table('islamic_partnership_losses')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'loss_type' => (string) $validated['loss_type'],
                    'amount_minor' => (int) $validated['amount_minor'],
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'description' => is_string($validated['description'] ?? null) ? (string) $validated['description'] : null,
                    'blocks_distribution' => (bool) ($validated['blocks_distribution'] ?? ((string) $validated['loss_type'] === 'misconduct')),
                    'recorded_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_losses')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Loss could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership_loss' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.loss_recorded', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
            'loss_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->lossPayload($row), 'Loss/misconduct recorded');
    }

    public function storeValuation(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'valuation_method' => ['required', 'string', 'max:64'],
            'valuation_amount_minor' => ['required', 'integer', 'min:1'],
            'valuation_date' => ['required', 'date'],
            'validity_until' => ['required', 'date', 'after_or_equal:valuation_date'],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                $id = DB::table('islamic_partnership_valuations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'valuation_method' => (string) $validated['valuation_method'],
                    'valuation_amount_minor' => (int) $validated['valuation_amount_minor'],
                    'valuation_date' => (string) $validated['valuation_date'],
                    'validity_until' => (string) $validated['validity_until'],
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'approval_status' => 'approved',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_valuations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Valuation could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership_valuation' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.valuation.approved', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
            'valuation_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->valuationPayload($row), 'Valuation recorded');
    }

    public function storeBuyout(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'partner_public_id' => ['required', 'string'],
            'valuation_public_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                if ($this->rowString($partnership, 'status') !== self::STATUS_ACTIVE) {
                    throw new InvalidArgumentException('Buyouts only allowed for active partnerships.');
                }
                $partner = DB::table('islamic_partnership_partners')->where('public_id', (string) $validated['partner_public_id'])->first();
                if (! is_object($partner) || (int) $partner->islamic_partnership_id !== $this->rowInt($partnership, 'id')) {
                    throw new InvalidArgumentException('Partner does not belong to this partnership.');
                }
                $valuation = DB::table('islamic_partnership_valuations')->where('public_id', (string) $validated['valuation_public_id'])->first();
                if (! is_object($valuation) || (int) $valuation->islamic_partnership_id !== $this->rowInt($partnership, 'id')) {
                    throw new InvalidArgumentException('Valuation does not belong to this partnership.');
                }
                if ((string) $valuation->approval_status !== 'approved') {
                    throw new InvalidArgumentException('Buyout requires an approved valuation (IF-043 buyout gate).');
                }
                $today = now()->toDateString();
                if (is_string($valuation->validity_until ?? null) && $valuation->validity_until !== '' && $today > (string) $valuation->validity_until) {
                    throw new InvalidArgumentException('Buyout valuation is expired; refresh valuation before proceeding.');
                }
                $latestApprovedValuation = DB::table('islamic_partnership_valuations')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->where('approval_status', 'approved')
                    ->orderByDesc('valuation_date')
                    ->first(['id']);
                if (! is_object($latestApprovedValuation) || (int) $latestApprovedValuation->id !== (int) $valuation->id) {
                    throw new InvalidArgumentException('Buyout must reference the most recent approved valuation.');
                }
                $idempotency = (string) $validated['idempotency_key'];
                if (DB::table('islamic_partnership_buyouts')->where('idempotency_key', $idempotency)->exists()) {
                    throw new InvalidArgumentException('Buyout with idempotency_key already executed.');
                }
                $maxAmount = (int) round((float) $partner->profit_share_ratio * (int) $valuation->valuation_amount_minor);
                if ((int) $validated['amount_minor'] > $maxAmount) {
                    throw new InvalidArgumentException(sprintf('Buyout amount (%d) exceeds partner share of valuation (%d).', (int) $validated['amount_minor'], $maxAmount));
                }
                $id = DB::table('islamic_partnership_buyouts')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_partnership_id' => $this->rowInt($partnership, 'id'),
                    'islamic_partnership_partner_id' => (int) $partner->id,
                    'islamic_partnership_valuation_id' => (int) $valuation->id,
                    'amount_minor' => (int) $validated['amount_minor'],
                    'idempotency_key' => $idempotency,
                    'executed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnership_buyouts')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Buyout could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            $this->securityAudit->record('islamic.partnership.buyout.blocked', actor: $actor, properties: [
                'partnership_public_id' => $partnershipPublicId,
                'reason' => $exception->getMessage(),
            ], request: $request);

            return $this->respondUnprocessable(errors: ['islamic_partnership_buyout' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.partnership.buyout.executed', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
            'buyout_public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
        ], request: $request);

        return $this->respondCreated($this->buyoutPayload($row), 'Buyout executed');
    }

    public function liquidatePartnership(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'valuation_public_id' => ['required', 'string'],
            'settlement_plan_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'liquidation_evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($partnershipPublicId, $validated): object {
                $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->lockForUpdate()->first();
                if (! is_object($partnership)) {
                    throw new InvalidArgumentException('Partnership is invalid.');
                }
                if ($this->rowString($partnership, 'status') !== self::STATUS_ACTIVE) {
                    throw new InvalidArgumentException('Only active partnerships can be liquidated.');
                }
                $valuation = DB::table('islamic_partnership_valuations')->where('public_id', (string) $validated['valuation_public_id'])->first();
                if (! is_object($valuation) || (int) $valuation->islamic_partnership_id !== $this->rowInt($partnership, 'id')) {
                    throw new InvalidArgumentException('Liquidation valuation does not belong to this partnership.');
                }
                if ((string) $valuation->approval_status !== 'approved') {
                    throw new InvalidArgumentException('Liquidation requires an approved valuation (IF-043 liquidation gate).');
                }
                $latestApprovedValuation = DB::table('islamic_partnership_valuations')
                    ->where('islamic_partnership_id', $this->rowInt($partnership, 'id'))
                    ->where('approval_status', 'approved')
                    ->orderByDesc('valuation_date')
                    ->first(['id']);
                if (! is_object($latestApprovedValuation) || (int) $latestApprovedValuation->id !== (int) $valuation->id) {
                    throw new InvalidArgumentException('Liquidation must reference the most recent approved valuation.');
                }

                $metadata = is_string($partnership->metadata ?? null) && $partnership->metadata !== '' ? (string) $partnership->metadata : '{}';
                $decoded = json_decode($metadata, true);
                $decoded = is_array($decoded) ? $decoded : [];
                $decoded['liquidation'] = [
                    'valuation_public_id' => (string) $validated['valuation_public_id'],
                    'settlement_plan_document_public_id' => (string) $validated['settlement_plan_document_public_id'],
                    'liquidation_evidence_document_public_id' => (string) $validated['liquidation_evidence_document_public_id'],
                    'comments' => is_string($validated['comments'] ?? null) ? (string) $validated['comments'] : null,
                    'liquidated_at' => now()->toIso8601String(),
                ];

                DB::table('islamic_partnerships')->where('id', $this->rowInt($partnership, 'id'))->update([
                    'status' => self::STATUS_LIQUIDATED,
                    'metadata' => json_encode($decoded, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_partnerships')->where('id', $this->rowInt($partnership, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Partnership could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_partnership' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.partnership.liquidated', actor: $actor, properties: [
            'partnership_public_id' => $partnershipPublicId,
            'valuation_public_id' => (string) $validated['valuation_public_id'],
        ], request: $request);

        return $this->respondSuccess($this->partnershipPayload($row), 'Partnership liquidated');
    }

    public function showTimeline(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $partnership = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->first();
        if (! is_object($partnership)) {
            return $this->respondNotFound('Partnership not found.');
        }
        $partnershipId = $this->rowInt($partnership, 'id');

        $partners = DB::table('islamic_partnership_partners')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'partner_role', 'partner_reference', 'created_at']);
        $contributions = DB::table('islamic_partnership_contributions')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'amount_minor', 'contributed_on', 'created_at']);
        $reports = DB::table('islamic_partnership_reports')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'period_code', 'distributable_profit_minor', 'approval_status', 'created_at']);
        $declarations = DB::table('islamic_partnership_profit_declarations')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'period_code', 'amount_minor', 'created_at']);
        $losses = DB::table('islamic_partnership_losses')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'loss_type', 'amount_minor', 'blocks_distribution', 'created_at']);
        $valuations = DB::table('islamic_partnership_valuations')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'valuation_method', 'valuation_amount_minor', 'approval_status', 'created_at']);
        $buyouts = DB::table('islamic_partnership_buyouts')
            ->where('islamic_partnership_id', $partnershipId)
            ->orderBy('id')
            ->get(['public_id', 'amount_minor', 'executed_at', 'created_at']);

        return $this->respondSuccess([
            'partnership_public_id' => $partnershipPublicId,
            'status' => $this->rowString($partnership, 'status'),
            'partners' => $partners->map(fn (object $row): array => (array) $row)->all(),
            'contributions' => $contributions->map(fn (object $row): array => (array) $row)->all(),
            'reports' => $reports->map(fn (object $row): array => (array) $row)->all(),
            'profit_declarations' => $declarations->map(fn (object $row): array => (array) $row)->all(),
            'losses' => $losses->map(fn (object $row): array => (array) $row)->all(),
            'valuations' => $valuations->map(fn (object $row): array => (array) $row)->all(),
            'buyouts' => $buyouts->map(fn (object $row): array => (array) $row)->all(),
        ]);
    }

    public function showPartnership(Request $request, string $partnershipPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_partnerships')->where('public_id', $partnershipPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Partnership not found.');
        }

        return $this->respondSuccess($this->partnershipPayload($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function partnershipPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'partnership_type' => $this->rowString($row, 'partnership_type'),
            'reporting_cadence' => $this->rowString($row, 'reporting_cadence'),
            'status' => $this->rowString($row, 'status'),
            'expected_total_capital_minor' => $this->rowInt($row, 'expected_total_capital_minor'),
            'contributed_total_capital_minor' => $this->rowInt($row, 'contributed_total_capital_minor'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'partner_role' => $this->rowString($row, 'partner_role'),
            'partner_reference' => $this->rowString($row, 'partner_reference'),
            'profit_share_ratio' => (float) ((array) $row)['profit_share_ratio'],
            'expected_contribution_minor' => $this->rowInt($row, 'expected_contribution_minor'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contributionPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'contributed_on' => $this->rowNullableString($row, 'contributed_on'),
            'evidence_document_public_id' => $this->rowString($row, 'evidence_document_public_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'period_code' => $this->rowString($row, 'period_code'),
            'distributable_profit_minor' => $this->rowInt($row, 'distributable_profit_minor'),
            'approval_status' => $this->rowString($row, 'approval_status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function declarationPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'period_code' => $this->rowString($row, 'period_code'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lossPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'loss_type' => $this->rowString($row, 'loss_type'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'blocks_distribution' => (bool) (((array) $row)['blocks_distribution'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuationPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'valuation_method' => $this->rowString($row, 'valuation_method'),
            'valuation_amount_minor' => $this->rowInt($row, 'valuation_amount_minor'),
            'valuation_date' => $this->rowNullableString($row, 'valuation_date'),
            'validity_until' => $this->rowNullableString($row, 'validity_until'),
            'approval_status' => $this->rowString($row, 'approval_status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyoutPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'idempotency_key' => $this->rowString($row, 'idempotency_key'),
            'executed_at' => $this->rowNullableString($row, 'executed_at'),
        ];
    }

    private function rowString(object $row, string $field): string
    {
        $value = ((array) $row)[$field] ?? null;

        return is_string($value) ? $value : '';
    }

    private function rowNullableString(object $row, string $field): ?string
    {
        $value = ((array) $row)[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    private function rowInt(object $row, string $field): int
    {
        $value = ((array) $row)[$field] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
