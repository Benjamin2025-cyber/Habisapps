<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->string('actor_context', 512)->nullable()->after('operator_user_id');
            $table->string('scope_hash', 64)->nullable()->after('actor_context');
        });

        DB::table('batch_runs')
            ->join('batch_procedures', 'batch_procedures.id', '=', 'batch_runs.batch_procedure_id')
            ->leftJoin('agencies', 'agencies.id', '=', 'batch_runs.agency_id')
            ->leftJoin('users', 'users.id', '=', 'batch_runs.operator_user_id')
            ->whereNotNull('batch_runs.idempotency_key')
            ->orderBy('batch_runs.id')
            ->select([
                'batch_runs.id',
                'batch_runs.idempotency_key',
                'batch_runs.summary_payload',
                'batch_runs.business_date',
                'batch_procedures.public_id as batch_procedure_public_id',
                'agencies.code as agency_code',
                'users.id as operator_id',
            ])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $actorContext = is_numeric($row->operator_id) ? 'user:'.(int) $row->operator_id : 'system';
                    $scopeHash = hash('sha256', implode('|', [
                        'POST',
                        'api/v1/batch-runs',
                        $actorContext,
                        (string) $row->idempotency_key,
                    ]));

                    $summaryPayload = $row->summary_payload;
                    if (is_string($summaryPayload)) {
                        $summaryPayload = json_decode($summaryPayload, true);
                    }

                    $requestFingerprint = hash('sha256', json_encode([
                        'actor' => $actorContext,
                        'batch_procedure_public_id' => $row->batch_procedure_public_id,
                        'business_date' => $row->business_date,
                        'agency_code' => $row->agency_code,
                        'summary_payload' => $this->normalizeSummaryPayload(is_array($summaryPayload) ? $summaryPayload : null),
                    ], JSON_THROW_ON_ERROR));

                    DB::table('batch_runs')
                        ->where('id', $row->id)
                        ->update([
                            'actor_context' => $actorContext,
                            'scope_hash' => $scopeHash,
                            'request_fingerprint' => $requestFingerprint,
                        ]);
                }
            }, 'batch_runs.id', 'id');

        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->dropUnique('batch_runs_idempotency_key_unique');
            $table->index('idempotency_key');
            $table->unique('scope_hash');
        });
    }

    public function down(): void
    {
        Schema::table('batch_runs', function (Blueprint $table): void {
            $table->dropUnique(['scope_hash']);
            $table->dropIndex(['idempotency_key']);
            $table->unique('idempotency_key');
            $table->dropColumn(['actor_context', 'scope_hash']);
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function normalizeSummaryPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizeSummaryPayload($value);
            }
        }

        return $payload;
    }
};
