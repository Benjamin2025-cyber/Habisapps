<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IslamicSalamGoodsWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicScreeningPolicyService $screening,
        private readonly IslamicInterestGuardPolicy $interestGuard,
        private readonly IslamicMappingValidationService $mappingValidation,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    public function storeGoods(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'islamic_financing_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_financings,public_id'],
            'goods_category' => ['required', 'string', 'max:64'],
            'quality_spec' => ['required', 'string', 'max:1000'],
            'quantity_units' => ['required', 'integer', 'min:1'],
            'quantity_unit' => ['required', 'string', 'max:32'],
            'delivery_date' => ['required', 'date'],
            'delivery_place' => ['required', 'string', 'max:255'],
            'counterparty_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'inspection_requirements' => ['sometimes', 'nullable', 'array'],
            'acceptance_rules' => ['sometimes', 'nullable', 'array'],
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
                    $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first(['id', 'status']);
                    if (! is_object($financing) || ! is_numeric($financing->id)) {
                        throw new InvalidArgumentException('Linked Islamic financing is invalid.');
                    }
                    if (is_string($financing->status ?? null) && $financing->status !== 'draft') {
                        throw new InvalidArgumentException('Salam goods can only be linked to draft financings.');
                    }
                    $financingId = (int) $financing->id;
                }

                $id = DB::table('islamic_salam_goods')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $financingId,
                    'goods_category' => (string) $validated['goods_category'],
                    'quality_spec' => (string) $validated['quality_spec'],
                    'quantity_units' => (int) $validated['quantity_units'],
                    'quantity_unit' => (string) $validated['quantity_unit'],
                    'delivery_date' => (string) $validated['delivery_date'],
                    'delivery_place' => (string) $validated['delivery_place'],
                    'counterparty_reference' => is_string($validated['counterparty_reference'] ?? null) && $validated['counterparty_reference'] !== '' ? $validated['counterparty_reference'] : null,
                    'inspection_requirements' => isset($validated['inspection_requirements']) && is_array($validated['inspection_requirements']) ? json_encode($validated['inspection_requirements'], JSON_THROW_ON_ERROR) : null,
                    'acceptance_rules' => isset($validated['acceptance_rules']) && is_array($validated['acceptance_rules']) ? json_encode($validated['acceptance_rules'], JSON_THROW_ON_ERROR) : null,
                    'status' => IslamicSalamGoodsStateMachine::STATUS_SPECIFIED,
                    'delivered_units' => 0,
                    'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_salam_goods')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Salam goods could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_salam_goods' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.salam_goods.created', actor: $actor, properties: [
            'goods_public_id' => $this->rowString($row, 'public_id'),
            'goods_category' => $this->rowString($row, 'goods_category'),
            'quantity_units' => $this->rowInt($row, 'quantity_units'),
            'quantity_unit' => $this->rowString($row, 'quantity_unit'),
        ], request: $request);

        return $this->respondCreated($this->goodsPayload($row), 'Salam goods registered');
    }

    public function showGoods(Request $request, string $goodsPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_salam_goods')->where('public_id', $goodsPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Salam goods not found.');
        }

        return $this->respondSuccess($this->goodsPayload($row));
    }

    public function showTimeline(Request $request, string $goodsPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_salam_goods')->where('public_id', $goodsPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Salam goods not found.');
        }
        $events = $this->salamGoodsTimelineEvents($this->rowInt($row, 'id'));
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = mb_strtolower(trim($search));
            $events = array_values(array_filter($events, static function (array $event) use ($term): bool {
                $haystack = mb_strtolower(json_encode($event, JSON_THROW_ON_ERROR));

                return str_contains($haystack, $term);
            }));
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = count($events);
        $slice = array_slice($events, ($page - 1) * $perPage, $perPage);

        return $this->respondSuccess([
            'goods_public_id' => $goodsPublicId,
            'current_status' => $this->rowString($row, 'status'),
            'timeline_events' => $slice,
        ], 'Salam goods timeline retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    public function storeUpfrontPayment(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reference_goods_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_salam_goods,public_id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($financingPublicId, $validated, $actor, $request): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if ($family !== 'salam') {
                    throw new InvalidArgumentException('Salam upfront payment endpoint is only available for Salam financings.');
                }
                if ($this->rowString($financing, 'status') !== 'approved') {
                    $this->securityAudit->record('islamic.salam.upfront_payment_rejected_pre_approval', actor: $actor, properties: [
                        'financing_public_id' => $financingPublicId,
                        'status' => $this->rowString($financing, 'status'),
                    ], request: $request);
                    throw new InvalidArgumentException('Salam upfront payment can only be posted after financing approval.');
                }

                $idempotencyKey = (string) $validated['idempotency_key'];
                if (DB::table('islamic_salam_upfront_payments')->where('idempotency_key', $idempotencyKey)->exists()) {
                    throw new InvalidArgumentException('Salam upfront payment idempotency_key already posted.');
                }

                $this->assertGoodsReadyForApproval($this->rowInt($financing, 'id'));

                $referenceGoodsId = null;
                $referenceGoodsPublicId = is_string($validated['reference_goods_public_id'] ?? null) ? $validated['reference_goods_public_id'] : null;
                if ($referenceGoodsPublicId !== null && $referenceGoodsPublicId !== '') {
                    $goods = DB::table('islamic_salam_goods')->where('public_id', $referenceGoodsPublicId)->lockForUpdate()->first(['id', 'islamic_financing_id']);
                    if (! is_object($goods) || $this->rowInt($goods, 'islamic_financing_id') !== $this->rowInt($financing, 'id')) {
                        throw new InvalidArgumentException('Reference Salam goods must belong to the target financing.');
                    }
                    $referenceGoodsId = $this->rowInt($goods, 'id');
                }

                $product = DB::table('islamic_products')
                    ->where('id', $this->rowInt($financing, 'islamic_product_id'))
                    ->lockForUpdate()
                    ->first(['rules']);
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Linked Islamic product is invalid.');
                }
                $rules = $this->decodeJsonArray($product, 'rules') ?? [];
                $upfrontPaymentMapping = is_array($rules['upfront_payment_mapping'] ?? null) ? $rules['upfront_payment_mapping'] : [];
                $operationCode = is_string($upfrontPaymentMapping['operation_code'] ?? null) && trim($upfrontPaymentMapping['operation_code']) !== ''
                    ? trim($upfrontPaymentMapping['operation_code'])
                    : 'salam_upfront_payment';

                $this->interestGuard->assertIslamicMappingAllowed($operationCode);
                $agencyId = $this->rowInt($financing, 'agency_id');
                $currency = $this->rowString($financing, 'currency');
                $debitMapping = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, [
                    'side' => 'debit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $creditMapping = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, [
                    'side' => 'credit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $debitLedger = $debitMapping['debit_ledger_account_id'];
                $creditLedger = $creditMapping['credit_ledger_account_id'];
                if (! is_int($debitLedger) || ! is_int($creditLedger)) {
                    throw new InvalidArgumentException('Approved Salam upfront mapping with both debit and credit ledgers is required.');
                }

                $amount = (int) $validated['amount_minor'];
                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'islamic.salam', $agencyId);
                $businessDate = $accountingDay->business_date->toDateString();
                $journal = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'SAL-UPF-'.Str::upper(Str::random(10)),
                    'business_date' => $businessDate,
                    'accounting_day_id' => $accountingDay->id,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'islamic_finance',
                    'source_type' => 'salam_upfront_payment',
                    'source_public_id' => $financingPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Salam upfront payment '.$financingPublicId,
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => 'salam-upfront:'.$idempotencyKey,
                ]);
                $journal->lines()->createMany([
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $debitLedger,
                        'debit_minor' => $amount,
                        'credit_minor' => 0,
                        'currency' => $currency,
                        'line_memo' => 'Salam upfront payment debit',
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $creditLedger,
                        'debit_minor' => 0,
                        'credit_minor' => $amount,
                        'currency' => $currency,
                        'line_memo' => 'Salam upfront payment credit',
                    ],
                ]);
                $this->postSystemJournal($journal, $actor);

                $id = DB::table('islamic_salam_upfront_payments')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'islamic_salam_goods_id' => $referenceGoodsId,
                    'operation_code' => $operationCode,
                    'mapping_public_id' => $creditMapping['mapping_public_id'],
                    'journal_entry_id' => $journal->id,
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'status' => 'posted',
                    'idempotency_key' => $idempotencyKey,
                    'event_payload' => json_encode([
                        'reference_goods_public_id' => $referenceGoodsPublicId,
                        'notes' => is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
                        'debit_mapping_public_id' => $debitMapping['mapping_public_id'],
                        'credit_mapping_public_id' => $creditMapping['mapping_public_id'],
                    ], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('islamic_financings')->where('id', $this->rowInt($financing, 'id'))->update([
                    'status' => 'paid',
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_salam_upfront_payments')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Salam upfront payment could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_salam_upfront_payment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.salam.upfront_payment_posted', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'upfront_payment_public_id' => $this->rowString($row, 'public_id'),
            'operation_code' => $this->rowString($row, 'operation_code'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
        ], request: $request);

        return $this->respondCreated($this->upfrontPaymentPayload($row), 'Salam upfront payment posted');
    }

    public function storeDelivery(Request $request, string $goodsPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'delivered_units' => ['required', 'integer', 'min:1'],
            'delivered_on' => ['required', 'date'],
            'delivery_evidence' => ['required', 'string', 'exists:documents,public_id'],
            'inventory_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'settlement_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        $inventoryRef = is_string($validated['inventory_reference'] ?? null) && $validated['inventory_reference'] !== '' ? $validated['inventory_reference'] : null;
        $settlementRef = is_string($validated['settlement_reference'] ?? null) && $validated['settlement_reference'] !== '' ? $validated['settlement_reference'] : null;
        if ($inventoryRef === null && $settlementRef === null) {
            return $this->respondUnprocessable(errors: ['islamic_salam_goods_delivery' => ['Delivery requires inventory_reference or settlement_reference (IF-041 Phase 4).']]);
        }

        try {
            $result = DB::transaction(function () use ($goodsPublicId, $validated, $inventoryRef, $settlementRef, $actor): array {
                $goods = DB::table('islamic_salam_goods')->where('public_id', $goodsPublicId)->lockForUpdate()->first();
                if (! is_object($goods)) {
                    throw new InvalidArgumentException('Salam goods are invalid.');
                }
                $currentStatus = $this->rowString($goods, 'status');
                if (IslamicSalamGoodsStateMachine::isTerminal($currentStatus)) {
                    throw new InvalidArgumentException(sprintf('Salam goods are in terminal status "%s" and cannot accept new deliveries.', $currentStatus));
                }

                $totalQty = $this->rowInt($goods, 'quantity_units');
                $deliveredQty = $this->rowInt($goods, 'delivered_units');
                $newQty = (int) $validated['delivered_units'];
                $finalDelivered = $deliveredQty + $newQty;
                if ($finalDelivered > $totalQty) {
                    throw new InvalidArgumentException('Delivered quantity exceeds specified quantity.');
                }

                $deliveryEvidencePublicId = (string) $validated['delivery_evidence'];

                // Screening at acceptance: every delivery is an acceptance event.
                $screeningOutcome = $this->screening->evaluate(
                    subjectType: 'islamic_salam_goods',
                    subjectPublicId: $goodsPublicId,
                    contextType: 'goods_acceptance',
                    facts: [
                        'scope_type' => 'product_family',
                        'scope_value' => 'salam',
                        'goods_public_id' => $goodsPublicId,
                        'counterparty_reference' => $this->rowNullableString($goods, 'counterparty_reference'),
                        'goods_codes' => [strtolower($this->rowString($goods, 'goods_category'))],
                    ],
                    actor: $actor,
                    strictPolicy: false,
                );
                $screeningResultPublicId = is_string($screeningOutcome['public_id'] ?? null) && $screeningOutcome['public_id'] !== '' ? $screeningOutcome['public_id'] : null;
                $complianceCasePublicId = is_string($screeningOutcome['review_case_public_id'] ?? null) && $screeningOutcome['review_case_public_id'] !== '' ? $screeningOutcome['review_case_public_id'] : null;
                $resultStatus = is_string($screeningOutcome['result'] ?? null) ? $screeningOutcome['result'] : 'not_applicable';
                if ($resultStatus === 'fail') {
                    throw new InvalidArgumentException('Salam goods delivery blocked by screening result.');
                }
                if ($resultStatus === 'manual_review') {
                    throw new InvalidArgumentException('Salam goods delivery requires manual compliance review.');
                }

                $deliveryId = DB::table('islamic_salam_goods_deliveries')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_salam_goods_id' => $this->rowInt($goods, 'id'),
                    'delivered_units' => $newQty,
                    'delivered_on' => (string) $validated['delivered_on'],
                    'delivery_evidence' => $deliveryEvidencePublicId,
                    'inventory_reference' => $inventoryRef,
                    'settlement_reference' => $settlementRef,
                    'notes' => is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
                    'actor_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $toStatus = $finalDelivered === $totalQty
                    ? IslamicSalamGoodsStateMachine::STATUS_DELIVERED
                    : IslamicSalamGoodsStateMachine::STATUS_PARTIALLY_DELIVERED;

                IslamicSalamGoodsStateMachine::assertTransitionAllowed($currentStatus, $toStatus);

                $transitionPublicId = (string) Str::ulid();
                DB::table('islamic_salam_goods_transitions')->insert([
                    'public_id' => $transitionPublicId,
                    'islamic_salam_goods_id' => $this->rowInt($goods, 'id'),
                    'from_status' => $currentStatus,
                    'to_status' => $toStatus,
                    'reason_code' => 'delivery_recorded',
                    'reason_note' => null,
                    'screening_result_public_id' => $screeningResultPublicId,
                    'compliance_case_public_id' => $complianceCasePublicId,
                    'evidence_refs' => json_encode([
                        'delivery_evidence' => $deliveryEvidencePublicId,
                        'inventory_reference' => $inventoryRef,
                        'settlement_reference' => $settlementRef,
                    ], JSON_THROW_ON_ERROR),
                    'context_snapshot' => json_encode([
                        'delivered_units_in_event' => $newQty,
                        'delivered_units_after' => $finalDelivered,
                        'quantity_units' => $totalQty,
                    ], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'transitioned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $goodsUpdate = [
                    'status' => $toStatus,
                    'delivered_units' => $finalDelivered,
                    'updated_at' => now(),
                ];
                if ($screeningResultPublicId !== null) {
                    $goodsUpdate['screening_result_public_id'] = $screeningResultPublicId;
                }
                if ($inventoryRef !== null) {
                    $goodsUpdate['inventory_reference'] = $inventoryRef;
                }
                if ($settlementRef !== null) {
                    $goodsUpdate['settlement_reference'] = $settlementRef;
                }
                DB::table('islamic_salam_goods')->where('id', $this->rowInt($goods, 'id'))->update($goodsUpdate);
                $deliveryRow = DB::table('islamic_salam_goods_deliveries')->where('id', $deliveryId)->first();
                if (! is_object($deliveryRow)) {
                    throw new InvalidArgumentException('Delivery could not be reloaded.');
                }
                $this->upsertSettlementState(
                    $this->rowInt($goods, 'id'),
                    $toStatus,
                    $totalQty,
                    $finalDelivered,
                    $this->rowString($deliveryRow, 'public_id'),
                    $transitionPublicId,
                );

                $row = DB::table('islamic_salam_goods')->where('id', $this->rowInt($goods, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Salam goods could not be reloaded.');
                }

                return ['goods' => $row, 'delivery' => $deliveryRow, 'to_status' => $toStatus, 'transition_public_id' => $transitionPublicId];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_salam_goods_delivery' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.salam_goods.delivery_recorded', actor: $actor, properties: [
            'goods_public_id' => $goodsPublicId,
            'delivery_public_id' => $this->rowString($result['delivery'], 'public_id'),
            'delivered_units' => $this->rowInt($result['delivery'], 'delivered_units'),
            'new_status' => $result['to_status'],
        ], request: $request);
        $this->securityAudit->record('islamic.salam_goods.transitioned', actor: $actor, properties: [
            'goods_public_id' => $goodsPublicId,
            'to_status' => $result['to_status'],
            'transition_public_id' => $result['transition_public_id'],
        ], request: $request);
        if ($result['to_status'] === IslamicSalamGoodsStateMachine::STATUS_PARTIALLY_DELIVERED) {
            $this->securityAudit->record('islamic.salam.partial_delivery_settlement_opened', actor: $actor, properties: [
                'goods_public_id' => $goodsPublicId,
                'transition_public_id' => $result['transition_public_id'],
            ], request: $request);
        }

        return $this->respondSuccess([
            'goods' => $this->goodsPayload($result['goods']),
            'delivery' => $this->deliveryPayload($result['delivery']),
        ], 'Salam goods delivery recorded');
    }

    public function transitionGoods(Request $request, string $goodsPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'to_status' => ['required', 'string', 'in:'.implode(',', IslamicSalamGoodsStateMachine::STATUSES)],
            'evidence' => ['sometimes', 'array'],
            'reason_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reason_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        $toStatus = (string) $validated['to_status'];
        $evidence = is_array($validated['evidence'] ?? null) ? $validated['evidence'] : [];

        try {
            $result = DB::transaction(function () use ($goodsPublicId, $toStatus, $evidence, $validated, $actor): array {
                $goods = DB::table('islamic_salam_goods')->where('public_id', $goodsPublicId)->lockForUpdate()->first();
                if (! is_object($goods)) {
                    throw new InvalidArgumentException('Salam goods are invalid.');
                }
                $fromStatus = $this->rowString($goods, 'status');
                IslamicSalamGoodsStateMachine::assertTransitionAllowed($fromStatus, $toStatus);
                if (
                    $toStatus === IslamicSalamGoodsStateMachine::STATUS_PARTIALLY_DELIVERED
                    || $toStatus === IslamicSalamGoodsStateMachine::STATUS_DELIVERED
                ) {
                    throw new InvalidArgumentException('Use the deliveries endpoint to move Salam goods into delivery states.');
                }
                IslamicSalamGoodsStateMachine::assertEvidenceComplete($toStatus, $evidence);
                $this->assertEvidenceDocumentsExist($evidence);

                $screeningResultPublicId = null;
                $complianceCasePublicId = null;
                if (IslamicSalamGoodsStateMachine::requiresAcceptanceScreening($toStatus)) {
                    $screeningOutcome = $this->screening->evaluate(
                        subjectType: 'islamic_salam_goods',
                        subjectPublicId: $goodsPublicId,
                        contextType: 'goods_acceptance',
                        facts: [
                            'scope_type' => 'product_family',
                            'scope_value' => 'salam',
                            'goods_public_id' => $goodsPublicId,
                            'counterparty_reference' => $this->rowNullableString($goods, 'counterparty_reference'),
                            'goods_codes' => [strtolower($this->rowString($goods, 'goods_category'))],
                        ],
                        actor: $actor,
                        strictPolicy: false,
                    );
                    $screeningResultPublicId = is_string($screeningOutcome['public_id'] ?? null) && $screeningOutcome['public_id'] !== '' ? $screeningOutcome['public_id'] : null;
                    $complianceCasePublicId = is_string($screeningOutcome['review_case_public_id'] ?? null) && $screeningOutcome['review_case_public_id'] !== '' ? $screeningOutcome['review_case_public_id'] : null;
                    $resultStatus = is_string($screeningOutcome['result'] ?? null) ? $screeningOutcome['result'] : 'not_applicable';
                    if ($resultStatus === 'fail') {
                        throw new InvalidArgumentException('Salam goods acceptance transition blocked by screening result.');
                    }
                    if ($resultStatus === 'manual_review') {
                        throw new InvalidArgumentException('Salam goods acceptance transition requires manual compliance review.');
                    }
                }

                $transitionPublicId = (string) Str::ulid();
                DB::table('islamic_salam_goods_transitions')->insert([
                    'public_id' => $transitionPublicId,
                    'islamic_salam_goods_id' => $this->rowInt($goods, 'id'),
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'reason_code' => is_string($validated['reason_code'] ?? null) && $validated['reason_code'] !== '' ? $validated['reason_code'] : null,
                    'reason_note' => is_string($validated['reason_note'] ?? null) && $validated['reason_note'] !== '' ? $validated['reason_note'] : null,
                    'screening_result_public_id' => $screeningResultPublicId,
                    'compliance_case_public_id' => $complianceCasePublicId,
                    'evidence_refs' => json_encode($evidence, JSON_THROW_ON_ERROR),
                    'context_snapshot' => null,
                    'actor_user_id' => $actor->id,
                    'transitioned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $goodsUpdate = ['status' => $toStatus, 'updated_at' => now()];
                if ($screeningResultPublicId !== null) {
                    $goodsUpdate['screening_result_public_id'] = $screeningResultPublicId;
                }
                if ($toStatus === IslamicSalamGoodsStateMachine::STATUS_SETTLED && isset($evidence['settlement_reference']) && is_string($evidence['settlement_reference'])) {
                    $goodsUpdate['settlement_reference'] = $evidence['settlement_reference'];
                }
                DB::table('islamic_salam_goods')->where('id', $this->rowInt($goods, 'id'))->update($goodsUpdate);
                $this->upsertSettlementState(
                    $this->rowInt($goods, 'id'),
                    $toStatus,
                    $this->rowInt($goods, 'quantity_units'),
                    $this->rowInt($goods, 'delivered_units'),
                    null,
                    $transitionPublicId,
                );

                $row = DB::table('islamic_salam_goods')->where('id', $this->rowInt($goods, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Salam goods could not be reloaded after transition.');
                }
                $transition = DB::table('islamic_salam_goods_transitions')->where('public_id', $transitionPublicId)->first();
                if (! is_object($transition)) {
                    throw new InvalidArgumentException('Transition record could not be reloaded.');
                }

                return ['goods' => $row, 'transition' => $transition];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_salam_goods' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.salam_goods.transitioned', actor: $actor, properties: [
            'goods_public_id' => $goodsPublicId,
            'from_status' => $this->rowNullableString($result['transition'], 'from_status'),
            'to_status' => $this->rowString($result['transition'], 'to_status'),
            'transition_public_id' => $this->rowString($result['transition'], 'public_id'),
        ], request: $request);
        if ($this->rowString($result['transition'], 'to_status') === IslamicSalamGoodsStateMachine::STATUS_IN_DISPUTE) {
            $this->securityAudit->record('islamic.salam.dispute_opened', actor: $actor, properties: [
                'goods_public_id' => $goodsPublicId,
                'transition_public_id' => $this->rowString($result['transition'], 'public_id'),
            ], request: $request);
        }

        return $this->respondSuccess([
            'goods' => $this->goodsPayload($result['goods']),
            'transition' => $this->transitionPayload($result['transition']),
        ], 'Salam goods transitioned');
    }

    /**
     * Used by the future Salam financing approval workflow to verify all linked Salam goods specs are complete.
     */
    public function assertGoodsReadyForApproval(int $financingId): void
    {
        $rows = DB::table('islamic_salam_goods')
            ->where('islamic_financing_id', $financingId)
            ->lockForUpdate()
            ->get();
        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('Salam financing requires at least one specified goods record (IF-041 activation gate).');
        }
        foreach ($rows as $row) {
            $status = $this->rowString($row, 'status');
            if (in_array($status, [
                IslamicSalamGoodsStateMachine::STATUS_NON_DELIVERY,
                IslamicSalamGoodsStateMachine::STATUS_REJECTED,
                IslamicSalamGoodsStateMachine::STATUS_SETTLED,
                IslamicSalamGoodsStateMachine::STATUS_CANCELLED,
            ], true)) {
                throw new InvalidArgumentException(sprintf(
                    'Salam financing approval cannot proceed with goods in "%s" status (IF-041 activation gate).',
                    $status
                ));
            }
            $quantity = is_numeric($row->quantity_units ?? null) ? (int) $row->quantity_units : 0;
            if ($quantity <= 0) {
                throw new InvalidArgumentException('Salam goods specification requires a positive quantity (IF-041 activation gate).');
            }
            if (! is_string($row->quantity_unit ?? null) || $row->quantity_unit === '') {
                throw new InvalidArgumentException('Salam goods specification requires a quantity unit (IF-041 activation gate).');
            }
            if (! is_string($row->delivery_date ?? null) || $row->delivery_date === '') {
                throw new InvalidArgumentException('Salam goods specification requires a delivery date (IF-041 activation gate).');
            }
            if (! is_string($row->delivery_place ?? null) || $row->delivery_place === '') {
                throw new InvalidArgumentException('Salam goods specification requires a delivery place (IF-041 activation gate).');
            }
            if (! is_string($row->quality_spec ?? null) || $row->quality_spec === '') {
                throw new InvalidArgumentException('Salam goods specification requires a quality spec (IF-041 activation gate).');
            }
            if ($this->isVagueQualitySpec($row->quality_spec)) {
                throw new InvalidArgumentException('Salam goods specification contains vague quality terms and cannot be approved (IF-081 goods precision gate).');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function assertEvidenceDocumentsExist(array $evidence): void
    {
        foreach (IslamicSalamGoodsStateMachine::documentBackedEvidenceKeys() as $key) {
            if (! array_key_exists($key, $evidence)) {
                continue;
            }
            $value = $evidence[$key];
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('Salam goods transition evidence "%s" must be a non-empty document public_id.', $key));
            }
            $exists = DB::table('documents')->where('public_id', $value)->exists();
            if (! $exists) {
                throw new InvalidArgumentException(sprintf('Salam goods transition evidence "%s" references unknown document "%s".', $key, $value));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function goodsPayload(object $row): array
    {
        $inspectionRequirements = $this->decodeJsonArray($row, 'inspection_requirements');
        $acceptanceRules = $this->decodeJsonArray($row, 'acceptance_rules');

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'goods_category' => $this->rowString($row, 'goods_category'),
            'quality_spec' => $this->rowString($row, 'quality_spec'),
            'quantity_units' => $this->rowInt($row, 'quantity_units'),
            'quantity_unit' => $this->rowString($row, 'quantity_unit'),
            'delivery_date' => $this->rowNullableString($row, 'delivery_date'),
            'delivery_place' => $this->rowString($row, 'delivery_place'),
            'counterparty_reference' => $this->rowNullableString($row, 'counterparty_reference'),
            'inspection_requirements' => $inspectionRequirements,
            'acceptance_rules' => $acceptanceRules,
            'status' => $this->rowString($row, 'status'),
            'delivered_units' => $this->rowInt($row, 'delivered_units'),
            'inventory_reference' => $this->rowNullableString($row, 'inventory_reference'),
            'settlement_reference' => $this->rowNullableString($row, 'settlement_reference'),
            'screening_result_public_id' => $this->rowNullableString($row, 'screening_result_public_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'delivered_units' => $this->rowInt($row, 'delivered_units'),
            'delivered_on' => $this->rowNullableString($row, 'delivered_on'),
            'delivery_evidence' => $this->rowString($row, 'delivery_evidence'),
            'inventory_reference' => $this->rowNullableString($row, 'inventory_reference'),
            'settlement_reference' => $this->rowNullableString($row, 'settlement_reference'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function upfrontPaymentPayload(object $row): array
    {
        $snapshot = $this->decodeJsonArray($row, 'event_payload');

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'operation_code' => $this->rowString($row, 'operation_code'),
            'mapping_public_id' => $this->rowString($row, 'mapping_public_id'),
            'journal_entry_public_id' => $this->tablePublicIdById('journal_entries', $this->rowNullableInt($row, 'journal_entry_id')),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'currency' => $this->rowString($row, 'currency'),
            'status' => $this->rowString($row, 'status'),
            'idempotency_key' => $this->rowString($row, 'idempotency_key'),
            'posted_at' => $this->rowNullableString($row, 'posted_at'),
            'event_payload' => $snapshot,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionPayload(object $row): array
    {
        $evidence = $this->decodeJsonArray($row, 'evidence_refs');

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'from_status' => $this->rowNullableString($row, 'from_status'),
            'to_status' => $this->rowString($row, 'to_status'),
            'reason_code' => $this->rowNullableString($row, 'reason_code'),
            'reason_note' => $this->rowNullableString($row, 'reason_note'),
            'evidence_refs' => $evidence,
            'screening_result_public_id' => $this->rowNullableString($row, 'screening_result_public_id'),
            'compliance_case_public_id' => $this->rowNullableString($row, 'compliance_case_public_id'),
            'transitioned_at' => $this->rowNullableString($row, 'transitioned_at'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salamGoodsTimelineEvents(int $goodsId): array
    {
        $events = [];

        foreach (DB::table('islamic_salam_goods_transitions')
            ->where('islamic_salam_goods_id', $goodsId)
            ->orderBy('id')
            ->get() as $row) {
            $events[] = ['type' => 'transition'] + $this->transitionPayload($row);
        }

        foreach (DB::table('islamic_salam_goods_deliveries')
            ->where('islamic_salam_goods_id', $goodsId)
            ->orderBy('id')
            ->get() as $row) {
            $events[] = ['type' => 'delivery'] + $this->deliveryPayload($row);
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonArray(object $row, string $field): ?array
    {
        $raw = ((array) $row)[$field] ?? null;
        if (is_array($raw)) {
            return $this->normalizeJsonObject($raw);
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->normalizeJsonObject($decoded) : null;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function normalizeJsonObject(array $value): ?array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    private function postSystemJournal(JournalEntry $journalEntry, User $actor): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
    }

    private function upsertSettlementState(
        int $goodsId,
        string $status,
        int $totalUnits,
        int $deliveredUnits,
        ?string $deliveryPublicId,
        ?string $transitionPublicId,
    ): void {
        $outstandingUnits = max(0, $totalUnits - $deliveredUnits);
        $stateStatus = match ($status) {
            IslamicSalamGoodsStateMachine::STATUS_PARTIALLY_DELIVERED => 'open',
            IslamicSalamGoodsStateMachine::STATUS_IN_DISPUTE,
            IslamicSalamGoodsStateMachine::STATUS_NON_DELIVERY,
            IslamicSalamGoodsStateMachine::STATUS_REJECTED => 'disputed',
            default => $outstandingUnits > 0 ? 'open' : 'resolved',
        };

        $row = DB::table('islamic_salam_settlement_states')
            ->where('islamic_salam_goods_id', $goodsId)
            ->lockForUpdate()
            ->first();

        $payload = [
            'status' => $stateStatus,
            'total_units' => $totalUnits,
            'delivered_units' => $deliveredUnits,
            'outstanding_units' => $outstandingUnits,
            'last_delivery_public_id' => $deliveryPublicId,
            'last_transition_public_id' => $transitionPublicId,
            'state_snapshot' => json_encode([
                'goods_status' => $status,
                'total_units' => $totalUnits,
                'delivered_units' => $deliveredUnits,
                'outstanding_units' => $outstandingUnits,
            ], JSON_THROW_ON_ERROR),
            'resolved_at' => $stateStatus === 'resolved' ? now() : null,
            'updated_at' => now(),
        ];

        if (! is_object($row)) {
            DB::table('islamic_salam_settlement_states')->insert([
                'public_id' => (string) Str::ulid(),
                'islamic_salam_goods_id' => $goodsId,
                'status' => $payload['status'],
                'total_units' => $payload['total_units'],
                'delivered_units' => $payload['delivered_units'],
                'outstanding_units' => $payload['outstanding_units'],
                'last_delivery_public_id' => $payload['last_delivery_public_id'],
                'last_transition_public_id' => $payload['last_transition_public_id'],
                'state_snapshot' => $payload['state_snapshot'],
                'opened_at' => now(),
                'resolved_at' => $payload['resolved_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('islamic_salam_settlement_states')
            ->where('id', $this->rowInt($row, 'id'))
            ->update($payload);
    }

    private function tablePublicIdById(string $table, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
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

    private function rowNullableInt(object $row, string $field): ?int
    {
        $value = ((array) $row)[$field] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function isVagueQualitySpec(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }
        if (strlen($normalized) < 8) {
            return true;
        }
        $vague = [
            'goods',
            'commodity',
            'product',
            'item',
            'standard',
            'generic',
            'n/a',
            'na',
            'tbd',
        ];

        return in_array($normalized, $vague, true);
    }
}
