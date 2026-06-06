<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SecurityAudit
{
    /**
     * Record a security/domain audit event.
     *
     * The machine event code is preserved in the activity `event` column (the
     * stable key used for filtering, alert rules, and correlation). The
     * activity `description` column receives a human-readable, operator-facing
     * label. Explicit descriptions are accepted only when they do not contain
     * sensitive-looking runtime values. When no explicit description is provided
     * the label is resolved from {@see SecurityEventCatalog}, which derives text
     * from the event code alone and so never leaks property, actor, subject, or
     * request values.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(string $event, ?User $actor = null, ?Model $subject = null, array $properties = [], ?Request $request = null, ?string $description = null): void
    {
        $requestProperties = $request instanceof Request
            ? [
                'ip_hash' => $request->ip() !== null ? hash('sha256', $request->ip()) : null,
                'user_agent_hash' => $request->userAgent() !== null ? hash('sha256', $request->userAgent()) : null,
            ]
            : [];

        $resolvedDescription = $description ?? SecurityEventCatalog::describe($event);
        if ($description !== null) {
            $this->guardSafeDescription($resolvedDescription);
        }

        $activity = activity('security')->event($event);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        if ($subject instanceof Model) {
            $activity->performedOn($subject);
        }

        $activity
            ->withProperties(array_filter(array_merge($properties, $requestProperties), static fn (mixed $value): bool => $value !== null))
            ->log($resolvedDescription);
    }

    private function guardSafeDescription(string $description): void
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('Audit event descriptions must not be blank.');
        }

        $normalized = strtolower($description);
        foreach (['password', 'token', 'otp', 'secret', 'authorization', 'phone'] as $sensitiveFragment) {
            if (str_contains($normalized, $sensitiveFragment)) {
                throw new InvalidArgumentException('Audit event descriptions must not contain sensitive values.');
            }
        }

        if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $description) === 1) {
            throw new InvalidArgumentException('Audit event descriptions must not contain sensitive values.');
        }

        if (preg_match('/\+\d{7,15}\b|\b\d{6,}\b/', $description) === 1) {
            throw new InvalidArgumentException('Audit event descriptions must not contain sensitive values.');
        }
    }
}
