<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\CloseTellerSessionRequest;
use App\Http\Requests\StoreTellerSessionRequest;
use App\Http\Resources\TellerSessionCollection;
use App\Http\Resources\TellerSessionResource;
use App\Models\Agency;
use App\Models\Denomination;
use App\Models\StaffAgencyAssignment;
use App\Models\TellerSession;
use App\Models\Till;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TellerSessionController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_sessions: array<int, \App\Http\Resources\TellerSessionResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): TellerSessionCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', TellerSession::class)) {
            return $this->respondForbidden();
        }

        $query = TellerSession::query()->with(['agency', 'till', 'teller'])->latest();
        if (! $actor->hasRole('platform-admin')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->where('agency_id', $agencyId);
        }

        return new TellerSessionCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_session: \App\Http\Resources\TellerSessionResource}, errors: null, meta: null}')]
    public function store(StoreTellerSessionRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $till = Till::query()
            ->with(['agency'])
            ->where('public_id', $request->string('till_public_id')->toString())
            ->first();
        if (! $till instanceof Till || ! $till->agency instanceof Agency) {
            return $this->respondUnprocessable(errors: ['till_public_id' => ['The selected till is invalid.']]);
        }

        if (! $this->canAccessAgency($actor, $till->agency_id)) {
            return $this->respondForbidden('Teller session can only be opened inside your agency scope.');
        }

        if ($till->status !== Till::STATUS_ACTIVE || $till->daily_state !== Till::DAILY_STATE_CLOSED) {
            return $this->respondUnprocessable(errors: ['till_public_id' => ['The selected till must be active and closed before opening a teller session.']]);
        }

        $teller = $this->resolveTeller($request, $actor);
        if (! $teller instanceof User || ! $this->canBeAssignedToTill($teller, $till->agency_id)) {
            return $this->respondUnprocessable(errors: ['teller_user_public_id' => ['The selected teller must be an active teller or cashier in the till agency.']]);
        }

        if ($till->assigned_user_id !== null && $till->assigned_user_id !== $teller->id) {
            return $this->respondUnprocessable(errors: ['teller_user_public_id' => ['The selected teller is not assigned to this till.']]);
        }

        if ($this->hasOpenSessionForTill($till->id) || $this->hasOpenSessionForTeller($teller->id)) {
            return $this->respondUnprocessable(errors: ['session' => ['The till or teller already has an open session.']]);
        }

        $openingDeclarationMinor = $request->integer('opening_declaration_minor');
        $currency = $this->normalizedCurrency($request->input('currency', $till->currency));
        $denominationCounts = $this->validatedDenominationCounts(
            $request->input('denomination_counts'),
            $currency,
            $openingDeclarationMinor,
            $till->requires_denominations
        );
        if ($denominationCounts['errors'] !== []) {
            return $this->respondUnprocessable(errors: $denominationCounts['errors']);
        }

        $session = DB::transaction(function () use ($request, $till, $teller, $openingDeclarationMinor, $currency): TellerSession {
            $session = TellerSession::query()->create([
                'public_id' => (string) Str::ulid(),
                'till_id' => $till->id,
                'agency_id' => $till->agency_id,
                'teller_user_id' => $teller->id,
                'business_date' => $request->string('business_date')->toString(),
                'opened_at' => now(),
                'opening_declaration_minor' => $openingDeclarationMinor,
                'currency' => $currency,
                'status' => TellerSession::STATUS_OPEN,
            ]);

            $till->fill([
                'daily_state' => Till::DAILY_STATE_OPEN,
                'opening_balance_minor' => $openingDeclarationMinor,
                'currency' => $currency,
            ])->save();

            return $session;
        });

        $this->securityAudit->record('cash.teller_session.opened', actor: $actor, subject: $session, properties: [
            'till_public_id' => $till->public_id,
            'teller_user_public_id' => $teller->public_id,
            'opening_declaration_minor' => $openingDeclarationMinor,
            'currency' => $currency,
            'denomination_counts' => $denominationCounts['lines'],
        ], request: $request);

        return $this->respondCreated(
            TellerSessionResource::make($session->loadMissing(['agency', 'till', 'teller'])),
            'Teller session opened successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_session: \App\Http\Resources\TellerSessionResource}, errors: null, meta: null}')]
    public function show(Request $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $tellerSession)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(TellerSessionResource::make($tellerSession->loadMissing(['agency', 'till', 'teller'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_session: \App\Http\Resources\TellerSessionResource}, errors: null, meta: null}')]
    public function close(CloseTellerSessionRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session must be linked to a valid till.']]);
        }

        if ($actor->hasRole('teller') && $actor->id !== $tellerSession->teller_user_id) {
            return $this->respondForbidden('A teller can only close their own teller session.');
        }

        if ($tellerSession->status !== TellerSession::STATUS_OPEN) {
            return $this->respondUnprocessable(errors: ['status' => ['Only open teller sessions can be closed.']]);
        }

        if ($this->hasPendingTransactions($tellerSession->id)) {
            return $this->respondUnprocessable(errors: ['transactions' => ['Pending teller transactions must be posted or cancelled before closing the session.']]);
        }

        $closingDeclarationMinor = $request->integer('closing_declaration_minor');
        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        $denominationCounts = $this->validatedDenominationCounts(
            $request->input('denomination_counts'),
            $currency,
            $closingDeclarationMinor,
            $till->requires_denominations
        );
        if ($denominationCounts['errors'] !== []) {
            return $this->respondUnprocessable(errors: $denominationCounts['errors']);
        }

        $theoreticalBalanceMinor = $this->theoreticalBalanceMinor($tellerSession);
        if ($closingDeclarationMinor !== $theoreticalBalanceMinor) {
            return $this->respondUnprocessable(errors: ['closing_declaration_minor' => ['Closing declaration must equal the posted theoretical till balance.']]);
        }

        DB::transaction(function () use ($tellerSession, $till, $closingDeclarationMinor, $currency): void {
            $tellerSession->fill([
                'closed_at' => now(),
                'closing_declaration_minor' => $closingDeclarationMinor,
                'currency' => $currency,
                'status' => TellerSession::STATUS_CLOSED,
            ])->save();

            $till->fill([
                'daily_state' => Till::DAILY_STATE_CLOSED,
                'last_closing_balance_minor' => $closingDeclarationMinor,
                'last_closing_at' => now(),
                'currency' => $currency,
            ])->save();
        });

        $this->securityAudit->record('cash.teller_session.closed', actor: $actor, subject: $tellerSession, properties: [
            'till_public_id' => $till->public_id,
            'closing_declaration_minor' => $closingDeclarationMinor,
            'theoretical_balance_minor' => $theoreticalBalanceMinor,
            'currency' => $currency,
            'denomination_counts' => $denominationCounts['lines'],
        ], request: $request);

        return $this->respondSuccess(
            TellerSessionResource::make($tellerSession->refresh()->loadMissing(['agency', 'till', 'teller'])),
            'Teller session closed successfully'
        );
    }

    private function canAccessAgency(User $actor, int $agencyId): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $this->staffAgencyScope->currentAgencyId($actor) === $agencyId;
    }

    private function resolveTeller(StoreTellerSessionRequest $request, User $actor): ?User
    {
        $publicId = $request->input('teller_user_public_id');
        if ($publicId === null || $publicId === '') {
            return $actor;
        }

        if (! is_string($publicId)) {
            return null;
        }

        $user = User::query()->where('public_id', $publicId)->first();

        return $user instanceof User ? $user : null;
    }

    private function canBeAssignedToTill(User $user, int $agencyId): bool
    {
        if ($user->status !== User::STATUS_ACTIVE || $this->staffAgencyScope->currentAgencyId($user) !== $agencyId) {
            return false;
        }

        if ($user->hasRole('teller') || $user->hasRole('cashier')) {
            return true;
        }

        return DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->where('agency_id', $agencyId)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->where('starts_on', '<=', now()->toDateString())
            ->whereRaw('(ends_on IS NULL OR ends_on >= ?)', [now()->toDateString()])
            ->whereIn('role_at_agency', ['teller', 'cashier'])
            ->exists();
    }

    private function hasOpenSessionForTill(int $tillId): bool
    {
        return TellerSession::query()
            ->where('till_id', $tillId)
            ->where('status', TellerSession::STATUS_OPEN)
            ->first() instanceof TellerSession;
    }

    private function hasOpenSessionForTeller(int $tellerUserId): bool
    {
        return TellerSession::query()
            ->where('teller_user_id', $tellerUserId)
            ->where('status', TellerSession::STATUS_OPEN)
            ->first() instanceof TellerSession;
    }

    private function hasPendingTransactions(int $tellerSessionId): bool
    {
        return DB::table('teller_transactions')
            ->where('teller_session_id', $tellerSessionId)
            ->whereNotIn('status', ['posted', 'reversed', 'cancelled'])
            ->first(['id']) !== null;
    }

    private function theoreticalBalanceMinor(TellerSession $session): int
    {
        $opening = $session->opening_declaration_minor ?? 0;
        $transactions = DB::table('teller_transactions')
            ->where('teller_session_id', $session->id)
            ->where('status', 'posted')
            ->get(['transaction_type', 'amount_minor']);

        $movement = 0;
        foreach ($transactions as $transaction) {
            $type = is_string($transaction->transaction_type)
                ? $transaction->transaction_type
                : '';
            $amount = is_numeric($transaction->amount_minor)
                ? (int) $transaction->amount_minor
                : 0;

            if (str_contains($type, 'withdrawal') || str_contains($type, 'cash_out') || str_contains($type, 'retrait')) {
                $movement -= $amount;

                continue;
            }

            if (str_contains($type, 'deposit') || str_contains($type, 'cash_in') || str_contains($type, 'versement')) {
                $movement += $amount;
            }
        }

        return $opening + $movement;
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{denomination_public_id: string, count: int, amount_minor: int}>}
     */
    private function validatedDenominationCounts(mixed $rawCounts, string $currency, int $expectedTotalMinor, bool $required): array
    {
        if (! is_array($rawCounts)) {
            return $required
                ? ['errors' => ['denomination_counts' => ['Denomination counts are required for this till.']], 'lines' => []]
                : ['errors' => [], 'lines' => []];
        }

        $seen = [];
        $total = 0;
        $lines = [];
        foreach ($rawCounts as $index => $line) {
            if (! is_array($line)) {
                return ['errors' => ['denomination_counts.'.$index => ['Each denomination count must be an object.']], 'lines' => []];
            }

            $publicId = $line['denomination_public_id'] ?? null;
            $count = $line['count'] ?? null;
            if (! is_string($publicId) || ! is_int($count)) {
                return ['errors' => ['denomination_counts.'.$index => ['Each denomination count must include a denomination and integer count.']], 'lines' => []];
            }

            if (array_key_exists($publicId, $seen)) {
                return ['errors' => ['denomination_counts' => ['Duplicate denominations are not allowed.']], 'lines' => []];
            }
            $seen[$publicId] = true;

            $denomination = Denomination::query()->where('public_id', $publicId)->first();
            if (! $denomination instanceof Denomination || $denomination->status !== Denomination::STATUS_ACTIVE || $denomination->currency !== $currency) {
                return ['errors' => ['denomination_counts.'.$index.'.denomination_public_id' => ['The selected denomination must be active and match the session currency.']], 'lines' => []];
            }

            $amountMinor = $denomination->value_minor * $count;
            $total += $amountMinor;
            $lines[] = [
                'denomination_public_id' => $publicId,
                'count' => $count,
                'amount_minor' => $amountMinor,
            ];
        }

        if ($total !== $expectedTotalMinor) {
            return ['errors' => ['denomination_counts' => ['Denomination counts must equal the opening declaration.']], 'lines' => []];
        }

        return ['errors' => [], 'lines' => $lines];
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }
}
