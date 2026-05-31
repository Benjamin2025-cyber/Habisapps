<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
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
