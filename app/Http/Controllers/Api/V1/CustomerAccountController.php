<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCustomerAccountRequest;
use App\Http\Requests\UpdateCustomerAccountRequest;
use App\Http\Resources\CustomerAccountCollection;
use App\Http\Resources\CustomerAccountResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CustomerAccountController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{customer_accounts: array<int, \App\Http\Resources\CustomerAccountResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): CustomerAccountCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', CustomerAccount::class)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = CustomerAccount::query()->with(['client', 'agency', 'ledgerAccount']);

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor) ?? $actor->agency_id;
        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        } elseif ($actor->cannot('create', CustomerAccount::class)) {
            return $this->respondForbidden();
        }

        if ($request->filled('account_number')) {
            $query->where('account_number', $request->string('account_number'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('client_public_id')) {
            $client = Client::query()->where('public_id', $request->string('client_public_id'))->first();
            if (! $client instanceof Client) {
                return $this->respondUnprocessable(errors: ['client_public_id' => ['The selected client is invalid.']]);
            }

            $query->where('client_id', $client->id);
        }

        if ($request->filled('account_type')) {
            $query->where('account_type', $request->string('account_type'));
        }

        if ($request->filled('opened_from')) {
            $query->where('opened_on', '>=', $request->date('opened_from')?->toDateString());
        }

        if ($request->filled('opened_to')) {
            $query->where('opened_on', '<=', $request->date('opened_to')?->toDateString());
        }

        return new CustomerAccountCollection($query->latest()->paginate($perPage));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{customer_account: \App\Http\Resources\CustomerAccountResource}, errors: null, meta: null}')]
    public function store(StoreCustomerAccountRequest $request): JsonResponse
    {
        $client = Client::query()->where('public_id', $request->string('client_public_id'))->first();
        if (! $client instanceof Client) {
            return $this->respondUnprocessable(errors: ['client_public_id' => ['The selected client is invalid.']]);
        }

        $agency = null;
        if ($request->filled('agency_public_id')) {
            $agency = Agency::query()->where('public_id', $request->string('agency_public_id'))->first();
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
            }
        } else {
            $agency = $client->agency;
        }

        $ledgerAccount = null;
        if ($request->filled('ledger_account_public_id')) {
            $ledgerAccount = LedgerAccount::query()->where('public_id', $request->string('ledger_account_public_id'))->first();
            if (! $ledgerAccount instanceof LedgerAccount) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
            }
        }

        if ($agency === null || $agency->id !== $client->agency_id) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency must match the client agency.']]);
        }
        if ($ledgerAccount instanceof LedgerAccount && $ledgerAccount->agency_id !== $agency->id) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must belong to the same agency scope.']]);
        }
        if ($ledgerAccount instanceof LedgerAccount && $ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active.']]);
        }
        if ($client->status !== Client::STATUS_ACTIVE || $client->kyc_status !== Client::KYC_STATUS_VERIFIED) {
            return $this->respondUnprocessable(errors: ['client_public_id' => ['The selected client must be active and KYC-verified.']]);
        }

        $account = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $agency->id,
            'ledger_account_id' => $ledgerAccount?->id,
            'account_number' => $request->string('account_number')->toString(),
            'account_type' => $request->input('account_type'),
            'opened_on' => $request->date('opened_on')?->toDateString(),
            'closed_on' => $request->date('closed_on')?->toDateString(),
            'status' => $request->input('status', CustomerAccount::STATUS_ACTIVE),
        ]);

        $this->securityAudit->record('customer_account.created', actor: $request->user(), subject: $account, request: $request);

        return $this->respondCreated(CustomerAccountResource::make($account->loadMissing(['client', 'agency', 'ledgerAccount'])), 'Customer account created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{customer_account: \App\Http\Resources\CustomerAccountResource}, errors: null, meta: null}')]
    public function show(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $customerAccount)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(CustomerAccountResource::make($customerAccount->loadMissing(['client', 'agency', 'ledgerAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{customer_account: \App\Http\Resources\CustomerAccountResource}, errors: null, meta: null}')]
    public function update(UpdateCustomerAccountRequest $request, CustomerAccount $customerAccount): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('ledger_account_public_id', $validated)) {
            $ledgerAccount = null;
            if ($validated['ledger_account_public_id'] !== null) {
                $ledgerAccount = LedgerAccount::query()->where('public_id', $validated['ledger_account_public_id'])->first();
                if (! $ledgerAccount instanceof LedgerAccount) {
                    return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
                }
                if ($ledgerAccount->agency_id !== $customerAccount->agency_id) {
                    return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must belong to the same agency scope.']]);
                }
                if ($ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
                    return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active.']]);
                }
            }

            if ($ledgerAccount instanceof LedgerAccount) {
                $customerAccount->setAttribute('ledger_account_id', $ledgerAccount->getKey());
            } else {
                $customerAccount->setAttribute('ledger_account_id', null);
            }
            unset($validated['ledger_account_public_id']);
        }

        $customerAccount->fill($validated)->save();

        $this->securityAudit->record('customer_account.updated', actor: $request->user(), subject: $customerAccount, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(CustomerAccountResource::make($customerAccount->loadMissing(['client', 'agency', 'ledgerAccount'])), 'Customer account updated successfully');
    }

    public function destroy(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $customerAccount)) {
            return $this->respondForbidden();
        }

        $customerAccount->update(['status' => CustomerAccount::STATUS_ARCHIVED]);
        $this->securityAudit->record('customer_account.archived', actor: $request->user(), subject: $customerAccount, request: $request);

        return $this->respondSuccess(message: 'Customer account archived successfully');
    }
}
