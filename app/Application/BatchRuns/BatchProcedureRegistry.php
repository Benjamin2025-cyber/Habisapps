<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Http\Resources\BatchProcedureResource;

/**
 * Authoritative catalog of the batch-procedure codes the backend can actually
 * execute.
 *
 * This is the single source of truth shared by execution dispatch
 * ({@see ExecuteRegisteredBatchRun}, {@see ExecuteLoanServicingHooksBatch}) and
 * API presentation (the executable-codes catalog endpoint and the `executable`
 * flag on {@see BatchProcedureResource}).
 *
 * Adding support for a new code means adding one entry here. Both the executor
 * routing and the frontend-facing catalog read from this same list, so there is
 * no second hardcoded list to keep in sync.
 */
final class BatchProcedureRegistry
{
    public const string HANDLER_LOAN_ARREARS = 'loan_arrears';

    public const string HANDLER_CASH_CLOSE = 'cash_close';

    public const string HANDLER_ACCOUNTING_CLOSE = 'accounting_close';

    public const string HANDLER_LOAN_SERVICING_HOOK = 'loan_servicing_hook';

    public const string VARIANT_PORTFOLIO_REPORT = 'portfolio_report';

    public const string VARIANT_NOTIFICATION = 'notification';

    /**
     * Catalog definitions keyed by normalized (lower snake_case) code.
     *
     * @return array<string, array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     group: string,
     *     default_schedule_type: string,
     *     prerequisite_codes: list<string>,
     *     handler: string,
     *     variant: string|null,
     * }>
     */
    private static function definitions(): array
    {
        return [
            'loan_arrears_assessment' => [
                'code' => 'loan_arrears_assessment',
                'label' => 'Loan Arrears Assessment',
                'description' => 'Assesses loan arrears against the active repayment schedule.',
                'group' => 'loan_arrears',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_ARREARS,
                'variant' => null,
            ],
            'loan_monthly_arrears_penalty' => [
                'code' => 'loan_monthly_arrears_penalty',
                'label' => 'Monthly Arrears Penalty',
                'description' => 'Applies the monthly arrears penalty to overdue loan installments.',
                'group' => 'loan_arrears',
                'default_schedule_type' => 'monthly',
                'prerequisite_codes' => ['loan_arrears_assessment'],
                'handler' => self::HANDLER_LOAN_ARREARS,
                'variant' => null,
            ],
            'cash_close_verification' => [
                'code' => 'cash_close_verification',
                'label' => 'Cash Close Verification',
                'description' => 'Verifies teller sessions are closed and reconciled and no cash transactions remain pending before an accounting day can close.',
                'group' => 'cash_close',
                'default_schedule_type' => 'manual',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_CASH_CLOSE,
                'variant' => null,
            ],
            'cash_daily_close' => [
                'code' => 'cash_daily_close',
                'label' => 'Cash Daily Close',
                'description' => 'Runs the institution-wide daily cash close once cash verification has succeeded.',
                'group' => 'cash_close',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => ['cash_close_verification'],
                'handler' => self::HANDLER_CASH_CLOSE,
                'variant' => null,
            ],
            'agency_cash_close' => [
                'code' => 'agency_cash_close',
                'label' => 'Agency Cash Close',
                'description' => 'Runs the agency-scoped daily cash close once cash verification has succeeded.',
                'group' => 'cash_close',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => ['cash_close_verification'],
                'handler' => self::HANDLER_CASH_CLOSE,
                'variant' => null,
            ],
            'accounting_close_verification' => [
                'code' => 'accounting_close_verification',
                'label' => 'Accounting Close Verification',
                'description' => 'Verifies that no unposted journal entries remain before an accounting day can close.',
                'group' => 'accounting_close',
                'default_schedule_type' => 'manual',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_ACCOUNTING_CLOSE,
                'variant' => null,
            ],
            'accounting_daily_close' => [
                'code' => 'accounting_daily_close',
                'label' => 'Accounting Daily Close',
                'description' => 'Runs the institution-wide daily accounting close once accounting verification has succeeded.',
                'group' => 'accounting_close',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => ['accounting_close_verification'],
                'handler' => self::HANDLER_ACCOUNTING_CLOSE,
                'variant' => null,
            ],
            'journal_close_verification' => [
                'code' => 'journal_close_verification',
                'label' => 'Journal Close Verification',
                'description' => 'Verifies that journal entries are balanced and posted before the accounting close.',
                'group' => 'accounting_close',
                'default_schedule_type' => 'manual',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_ACCOUNTING_CLOSE,
                'variant' => null,
            ],
            'loan_portfolio_report_hook' => [
                'code' => 'loan_portfolio_report_hook',
                'label' => 'Loan Portfolio Report Hook',
                'description' => 'Generates loan-servicing portfolio reporting outputs as part of end-of-day hooks.',
                'group' => 'loan_servicing_hooks',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_SERVICING_HOOK,
                'variant' => self::VARIANT_PORTFOLIO_REPORT,
            ],
            'credit_portfolio_report_hook' => [
                'code' => 'credit_portfolio_report_hook',
                'label' => 'Credit Portfolio Report Hook',
                'description' => 'Alias of the loan portfolio report hook that generates credit portfolio reporting outputs.',
                'group' => 'loan_servicing_hooks',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_SERVICING_HOOK,
                'variant' => self::VARIANT_PORTFOLIO_REPORT,
            ],
            'portfolio_report_generation' => [
                'code' => 'portfolio_report_generation',
                'label' => 'Portfolio Report Generation',
                'description' => 'Alias of the loan portfolio report hook that queues a portfolio report run.',
                'group' => 'loan_servicing_hooks',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_SERVICING_HOOK,
                'variant' => self::VARIANT_PORTFOLIO_REPORT,
            ],
            'loan_servicing_notification_hook' => [
                'code' => 'loan_servicing_notification_hook',
                'label' => 'Loan Servicing Notification Hook',
                'description' => 'Queues loan-servicing operator notifications as part of end-of-day hooks.',
                'group' => 'loan_servicing_hooks',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_SERVICING_HOOK,
                'variant' => self::VARIANT_NOTIFICATION,
            ],
            'loan_notifications_hook' => [
                'code' => 'loan_notifications_hook',
                'label' => 'Loan Notifications Hook',
                'description' => 'Alias of the loan servicing notification hook that queues loan notifications.',
                'group' => 'loan_servicing_hooks',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_SERVICING_HOOK,
                'variant' => self::VARIANT_NOTIFICATION,
            ],
            'credit_notification_hook' => [
                'code' => 'credit_notification_hook',
                'label' => 'Credit Notification Hook',
                'description' => 'Alias of the loan servicing notification hook that queues credit notifications.',
                'group' => 'loan_servicing_hooks',
                'default_schedule_type' => 'daily',
                'prerequisite_codes' => [],
                'handler' => self::HANDLER_LOAN_SERVICING_HOOK,
                'variant' => self::VARIANT_NOTIFICATION,
            ],
        ];
    }

    /**
     * Frontend-facing catalog: stable machine codes plus presentation metadata.
     *
     * @return list<array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     group: string,
     *     default_schedule_type: string,
     *     prerequisite_codes: list<string>,
     * }>
     */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::definitions() as $definition) {
            $catalog[] = [
                'code' => $definition['code'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'group' => $definition['group'],
                'default_schedule_type' => $definition['default_schedule_type'],
                'prerequisite_codes' => $definition['prerequisite_codes'],
            ];
        }

        return $catalog;
    }

    /**
     * All executable normalized codes.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    public static function isExecutable(string $code): bool
    {
        return array_key_exists(self::normalize($code), self::definitions());
    }

    /**
     * The dispatch handler key for a code, or null when the code is unknown.
     */
    public static function handlerFor(string $code): ?string
    {
        return self::definitions()[self::normalize($code)]['handler'] ?? null;
    }

    /**
     * The dispatch variant for a code, or null when the code is unknown or the
     * handler has no variant.
     */
    public static function variantFor(string $code): ?string
    {
        return self::definitions()[self::normalize($code)]['variant'] ?? null;
    }

    public static function normalize(string $code): string
    {
        return strtolower(str_replace('-', '_', trim($code)));
    }
}
