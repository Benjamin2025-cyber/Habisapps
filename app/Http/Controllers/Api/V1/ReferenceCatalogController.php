<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Models\TellerSession;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use App\Support\Finance\FormulaPolicyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferenceCatalogController extends BaseController
{
    /**
     * Accepted identity-document types.
     *
     * Returns the stable machine keys, display labels, required face counts,
     * and expiry requirements used to drive the frontend document-type select
     * and to validate identity-document submissions.
     *
     * @authenticated
     */
    public function identityDocumentTypes(Request $request): JsonResponse
    {
        $items = IdentityDocumentTypeCatalog::all();
        $search = $this->searchTerm($request);
        if ($search !== null) {
            $items = array_values(array_filter($items, fn (array $item): bool => $this->catalogMatchesSearch($item, $search)));
        }

        $pagination = $this->paginateArray($items, $request, 100);

        return $this->respondSuccess([
            'identity_document_types' => $pagination['items'],
        ], 'Identity document types catalog', ['pagination' => $pagination['pagination']]);
    }

    /**
     * Formula policy catalog for the loan-product UI.
     *
     * Returns every formula policy key with its category, label, approval
     * status, owner, approval date, and the loan-product fields it configures.
     * Unapproved policies are returned with `approved=false` so the UI can
     * disable them rather than guessing.
     *
     * @authenticated
     */
    public function formulaPolicies(Request $request): JsonResponse
    {
        $items = FormulaPolicyCatalog::all();
        $search = $this->searchTerm($request);
        if ($search !== null) {
            $items = array_values(array_filter($items, fn (array $item): bool => $this->catalogMatchesSearch($item, $search)));
        }

        $pagination = $this->paginateArray($items, $request, 100);

        return $this->respondSuccess([
            'formula_policies' => $pagination['items'],
        ], 'Formula policies catalog', ['pagination' => $pagination['pagination']]);
    }

    /**
     * Cash transaction tender options for teller deposit/withdrawal screens.
     *
     * @authenticated
     */
    public function cashTransactionOptions(Request $request): JsonResponse
    {
        $sessionPublicId = $request->query('teller_session_public_id');
        $requiresDenominations = false;
        if (is_string($sessionPublicId) && $sessionPublicId !== '') {
            $session = TellerSession::query()
                ->with('till')
                ->where('public_id', $sessionPublicId)
                ->first();
            $requiresDenominations = $session instanceof TellerSession && $session->till !== null
                ? $session->till->requires_denominations
                : false;
        }

        return $this->respondSuccess([
            'payment_methods' => ['cash', 'cheque', 'transfer', 'mixed'],
            'channels' => ['branch_counter', 'mobile_money', 'bank_transfer', 'clearing_house', 'internal_transfer'],
            'required_fields_by_payment_method' => [
                'cash' => ['cash_amount_minor'],
                'cheque' => ['cheque_amount_minor', 'cheque_number', 'cheque_bank_name', 'cheque_issue_date'],
                'transfer' => ['transfer_amount_minor', 'external_reference'],
                'mixed' => ['at_least_two_component_amounts', 'cheque_metadata_when_cheque_component_present'],
            ],
            'notification_channels' => ['sms', 'email', 'push'],
            'denomination_counts_required' => $requiresDenominations,
            'fee_policy_keys' => [],
            'fees' => [
                'server_authoritative' => true,
                'manual_fee_amount_allowed' => false,
                'default_fee_amount_minor' => 0,
            ],
        ], 'Cash transaction options catalog');
    }

    private function searchTerm(Request $request): ?string
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return null;
        }

        return mb_strtolower(trim($search));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function catalogMatchesSearch(array $item, string $search): bool
    {
        $haystack = mb_strtolower(json_encode($item, JSON_THROW_ON_ERROR));

        return str_contains($haystack, $search);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    private function paginateArray(array $items, Request $request, int $defaultPerPage): array
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', $defaultPerPage), 1), 100);
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }
}
