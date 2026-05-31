<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use App\Support\Finance\FormulaPolicyCatalog;
use Illuminate\Http\JsonResponse;

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
    public function identityDocumentTypes(): JsonResponse
    {
        return $this->respondSuccess([
            'identity_document_types' => IdentityDocumentTypeCatalog::all(),
        ]);
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
    public function formulaPolicies(): JsonResponse
    {
        return $this->respondSuccess([
            'formula_policies' => FormulaPolicyCatalog::all(),
        ]);
    }
}
