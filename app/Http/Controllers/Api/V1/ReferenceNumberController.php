<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\ReserveReferenceNumberRequest;
use App\Models\User;
use App\Support\References\ReferenceNumberGenerator;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;

final class ReferenceNumberController extends BaseController
{
    public function __construct(
        private readonly ReferenceNumberGenerator $referenceNumbers,
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function store(ReserveReferenceNumberRequest $request): JsonResponse
    {
        $key = $request->string('key')->toString();
        $reference = $this->referenceNumbers->reserve($key);
        $actor = $request->user();

        $this->securityAudit->record('reference.reserved', actor: $actor instanceof User ? $actor : null, properties: [
            'key' => $key,
            'reference' => $reference,
        ], request: $request);

        return $this->respondCreated([
            'key' => $key,
            'reference' => $reference,
        ], 'Reference number reserved successfully');
    }
}
