<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCustomerAccountRequest;
use App\Http\Requests\UpdateCustomerAccountRequest;
use App\Http\Resources\CustomerAccountCollection;
use App\Http\Resources\CustomerAccountResource;
use App\Models\AccountProduct;
use App\Models\Agency;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Accounts\CustomerAccountNumberGenerator;
use App\Support\References\ReferenceNumberGenerator;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CustomerAccountWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly ReferenceNumberGenerator $referenceNumberGenerator,
        private readonly OpenCustomerLedgerAccount $openCustomerLedgerAccount,
        private readonly CustomerAccountNumberGenerator $accountNumbers,
    ) {}

    public function index(Request $request): CustomerAccountCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', CustomerAccount::class)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = CustomerAccount::query()->with(['client', 'agency', 'ledgerAccount', 'accountProduct']);

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
                return $this->respondUnprocessable(errors: ['client_public_id' => [__('The selected client is invalid.')]]);
            }

            $query->where('client_id', $client->id);
        }

        if ($request->filled('account_type')) {
            $query->where('account_type', $request->string('account_type'));
        }

        if ($request->filled('account_product_public_id')) {
            $product = AccountProduct::query()->where('public_id', $request->string('account_product_public_id'))->first();
            if (! $product instanceof AccountProduct) {
                return $this->respondUnprocessable(errors: ['account_product_public_id' => [__('The selected account product is invalid.')]]);
            }

            $query->where('account_product_id', $product->id);
        }

        if ($request->filled('opened_from')) {
            $query->where('opened_on', '>=', $request->date('opened_from')?->toDateString());
        }

        if ($request->filled('opened_to')) {
            $query->where('opened_on', '<=', $request->date('opened_to')?->toDateString());
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('account_number', 'ilike', '%'.$term.'%')
                    ->orWhere('account_title', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('account_type', 'ilike', '%'.$term.'%')
                    ->orWhereHas('client', function (Builder $clientQuery) use ($term): void {
                        $clientQuery
                            ->where('client_reference', 'ilike', '%'.$term.'%')
                            ->orWhere('first_name', 'ilike', '%'.$term.'%')
                            ->orWhere('last_name', 'ilike', '%'.$term.'%');
                    });
            });
        }

        return new CustomerAccountCollection($query->latest()->paginate($perPage));
    }

    public function store(StoreCustomerAccountRequest $request): JsonResponse
    {
        $client = Client::query()->where('public_id', $request->string('client_public_id'))->first();
        if (! $client instanceof Client) {
            return $this->respondUnprocessable(errors: ['client_public_id' => [__('The selected client is invalid.')]]);
        }

        $agency = null;
        if ($request->filled('agency_public_id')) {
            $agency = Agency::query()->where('public_id', $request->string('agency_public_id'))->first();
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.staff_selected_agency_invalid')]]);
            }
        } else {
            $agency = $client->agency;
        }

        $ledgerAccount = null;
        if ($request->filled('ledger_account_public_id')) {
            $ledgerAccount = LedgerAccount::query()->where('public_id', $request->string('ledger_account_public_id'))->first();
            if (! $ledgerAccount instanceof LedgerAccount) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account is invalid.')]]);
            }
        }

        if ($agency === null || $agency->id !== $client->agency_id) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => [__('The selected agency must match the client agency.')]]);
        }
        if ($ledgerAccount instanceof LedgerAccount && $ledgerAccount->agency_id !== $agency->id) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account must belong to the same agency scope.')]]);
        }
        if ($ledgerAccount instanceof LedgerAccount && $ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account must be active.')]]);
        }
        if ($client->status !== Client::STATUS_ACTIVE || $client->kyc_status !== Client::KYC_STATUS_VERIFIED) {
            return $this->respondUnprocessable(errors: ['client_public_id' => [__('The selected client must be active and KYC-verified.')]]);
        }

        $accountProduct = $this->resolveAccountProduct($request->input('account_product_public_id'));
        if ($accountProduct === false) {
            return $this->respondUnprocessable(errors: ['account_product_public_id' => [__('The selected account product is invalid.')]]);
        }
        if ($accountProduct instanceof AccountProduct && ! $this->accountProductIsCompatible($accountProduct, $agency->id)) {
            return $this->respondUnprocessable(errors: ['account_product_public_id' => [__('The selected account product must be active and available to the account agency.')]]);
        }
        if (! $ledgerAccount instanceof LedgerAccount && $accountProduct instanceof AccountProduct && $accountProduct->ledgerAccount instanceof LedgerAccount) {
            $ledgerAccount = $accountProduct->ledgerAccount;
        }

        $providedAccountNumber = $request->string('account_number')->toString();
        if ($providedAccountNumber !== '') {
            $accountNumber = $providedAccountNumber;
        } elseif ($ledgerAccount instanceof LedgerAccount) {
            // « Les numéros de comptes doivent représenter les numéros
            // comptables auxquels ils correspondent » — ten digits built from
            // the control account and the agency. See
            // CustomerAccountNumberGenerator.
            $accountNumber = $this->accountNumbers->generate($ledgerAccount->code, $agency->code);
        } else {
            // No control account resolved, so there is no accounting number to
            // derive one from; fall back to the flat ACC- series rather than
            // minting something that looks like a chart code and is not.
            $accountNumber = $this->referenceNumberGenerator->reserve('account');
        }

        // « le numéro du client soit directement son compte comptable » — the
        // resolved control (explicit or product default) is not posted to
        // directly; the client's own divisionary under it is, coded with their
        // client number. Postings, balances and reconciliation read
        // customer_accounts.ledger_account_id exactly as before.
        if ($ledgerAccount instanceof LedgerAccount) {
            $ledgerAccount = $this->openCustomerLedgerAccount->open($client, $ledgerAccount, $agency->id, $accountNumber);
        }

        $account = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $agency->id,
            'ledger_account_id' => $ledgerAccount?->id,
            'account_product_id' => $accountProduct instanceof AccountProduct ? $accountProduct->id : null,
            'account_number' => $accountNumber,
            'account_title' => $request->input('account_title'),
            'account_type' => $request->input('account_type', $accountProduct instanceof AccountProduct ? $accountProduct->account_family : null),
            'currency' => strtoupper($request->string('currency', $accountProduct instanceof AccountProduct ? $accountProduct->currency : 'XAF')->toString()),
            /*
             * The « frais d'ouverture » fixed at the tariff of the day, the way
             * conditions tarifaires work: what this customer owes for opening is
             * what the product charged when they opened, not what it charges
             * next year. Reading the live product instead would make the sweep
             * retroactive — switching the fee on would bill the whole existing
             * book on their next deposit.
             */
            'opening_fee_due_minor' => $accountProduct instanceof AccountProduct ? max(0, $accountProduct->opening_fee_minor) : 0,
            'opened_on' => $request->date('opened_on')?->toDateString(),
            'closed_on' => $request->date('closed_on')?->toDateString(),
            'status' => $request->input('status', CustomerAccount::STATUS_ACTIVE),
        ]);

        $this->securityAudit->record('customer_account.created', actor: $request->user(), subject: $account, request: $request);

        return $this->respondCreated(CustomerAccountResource::make($account->loadMissing(['client', 'agency', 'ledgerAccount', 'accountProduct'])), 'Customer account created successfully');
    }

    public function show(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $customerAccount)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(CustomerAccountResource::make($customerAccount->loadMissing(['client', 'agency', 'ledgerAccount', 'accountProduct'])));
    }

    public function update(UpdateCustomerAccountRequest $request, CustomerAccount $customerAccount): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('ledger_account_public_id', $validated)) {
            $ledgerAccount = null;
            if ($validated['ledger_account_public_id'] !== null) {
                $ledgerAccount = LedgerAccount::query()->where('public_id', $validated['ledger_account_public_id'])->first();
                if (! $ledgerAccount instanceof LedgerAccount) {
                    return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account is invalid.')]]);
                }
                if ($ledgerAccount->agency_id !== $customerAccount->agency_id) {
                    return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account must belong to the same agency scope.')]]);
                }
                if ($ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
                    return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account must be active.')]]);
                }
            }

            if ($ledgerAccount instanceof LedgerAccount) {
                $customerAccount->setAttribute('ledger_account_id', $ledgerAccount->getKey());
            } else {
                $customerAccount->setAttribute('ledger_account_id', null);
            }
            unset($validated['ledger_account_public_id']);
        }

        // Read before the product branch below, which writes into the same key.
        // Taken from the request rather than $validated so a re-snapshot cannot
        // be mistaken for something the operator typed.
        $explicitFee = $request->has('opening_fee_due_minor')
            ? $request->integer('opening_fee_due_minor')
            : null;

        if (array_key_exists('account_product_public_id', $validated)) {
            $accountProduct = $this->resolveAccountProduct($validated['account_product_public_id']);
            if ($accountProduct === false) {
                return $this->respondUnprocessable(errors: ['account_product_public_id' => [__('The selected account product is invalid.')]]);
            }
            if ($accountProduct instanceof AccountProduct && ! $this->accountProductIsCompatible($accountProduct, $customerAccount->agency_id)) {
                return $this->respondUnprocessable(errors: ['account_product_public_id' => [__('The selected account product must be active and available to the account agency.')]]);
            }

            $validated['account_product_id'] = $accountProduct instanceof AccountProduct ? $accountProduct->id : null;
            unset($validated['account_product_public_id']);

            /*
             * Moving an account to another product is a correction — the wrong
             * one was picked at opening — so the fee it owes follows, but only
             * while it is still owed. Once swept, the figure is history and
             * re-snapshotting it would either bill the customer twice or rewrite
             * what they were charged. Note this fires on an explicit product
             * change, never on someone editing the product's own tariff, which
             * is what must not reach accounts already opened.
             */
            $productChanged = $validated['account_product_id'] !== $customerAccount->account_product_id;
            if ($productChanged && $customerAccount->opening_fee_collected_at === null) {
                $validated['opening_fee_due_minor'] = $accountProduct instanceof AccountProduct
                    ? max(0, $accountProduct->opening_fee_minor)
                    : 0;
            }
        }

        // Applied last, so a figure the operator typed outranks the default a
        // product change would otherwise have re-snapshotted over it.
        if ($explicitFee !== null) {
            if ($customerAccount->opening_fee_collected_at !== null) {
                return $this->respondUnprocessable(errors: ['opening_fee_due_minor' => [
                    __('The opening fee has already been collected and can no longer be changed.'),
                ]]);
            }

            $validated['opening_fee_due_minor'] = max(0, $explicitFee);
        }

        if (array_key_exists('currency', $validated) && is_string($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $customerAccount->fill($validated)->save();

        $this->securityAudit->record('customer_account.updated', actor: $request->user(), subject: $customerAccount, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(CustomerAccountResource::make($customerAccount->refresh()->loadMissing(['client', 'agency', 'ledgerAccount', 'accountProduct'])), 'Customer account updated successfully');
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

    private function resolveAccountProduct(mixed $publicId): AccountProduct|false|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $product = AccountProduct::query()
            ->with('ledgerAccount')
            ->where('public_id', $publicId)
            ->first();

        return $product instanceof AccountProduct ? $product : false;
    }

    private function accountProductIsCompatible(AccountProduct $product, int $agencyId): bool
    {
        return $product->status === AccountProduct::STATUS_ACTIVE
            && ($product->agency_id === null || $product->agency_id === $agencyId)
            && (! $product->ledgerAccount instanceof LedgerAccount || $product->ledgerAccount->agency_id === $agencyId);
    }
}
