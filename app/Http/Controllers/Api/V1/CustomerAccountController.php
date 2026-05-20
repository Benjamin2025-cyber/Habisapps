<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Accounts\CustomerAccountWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCustomerAccountRequest;
use App\Http\Requests\UpdateCustomerAccountRequest;
use App\Http\Resources\CustomerAccountCollection;
use App\Models\CustomerAccount;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerAccountController extends BaseController
{
    public function __construct(
        private readonly CustomerAccountWorkflow $workflow,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{customer_accounts: array<int, \App\Http\Resources\CustomerAccountResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): CustomerAccountCollection|JsonResponse
    {
        return $this->workflow->index($request);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{customer_account: \App\Http\Resources\CustomerAccountResource}, errors: null, meta: null}')]
    public function store(StoreCustomerAccountRequest $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{customer_account: \App\Http\Resources\CustomerAccountResource}, errors: null, meta: null}')]
    public function show(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        return $this->workflow->show($request, $customerAccount);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{customer_account: \App\Http\Resources\CustomerAccountResource}, errors: null, meta: null}')]
    public function update(UpdateCustomerAccountRequest $request, CustomerAccount $customerAccount): JsonResponse
    {
        return $this->workflow->update($request, $customerAccount);
    }

    public function destroy(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        return $this->workflow->destroy($request, $customerAccount);
    }
}
