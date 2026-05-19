<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NotificationConsentManager
{
    public const string STATUS_OPTED_IN = 'opted_in';

    public const string STATUS_OPTED_OUT = 'opted_out';

    /**
     * @return list<string>
     */
    public static function allowedChannels(): array
    {
        return ['sms', 'email', 'push', 'in_app'];
    }

    /**
     * @return list<string>
     */
    public static function allowedCategories(): array
    {
        return [
            'loan_due',
            'loan_overdue',
            'insurance_premium_due',
            'insurance_claim_decision',
            'report_deadline',
            'report_failed',
        ];
    }

    public function setConsent(
        int $clientId,
        int $agencyId,
        string $channel,
        string $category,
        string $language,
        string $status,
        ?User $changedBy,
    ): object {
        if (! in_array($channel, self::allowedChannels(), true)) {
            throw new InvalidArgumentException('Unsupported notification channel.');
        }
        if (! in_array($category, self::allowedCategories(), true)) {
            throw new InvalidArgumentException('Unsupported notification category.');
        }
        if (! in_array($status, [self::STATUS_OPTED_IN, self::STATUS_OPTED_OUT], true)) {
            throw new InvalidArgumentException('Consent status must be opted_in or opted_out.');
        }

        return DB::transaction(function () use ($clientId, $agencyId, $channel, $category, $language, $status, $changedBy): object {
            $existing = DB::table('client_notification_consents')
                ->where('client_id', $clientId)
                ->where('channel', $channel)
                ->where('category', $category)
                ->where('language', $language)
                ->lockForUpdate()
                ->first();

            $now = now();
            $changedById = $changedBy instanceof User ? $changedBy->id : null;

            if (is_object($existing)) {
                DB::table('client_notification_consents')
                    ->where('id', $this->rowInt($existing, 'id'))
                    ->update([
                        'status' => $status,
                        'language' => $language,
                        'opted_in_at' => $status === self::STATUS_OPTED_IN ? $now : $this->rowNullableString($existing, 'opted_in_at'),
                        'opted_out_at' => $status === self::STATUS_OPTED_OUT ? $now : $this->rowNullableString($existing, 'opted_out_at'),
                        'last_changed_by_user_id' => $changedById,
                        'updated_at' => $now,
                    ]);
                $row = DB::table('client_notification_consents')->where('id', $this->rowInt($existing, 'id'))->first();
            } else {
                DB::table('client_notification_consents')->insert([
                    'public_id' => (string) Str::ulid(),
                    'client_id' => $clientId,
                    'agency_id' => $agencyId,
                    'channel' => $channel,
                    'category' => $category,
                    'language' => $language,
                    'status' => $status,
                    'opted_in_at' => $status === self::STATUS_OPTED_IN ? $now : null,
                    'opted_out_at' => $status === self::STATUS_OPTED_OUT ? $now : null,
                    'last_changed_by_user_id' => $changedById,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $row = DB::table('client_notification_consents')
                    ->where('client_id', $clientId)
                    ->where('channel', $channel)
                    ->where('category', $category)
                    ->where('language', $language)
                    ->first();
            }

            if (! is_object($row)) {
                throw new InvalidArgumentException('Consent row could not be reloaded.');
            }

            return $row;
        });
    }

    public function hasOptedIn(int $clientId, string $channel, string $category): bool
    {
        return $this->hasOptedInForLanguage($clientId, $channel, $category, null);
    }

    public function hasOptedInForLanguage(int $clientId, string $channel, string $category, ?string $language): bool
    {
        $query = DB::table('client_notification_consents')
            ->where('client_id', $clientId)
            ->where('channel', $channel)
            ->where('category', $category)
            ->where('status', self::STATUS_OPTED_IN);

        if ($language !== null && $language !== '') {
            $query->where('language', $language);
        }

        return $query->exists();
    }

    private function rowInt(object $row, string $key): int
    {
        return (int) (((array) $row)[$key] ?? 0);
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
