<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

use App\Models\AccountingDay;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raised by AccountingDayGuard when a registration write is not permitted
 * because no accounting day is open, the day is closing/closed, or a
 * caller-supplied business date does not match the open accounting day.
 *
 * It renders itself as a stable, machine-readable JSON error so frontends
 * can detect consultation-only mode consistently across modules.
 */
final class AccountingDayException extends RuntimeException
{
    public const string CODE_MISSING = 'accounting_day_missing';

    public const string CODE_CLOSED = 'accounting_day_closed';

    public const string CODE_CLOSING = 'accounting_day_closing';

    public const string CODE_MISMATCH = 'accounting_day_mismatch';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        private readonly int $statusCode,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function missing(?int $agencyId = null): self
    {
        return new self(
            self::CODE_MISSING,
            __('accounting_day.missing'),
            Response::HTTP_LOCKED,
            array_filter(['agency_id_scope' => $agencyId], static fn (mixed $v): bool => $v !== null),
        );
    }

    public static function closed(AccountingDay $day): self
    {
        return new self(
            self::CODE_CLOSED,
            __('accounting_day.closed', ['date' => $day->business_date->toDateString()]),
            Response::HTTP_LOCKED,
            self::dayContext($day),
        );
    }

    public static function closing(AccountingDay $day): self
    {
        return new self(
            self::CODE_CLOSING,
            __('accounting_day.closing', ['date' => $day->business_date->toDateString()]),
            Response::HTTP_LOCKED,
            self::dayContext($day),
        );
    }

    public static function mismatch(AccountingDay $day, string $requestedDate): self
    {
        return new self(
            self::CODE_MISMATCH,
            __('accounting_day.mismatch', [
                'requested_date' => $requestedDate,
                'date' => $day->business_date->toDateString(),
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            self::dayContext($day) + ['requested_business_date' => $requestedDate],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function dayContext(AccountingDay $day): array
    {
        return [
            'accounting_day_public_id' => $day->public_id,
            'business_date' => $day->business_date->toDateString(),
            'status' => $day->status,
        ];
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return ApiResponse::error(
            $this->getMessage(),
            ['code' => $this->errorCode] + $this->context,
            $this->statusCode,
        );
    }
}
