<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Models\BatchRun;
use App\Models\ReportDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ExecuteLoanServicingHooksBatch
{
    private const array PORTFOLIO_REPORT_PROCEDURE_CODES = [
        'loan_portfolio_report_hook',
        'credit_portfolio_report_hook',
        'portfolio_report_generation',
    ];

    private const array NOTIFICATION_PROCEDURE_CODES = [
        'loan_servicing_notification_hook',
        'loan_notifications_hook',
        'credit_notification_hook',
    ];

    public function execute(BatchRun $batchRun): BatchRun
    {
        $batchRun->loadMissing(['batchProcedure', 'agency', 'operator']);
        $procedureCode = $this->normalizedProcedureCode($batchRun);
        if (! $this->supports($procedureCode)) {
            throw new InvalidArgumentException('This batch procedure is not executable by the loan servicing hook runner.');
        }

        if (! in_array($batchRun->status, [BatchRun::STATUS_PENDING, BatchRun::STATUS_FAILED], true)) {
            throw new InvalidArgumentException('Only pending or failed batch runs can be executed.');
        }

        $batchRun->forceFill([
            'status' => BatchRun::STATUS_RUNNING,
            'started_at' => $batchRun->started_at ?? now(),
            'finished_at' => null,
            'failure_reason' => null,
        ])->save();

        try {
            $summary = in_array($procedureCode, self::PORTFOLIO_REPORT_PROCEDURE_CODES, true)
                ? $this->queuePortfolioReportRun($batchRun, $procedureCode)
                : $this->queueNotificationDelivery($batchRun, $procedureCode);

            $batchRun->forceFill([
                'status' => BatchRun::STATUS_SUCCEEDED,
                'summary_payload' => $summary,
                'failure_reason' => null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $batchRun->forceFill([
                'status' => BatchRun::STATUS_FAILED,
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $batchRun->refresh()->loadMissing(['batchProcedure', 'agency', 'operator']);
    }

    public function supports(string $procedureCode): bool
    {
        return in_array($procedureCode, self::PORTFOLIO_REPORT_PROCEDURE_CODES, true)
            || in_array($procedureCode, self::NOTIFICATION_PROCEDURE_CODES, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function queuePortfolioReportRun(BatchRun $batchRun, string $procedureCode): array
    {
        $metadata = $this->metadata($batchRun);
        $definition = $this->reportDefinition($metadata);
        if (! is_object($definition)) {
            return [
                'procedure_code' => $procedureCode,
                'business_date' => $batchRun->business_date,
                'agency_id' => $batchRun->agency_id,
                'hook_status' => 'skipped',
                'skipped_reason' => 'No active credit portfolio report definition was found.',
            ];
        }

        $publicId = (string) Str::ulid();
        DB::table('report_runs')->insert([
            'public_id' => $publicId,
            'report_definition_id' => $this->rowInt($definition, 'id'),
            'agency_id' => $batchRun->agency_id,
            'period_starts_on' => $batchRun->business_date,
            'period_ends_on' => $batchRun->business_date,
            'status' => 'pending',
            'generated_by_user_id' => $batchRun->operator_user_id,
            'parameters' => json_encode([
                'source' => 'loan_servicing_batch_hook',
                'batch_run_public_id' => $batchRun->public_id,
                'hook_only' => true,
            ], JSON_THROW_ON_ERROR),
            'summary' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'procedure_code' => $procedureCode,
            'business_date' => $batchRun->business_date,
            'agency_id' => $batchRun->agency_id,
            'hook_status' => 'queued',
            'report_definition_code' => $this->rowString($definition, 'code'),
            'report_run_public_id' => $publicId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueNotificationDelivery(BatchRun $batchRun, string $procedureCode): array
    {
        $metadata = $this->metadata($batchRun);
        $template = $this->notificationTemplate($metadata);
        $operator = $batchRun->operator;
        if (! is_object($template) || $operator === null || $operator->phone_number === '') {
            return [
                'procedure_code' => $procedureCode,
                'business_date' => $batchRun->business_date,
                'agency_id' => $batchRun->agency_id,
                'hook_status' => 'skipped',
                'skipped_reason' => 'No active template or operator destination was available.',
            ];
        }

        $publicId = (string) Str::ulid();
        DB::table('notification_deliveries')->insert([
            'public_id' => $publicId,
            'notification_template_id' => $this->rowInt($template, 'id'),
            'recipient_type' => $operator::class,
            'recipient_id' => $operator->id,
            'channel' => $this->rowString($template, 'channel'),
            'destination' => $operator->phone_number,
            'subject' => $this->rowNullableString($template, 'subject'),
            'body' => $this->renderTemplate($this->rowString($template, 'body_template'), $batchRun),
            'status' => 'pending',
            'scheduled_at' => now(),
            'metadata' => json_encode([
                'source' => 'loan_servicing_batch_hook',
                'batch_run_public_id' => $batchRun->public_id,
                'procedure_code' => $procedureCode,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'procedure_code' => $procedureCode,
            'business_date' => $batchRun->business_date,
            'agency_id' => $batchRun->agency_id,
            'hook_status' => 'queued',
            'notification_delivery_public_id' => $publicId,
            'notification_template_code' => $this->rowString($template, 'code'),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function reportDefinition(array $metadata): ?object
    {
        $code = $metadata['report_definition_code'] ?? null;
        $query = DB::table('report_definitions')->where('status', 'active');
        if (is_string($code) && $code !== '') {
            return $query->where('code', $code)->first();
        }

        return $query
            ->where('module', 'credit')
            ->where('report_type', ReportDefinition::TYPE_CREDIT_PORTFOLIO_OUTSTANDING)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function notificationTemplate(array $metadata): ?object
    {
        $code = $metadata['notification_template_code'] ?? 'loan_servicing_batch_alert';
        if (! is_string($code) || $code === '') {
            return null;
        }

        return DB::table('notification_templates')
            ->where('code', $code)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
    }

    private function renderTemplate(string $template, BatchRun $batchRun): string
    {
        return strtr($template, [
            '{{business_date}}' => $batchRun->business_date,
            '{{batch_run_public_id}}' => $batchRun->public_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(BatchRun $batchRun): array
    {
        $metadata = $batchRun->batchProcedure?->schedule_metadata;

        return is_array($metadata) ? $metadata : [];
    }

    private function normalizedProcedureCode(BatchRun $batchRun): string
    {
        $procedure = $batchRun->batchProcedure;
        $code = is_string($procedure?->code) ? $procedure->code : '';

        return strtolower(str_replace('-', '_', $code));
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        return (int) (((array) $row)[$key] ?? 0);
    }
}
