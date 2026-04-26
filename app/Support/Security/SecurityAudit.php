<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class SecurityAudit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(string $event, ?User $actor = null, ?Model $subject = null, array $properties = [], ?Request $request = null): void
    {
        $requestProperties = $request instanceof Request
            ? [
                'ip_hash' => $request->ip() !== null ? hash('sha256', $request->ip()) : null,
                'user_agent_hash' => $request->userAgent() !== null ? hash('sha256', $request->userAgent()) : null,
            ]
            : [];

        $activity = activity('security')->event($event);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        if ($subject instanceof Model) {
            $activity->performedOn($subject);
        }

        $activity
            ->withProperties(array_filter(array_merge($properties, $requestProperties), static fn (mixed $value): bool => $value !== null))
            ->log($event);
    }
}
