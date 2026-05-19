<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\NotificationConsentManager;
use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class NotificationConsentController extends BaseController
{
    public function __construct(
        private readonly NotificationConsentManager $consents,
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function store(Request $request, string $clientPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'channel' => ['required', Rule::in(NotificationConsentManager::allowedChannels())],
            'category' => ['required', Rule::in(NotificationConsentManager::allowedCategories())],
            'language' => ['sometimes', 'string', 'max:8'],
            'status' => ['required', Rule::in([NotificationConsentManager::STATUS_OPTED_IN, NotificationConsentManager::STATUS_OPTED_OUT])],
        ])->validate();

        $client = DB::table('clients')->where('public_id', $clientPublicId)->first(['id', 'agency_id']);
        if (! is_object($client)) {
            return $this->respondNotFound('Client not found.');
        }

        try {
            $consent = $this->consents->setConsent(
                clientId: (int) (((array) $client)['id'] ?? 0),
                agencyId: (int) (((array) $client)['agency_id'] ?? 0),
                channel: (string) $validated['channel'],
                category: (string) $validated['category'],
                language: is_string($validated['language'] ?? null) && $validated['language'] !== ''
                    ? $validated['language']
                    : 'fr',
                status: (string) $validated['status'],
                changedBy: $actor,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['notification_consent' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('notification.consent.recorded', actor: $actor, properties: [
            'client_public_id' => $clientPublicId,
            'channel' => (string) $validated['channel'],
            'category' => (string) $validated['category'],
            'status' => (string) $validated['status'],
        ], request: $request);

        return $this->respondSuccess([
            'public_id' => (string) (((array) $consent)['public_id'] ?? ''),
            'channel' => (string) (((array) $consent)['channel'] ?? ''),
            'category' => (string) (((array) $consent)['category'] ?? ''),
            'language' => (string) (((array) $consent)['language'] ?? ''),
            'status' => (string) (((array) $consent)['status'] ?? ''),
        ], 'Notification consent recorded successfully');
    }
}
