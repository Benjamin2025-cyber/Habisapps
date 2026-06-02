<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Application\Notifications\ClientAlertProducer;
use App\Http\Controllers\BaseController;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class InsuranceClaimWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly InsuranceAccountingService $insuranceAccounting,
        private readonly ClientAlertProducer $clientAlerts,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.claims.intake');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_subscription_public_id' => ['required', 'string', 'exists:insurance_subscriptions,public_id'],
            'claim_type' => ['required', 'string', 'max:64'],
            'incident_date' => ['required', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'claimed_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        try {
            $claim = DB::transaction(function () use ($validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', (string) $validated['insurance_subscription_public_id'])
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }
                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Claims can only be opened on active insurance subscriptions.');
                }

                $incidentDate = (string) $validated['incident_date'];
                $startsOn = $this->rowNullableString($subscription, 'starts_on');
                $endsOn = $this->rowNullableString($subscription, 'ends_on');
                if ($startsOn !== null && $incidentDate < $startsOn) {
                    throw new InvalidArgumentException('Claim incident date is before coverage starts.');
                }
                if ($endsOn !== null && $incidentDate > $endsOn) {
                    throw new InvalidArgumentException('Claim incident date is after coverage ends.');
                }
                $cancellationBlocksClaim = DB::table('insurance_cancellations')
                    ->where('insurance_subscription_id', $this->rowInt($subscription, 'id'))
                    ->where('status', 'approved')
                    ->whereDate('effective_on', '<=', $incidentDate)
                    ->exists();
                if ($cancellationBlocksClaim) {
                    throw new InvalidArgumentException('Claim incident date is on or after the approved cancellation effective date.');
                }

                $currency = $this->stringValue($validated['currency'] ?? $this->rowString($subscription, 'currency'), 'XAF');
                if ($currency !== $this->rowString($subscription, 'currency')) {
                    throw new InvalidArgumentException('Claim currency must match the insurance subscription currency.');
                }

                $id = DB::table('insurance_claims')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'client_id' => $this->rowInt($subscription, 'client_id'),
                    'agency_id' => $this->rowInt($subscription, 'agency_id'),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'claim_number' => 'CLM-'.Str::ulid(),
                    'claim_type' => (string) $validated['claim_type'],
                    'incident_date' => $incidentDate,
                    'description' => $this->nullableString($validated['description'] ?? null),
                    'status' => 'pending',
                    'claimed_amount_minor' => $this->nullableInt($validated['claimed_amount_minor'] ?? null),
                    'indemnified_amount_minor' => null,
                    'currency' => $currency,
                    'settled_at' => null,
                    'journal_entry_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $claim = DB::table('insurance_claims')->where('id', $id)->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim could not be reloaded.');
                }

                return $claim;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.created', actor: $actor, properties: [
            'claim_public_id' => $this->rowString($claim, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->claimPayload($claim), 'Insurance claim created successfully');
    }

    public function attachDocument(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.claims.intake');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        try {
            $attachment = DB::transaction(function () use ($claimPublicId, $validated): array {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                $document = DB::table('documents')
                    ->where('public_id', (string) $validated['document_public_id'])
                    ->first();
                if (! is_object($document)) {
                    throw new InvalidArgumentException('Document is invalid.');
                }

                if ($this->rowInt($document, 'agency_id') !== $this->rowInt($claim, 'agency_id')) {
                    throw new InvalidArgumentException('Document must belong to the claim agency.');
                }

                $ownerType = $this->rowNullableString($document, 'owner_type');
                if ($ownerType !== null && $ownerType !== '') {
                    $ownerId = (int) (((array) $document)['owner_id'] ?? 0);
                    if ($ownerType === Client::class) {
                        if ($ownerId !== $this->rowInt($claim, 'client_id')) {
                            throw new InvalidArgumentException('Document is owned by another client.');
                        }
                    } else {
                        throw new InvalidArgumentException('Document owner is outside the allowed scope for claim evidence.');
                    }
                }

                $claimId = $this->rowInt($claim, 'id');
                $documentId = $this->rowInt($document, 'id');

                $existing = DB::table('insurance_claim_documents')
                    ->where('insurance_claim_id', $claimId)
                    ->where('document_id', $documentId)
                    ->first();

                $documentType = $this->nullableString($validated['document_type'] ?? null);
                $created = false;
                if (! is_object($existing)) {
                    DB::table('insurance_claim_documents')->insert([
                        'insurance_claim_id' => $claimId,
                        'document_id' => $documentId,
                        'document_type' => $documentType,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created = true;
                }

                return [
                    'document_public_id' => $this->rowString($document, 'public_id'),
                    'document_type' => is_object($existing)
                        ? $this->rowNullableString($existing, 'document_type')
                        : $documentType,
                    'created' => $created,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_document' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.document_attached', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'document_public_id' => $attachment['document_public_id'],
        ], request: $request);

        $status = $attachment['created'] ? 201 : 200;

        return $this->respondSuccess(
            data: [
                'document_public_id' => $attachment['document_public_id'],
                'document_type' => $attachment['document_type'],
            ],
            message: $attachment['created']
                ? 'Document attached to claim successfully'
                : 'Document already attached to claim',
            status: $status,
        );
    }

    public function blockDirectDecision(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $this->securityAudit->record('insurance.claim.direct_decision_blocked', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
        ], request: $request);

        return $this->respondForbidden('Direct claim decisions are disabled. Use the maker-checker decision-request workflow.');
    }

    public function requestDecision(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.claims.review');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject', 'settle'])],
            'indemnified_amount_minor' => ['required_if:decision,settle', 'nullable', 'integer', 'min:0'],
            'settled_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $decision = DB::transaction(function () use ($actor, $claimPublicId, $validated): object {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                if ($this->rowString($claim, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending claims can have new decision requests.');
                }

                $hasOpenRequest = DB::table('insurance_claim_decisions')
                    ->where('insurance_claim_id', $this->rowInt($claim, 'id'))
                    ->where('status', 'pending')
                    ->exists();
                if ($hasOpenRequest) {
                    throw new InvalidArgumentException('A pending decision request already exists for this claim.');
                }

                $decisionAction = (string) $validated['decision'];
                if ($decisionAction !== 'settle' && array_key_exists('indemnified_amount_minor', $validated)) {
                    throw new InvalidArgumentException('Only settlement decision requests can include an indemnified amount.');
                }

                $id = DB::table('insurance_claim_decisions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_claim_id' => $this->rowInt($claim, 'id'),
                    'decision' => $decisionAction,
                    'indemnified_amount_minor' => $decisionAction !== 'settle'
                        ? null
                        : $this->nullableInt($validated['indemnified_amount_minor'] ?? null),
                    'settled_on' => $decisionAction === 'settle'
                        ? $this->nullableString($validated['settled_on'] ?? null)
                        : null,
                    'notes' => $this->nullableString($validated['notes'] ?? null),
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'requested_at' => now(),
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'review_comments' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_claim_decisions')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Insurance claim decision request could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_decision' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.decision_requested', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'decision_public_id' => $this->rowString($decision, 'public_id'),
            'decision' => $this->rowString($decision, 'decision'),
        ], request: $request);

        return $this->respondCreated(
            $this->claimDecisionPayload($decision, $claimPublicId),
            'Insurance claim decision request created successfully',
        );
    }

    public function reviewDecision(Request $request, string $decisionPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.claims.review');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'review_decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $decisionPublicId, $validated): array {
                $decision = DB::table('insurance_claim_decisions')
                    ->where('public_id', $decisionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($decision)) {
                    throw new InvalidArgumentException('Insurance claim decision request is invalid.');
                }

                if ($this->rowString($decision, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending decision requests can be reviewed.');
                }

                if ($this->rowInt($decision, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The requester cannot review their own decision request.');
                }

                $claim = DB::table('insurance_claims')
                    ->where('id', $this->rowInt($decision, 'insurance_claim_id'))
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                $reviewDecision = (string) $validated['review_decision'];
                $reviewComments = $this->nullableString($validated['review_comments'] ?? null);
                $newDecisionStatus = $reviewDecision === 'approve' ? 'approved' : 'rejected';

                DB::table('insurance_claim_decisions')
                    ->where('id', $this->rowInt($decision, 'id'))
                    ->update([
                        'status' => $newDecisionStatus,
                        'reviewed_by_user_id' => $actor->id,
                        'reviewed_at' => now(),
                        'review_comments' => $reviewComments,
                        'updated_at' => now(),
                    ]);

                if ($reviewDecision === 'approve') {
                    $decisionAction = $this->rowString($decision, 'decision');
                    if (in_array($decisionAction, ['approve', 'settle'], true)) {
                        $this->assertClaimEvidenceComplete($claim);
                    }
                    if ($decisionAction === 'settle') {
                        $this->assertClaimSettlementAmountAllowed($claim, $decision);
                    }

                    $claimStatus = match ($decisionAction) {
                        'approve' => 'approved',
                        'reject' => 'rejected',
                        'settle' => 'settled',
                        default => throw new InvalidArgumentException('Unsupported claim decision.'),
                    };

                    DB::table('insurance_claims')
                        ->where('id', $this->rowInt($claim, 'id'))
                        ->update([
                            'status' => $claimStatus,
                            'indemnified_amount_minor' => $decisionAction === 'settle'
                                ? $this->rowNullableInt($decision, 'indemnified_amount_minor')
                                : null,
                            'settled_at' => $decisionAction === 'settle'
                                ? ($this->rowNullableString($decision, 'settled_on') ?? now()->toDateString())
                                : null,
                            'updated_at' => now(),
                        ]);
                }

                $reloadedDecision = DB::table('insurance_claim_decisions')->where('id', $this->rowInt($decision, 'id'))->first();
                $reloadedClaim = DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first();
                if (! is_object($reloadedDecision) || ! is_object($reloadedClaim)) {
                    throw new InvalidArgumentException('Insurance claim decision could not be reloaded.');
                }

                return [
                    'decision' => $reloadedDecision,
                    'claim' => $reloadedClaim,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_decision' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.decision_reviewed', actor: $actor, properties: [
            'decision_public_id' => $decisionPublicId,
            'status' => $this->rowString($result['decision'], 'status'),
            'claim_public_id' => $this->rowString($result['claim'], 'public_id'),
            'claim_status' => $this->rowString($result['claim'], 'status'),
        ], request: $request);
        $notificationRows = $this->clientAlerts->produceInsuranceClaimDecisionAlerts(now());

        return $this->respondSuccess([
            'decision' => $this->claimDecisionPayload($result['decision'], $this->rowString($result['claim'], 'public_id')),
            'claim' => $this->claimPayload($result['claim']),
            'notification_outbox_rows' => $notificationRows,
        ], 'Insurance claim decision reviewed successfully');
    }

    public function postSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.claims.settle');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'business_date' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $claimPublicId, $validated): array {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                if ($this->rowString($claim, 'status') !== 'settled') {
                    throw new InvalidArgumentException('Only settled claims can have settlement accounting posted.');
                }
                if ($this->rowNullableInt($claim, 'journal_entry_id') !== null) {
                    throw new InvalidArgumentException('Settlement accounting has already been posted for this claim.');
                }

                $indemnifiedMinor = $this->rowNullableInt($claim, 'indemnified_amount_minor');
                if ($indemnifiedMinor === null || $indemnifiedMinor <= 0) {
                    throw new InvalidArgumentException('Settlement requires a positive indemnified amount.');
                }

                $subscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($claim, 'insurance_subscription_id'))
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }

                $product = DB::table('insurance_products')
                    ->where('id', $this->rowInt($subscription, 'insurance_product_id'))
                    ->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Insurance product is invalid.');
                }

                $rules = $this->productRules($product);
                $businessModel = $this->rowNullableString($product, 'business_model')
                    ?? $this->stringValue($rules['business_model'] ?? null, '');
                if (! in_array($businessModel, ['broker', 'collector', 'premium_collector', 'distributor', 'risk_carrier'], true)) {
                    throw new InvalidArgumentException('Insurance product business model must be configured before settlement posting.');
                }

                $agencyId = $this->rowInt($claim, 'agency_id');
                $currency = $this->rowString($claim, 'currency');
                [$debitLedgerId, $creditLedgerId] = $this->insuranceClaimSettlementLedgers($agencyId, $currency);

                $requestedBusinessDate = is_string($validated['business_date'] ?? null) && $validated['business_date'] !== ''
                    ? $validated['business_date']
                    : null;
                $accountingDay = $this->accountingDayGuard->resolveAccountingDay(
                    $actor,
                    'insurance.claim_settlement',
                    $agencyId,
                    $requestedBusinessDate,
                );
                $businessDate = $accountingDay->business_date->toDateString();
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'insurance-claim-settlement:'.$claimPublicId;

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'ICS-'.Str::upper(Str::random(10)),
                    'business_date' => $businessDate,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_claim_settlement',
                    'source_public_id' => $claimPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => is_string($validated['notes'] ?? null) && $validated['notes'] !== ''
                        ? $validated['notes']
                        : 'Insurance claim settlement ('.$businessModel.')',
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
                    'idempotency_key' => $idempotencyKey,
                    'accounting_day_id' => $accountingDay->id,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $debitLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => $indemnifiedMinor,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => 'Insurance claim settlement debit ('.$businessModel.')',
                ]);
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => 0,
                    'credit_minor' => $indemnifiedMinor,
                    'currency' => $currency,
                    'line_memo' => 'Insurance claim settlement credit ('.$businessModel.')',
                ]);

                $this->insuranceAccounting->postSystemJournal($journalEntry, $actor);

                DB::table('insurance_claims')
                    ->where('id', $this->rowInt($claim, 'id'))
                    ->update([
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);

                $reloadedClaim = DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first();
                if (! is_object($reloadedClaim)) {
                    throw new InvalidArgumentException('Insurance claim could not be reloaded.');
                }

                return [
                    'claim' => $reloadedClaim,
                    'journal_entry_public_id' => $journalEntry->public_id,
                    'business_model' => $businessModel,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_settlement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.settlement_posted', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'journal_entry_public_id' => $result['journal_entry_public_id'],
            'business_model' => $result['business_model'],
        ], request: $request);

        return $this->respondSuccess([
            'claim' => $this->claimPayload($result['claim']),
            'journal_entry_public_id' => $result['journal_entry_public_id'],
            'business_model' => $result['business_model'],
        ], 'Insurance claim settlement posted successfully');
    }

    public function reverseSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.reversals.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($actor, $claimPublicId): array {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim not found.');
                }
                if ($this->rowString($claim, 'status') !== 'settled') {
                    throw new InvalidArgumentException('Only settled claims can have their settlement reversed.');
                }
                if ($this->rowNullableString($claim, 'reversal_at') !== null) {
                    throw new InvalidArgumentException('Settlement has already been reversed.');
                }

                $originalJeId = $this->rowNullableInt($claim, 'journal_entry_id');
                if ($originalJeId === null) {
                    throw new InvalidArgumentException('No journal entry found for this settlement.');
                }
                $originalJe = JournalEntry::find($originalJeId);
                if (! $originalJe instanceof JournalEntry) {
                    throw new InvalidArgumentException('Settlement journal entry not found.');
                }

                $accountingDay = $this->accountingDayGuard->assertCanRegister(
                    $actor,
                    'insurance.claim_settlement',
                    $originalJe->agency_id,
                );

                $reversalJe = JournalEntry::create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'REV-'.Str::upper(Str::random(10)),
                    'business_date' => $accountingDay->business_date->toDateString(),
                    'agency_id' => $originalJe->agency_id,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_claim_settlement_reversal',
                    'source_public_id' => $claimPublicId,
                    'description' => 'Reversal of claim settlement '.$claimPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'created_by_user_id' => $actor->id,
                    'accounting_day_id' => $accountingDay->id,
                ]);

                foreach ($originalJe->lines as $line) {
                    JournalLine::create([
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $originalJe->agency_id,
                        'journal_entry_id' => $reversalJe->id,
                        'ledger_account_id' => $line->ledger_account_id,
                        'customer_account_id' => null,
                        'loan_id' => null,
                        'debit_minor' => $line->credit_minor,
                        'credit_minor' => $line->debit_minor,
                        'currency' => $line->currency,
                        'line_memo' => 'Reversal: '.($line->line_memo ?? ''),
                    ]);
                }

                $this->insuranceAccounting->postSystemJournal($reversalJe, $actor);

                DB::table('insurance_claims')
                    ->where('id', $this->rowInt($claim, 'id'))
                    ->update([
                        'status' => 'settlement_reversed',
                        'reversal_at' => now(),
                        'reversal_journal_entry_id' => $reversalJe->id,
                        'updated_at' => now(),
                    ]);

                return [
                    'claim' => DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first() ?? $claim,
                    'reversal_journal_entry_public_id' => $reversalJe->public_id,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.settlement_reversed', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
        ], request: $request);

        return $this->respondSuccess([
            'claim' => $this->claimPayload($result['claim']),
            'reversal_journal_entry_public_id' => $result['reversal_journal_entry_public_id'],
        ], 'Claim settlement reversed successfully');
    }

    private function actor(Request $request, string $permission): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo($permission) ? $actor : null;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function insuranceClaimSettlementLedgers(int $agencyId, string $currency): array
    {
        $operationCode = 'insurance_claim_settlement';
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts as debit_ledgers', 'debit_ledgers.id', '=', 'operation_account_mappings.debit_ledger_account_id')
            ->join('ledger_accounts as credit_ledgers', 'credit_ledgers.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where('debit_ledgers.agency_id', $agencyId)
            ->where('debit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where('credit_ledgers.agency_id', $agencyId)
            ->where('credit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->orderByRaw('operation_account_mappings.currency IS NULL')
            ->first(['operation_account_mappings.debit_ledger_account_id', 'operation_account_mappings.credit_ledger_account_id']);

        $debitId = is_object($mapping) ? $mapping->debit_ledger_account_id : null;
        $creditId = is_object($mapping) ? $mapping->credit_ledger_account_id : null;
        if (! is_int($debitId) || ! is_int($creditId)) {
            throw new InvalidArgumentException('Active debit and credit ledger mappings are required for '.$operationCode.'.');
        }

        return [$debitId, $creditId];
    }

    /**
     * @return array<string,mixed>
     */
    private function productRules(object $product): array
    {
        $rules = ((array) $product)['rules'] ?? null;
        $source = null;
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            if (is_array($decoded)) {
                $source = $decoded;
            }
        } elseif (is_array($rules)) {
            $source = $rules;
        }
        if ($source === null) {
            return [];
        }

        $result = [];
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function assertClaimEvidenceComplete(object $claim): void
    {
        $subscription = DB::table('insurance_subscriptions')
            ->where('id', $this->rowInt($claim, 'insurance_subscription_id'))
            ->first();
        if (! is_object($subscription)) {
            throw new InvalidArgumentException('Insurance subscription is invalid.');
        }

        $requiredTypes = DB::table('insurance_claim_evidence_configs')
            ->where('insurance_product_id', $this->rowInt($subscription, 'insurance_product_id'))
            ->where('claim_type', $this->rowString($claim, 'claim_type'))
            ->where('is_required', true)
            ->get(['document_type'])
            ->map(fn (object $row): string => $this->rowString($row, 'document_type'))
            ->all();
        if ($requiredTypes === []) {
            return;
        }

        $attachedTypes = DB::table('insurance_claim_documents')
            ->where('insurance_claim_id', $this->rowInt($claim, 'id'))
            ->whereIn('document_type', $requiredTypes)
            ->get(['document_type'])
            ->map(fn (object $row): string => $this->rowString($row, 'document_type'))
            ->all();

        $missingTypes = array_values(array_diff($requiredTypes, $attachedTypes));
        if ($missingTypes !== []) {
            throw new InvalidArgumentException('Required claim evidence is missing: '.implode(', ', $missingTypes).'.');
        }

        DB::table('insurance_claims')
            ->where('id', $this->rowInt($claim, 'id'))
            ->update(['evidence_complete_at' => now(), 'updated_at' => now()]);
    }

    private function assertClaimSettlementAmountAllowed(object $claim, object $decision): void
    {
        $indemnifiedMinor = $this->rowNullableInt($decision, 'indemnified_amount_minor');
        if ($indemnifiedMinor === null || $indemnifiedMinor <= 0) {
            throw new InvalidArgumentException('Settlement decision requires a positive indemnified amount.');
        }

        $claimedMinor = $this->rowNullableInt($claim, 'claimed_amount_minor');
        if ($claimedMinor !== null && $indemnifiedMinor > $claimedMinor) {
            throw new InvalidArgumentException('Settlement amount cannot exceed the claimed amount.');
        }

        $subscription = DB::table('insurance_subscriptions')
            ->where('id', $this->rowInt($claim, 'insurance_subscription_id'))
            ->first();
        if (! is_object($subscription)) {
            throw new InvalidArgumentException('Insurance subscription is invalid.');
        }

        $coverageMinor = $this->rowNullableInt($subscription, 'coverage_amount_minor');
        if ($coverageMinor !== null && $indemnifiedMinor > $coverageMinor) {
            throw new InvalidArgumentException('Settlement amount cannot exceed configured coverage.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function claimDecisionPayload(object $decision, string $claimPublicId): array
    {
        return [
            'public_id' => $this->rowString($decision, 'public_id'),
            'claim_public_id' => $claimPublicId,
            'decision' => $this->rowString($decision, 'decision'),
            'status' => $this->rowString($decision, 'status'),
            'indemnified_amount_minor' => $this->rowNullableInt($decision, 'indemnified_amount_minor'),
            'settled_on' => $this->rowNullableString($decision, 'settled_on'),
            'notes' => $this->rowNullableString($decision, 'notes'),
            'requested_at' => $this->rowNullableString($decision, 'requested_at'),
            'reviewed_at' => $this->rowNullableString($decision, 'reviewed_at'),
            'review_comments' => $this->rowNullableString($decision, 'review_comments'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPayload(object $claim): array
    {
        return [
            'public_id' => $this->rowString($claim, 'public_id'),
            'claim_number' => $this->rowString($claim, 'claim_number'),
            'claim_type' => $this->rowString($claim, 'claim_type'),
            'incident_date' => $this->rowNullableString($claim, 'incident_date'),
            'description' => $this->rowNullableString($claim, 'description'),
            'status' => $this->rowString($claim, 'status'),
            'claimed_amount_minor' => $this->rowNullableInt($claim, 'claimed_amount_minor'),
            'indemnified_amount_minor' => $this->rowNullableInt($claim, 'indemnified_amount_minor'),
            'currency' => $this->rowString($claim, 'currency'),
            'settled_at' => $this->rowNullableString($claim, 'settled_at'),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function rowString(object $row, string $key): string
    {
        return (string) (((array) $row)[$key] ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        return (int) (((array) $row)[$key] ?? 0);
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
