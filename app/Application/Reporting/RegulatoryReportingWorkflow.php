<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class RegulatoryReportingWorkflow extends BaseController
{
    private const array REGULATORY_REPORT_TYPES = ['emf_trial_balance'];

    private const array ALLOWED_DEFINITION_SOURCES = [
        'ledger_balance',
        'journal_movement',
        'emf_regulatory_balance',
        'portfolio_metric',
    ];

    private const array ALLOWED_DEFINITION_FIELDS = [
        'ledger_account_code',
        'ledger_account_name',
        'emf_code',
        'emf_name',
        'debit_total_minor',
        'credit_total_minor',
        'balance_minor',
        'line_count',
        'outstanding_minor',
        'principal_outstanding_minor',
        'interest_outstanding_minor',
        'penalty_outstanding_minor',
        'par30_outstanding_at_risk_minor',
        'delinquent_overdue_amount_minor',
        'expected_collection_minor',
        'actual_collection_minor',
    ];

    private const array FORBIDDEN_DEFINITION_KEYS = [
        'table',
        'raw_table',
        'source_table',
        'sql',
        'query',
        'from',
        'join',
        'where',
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly MappingCompletenessGate $gate,
    ) {}

    public function storeReportDefinitionVersion(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'report_type' => ['required', 'string', 'max:64'],
            'module' => ['sometimes', 'nullable', 'string', 'max:64'],
            'definition' => ['sometimes', 'nullable', 'array'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'regulatory_source_public_id' => ['required', 'string', 'exists:regulatory_sources,public_id'],
        ])->validate();

        $code = (string) $validated['code'];
        $reportType = (string) $validated['report_type'];
        $definition = is_array($validated['definition'] ?? null) ? $validated['definition'] : null;
        try {
            $this->assertDefinitionAllowlisted($definition);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['definition' => [$exception->getMessage()]]);
        }

        $latest = DB::table('report_definitions')->where('code', $code)->orderByDesc('version')->first(['version']);
        $latestVersion = is_object($latest) && is_numeric($latest->version) ? (int) $latest->version : 0;
        $nextVersion = $latestVersion + 1;

        $sourceRow = DB::table('regulatory_sources')->where('public_id', $validated['regulatory_source_public_id'])->first(['id', 'authority']);
        if (! is_object($sourceRow)) {
            return $this->respondUnprocessable(errors: ['regulatory_source_public_id' => ['The selected regulatory source is invalid.']]);
        }
        if (in_array($reportType, self::REGULATORY_REPORT_TYPES, true)
            && ! in_array($this->rowString($sourceRow, 'authority'), ['cobac', 'beac'], true)) {
            return $this->respondUnprocessable(errors: ['regulatory_source_public_id' => ['EMF/COBAC report definitions require a COBAC or BEAC source.']]);
        }

        $id = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'regulatory_source_id' => $this->rowInt($sourceRow, 'id'),
            'code' => $code,
            'version' => $nextVersion,
            'name' => (string) $validated['name'],
            'report_type' => $reportType,
            'module' => $this->nullableString($validated['module'] ?? null),
            'status' => 'active',
            'effective_from' => $this->nullableString($validated['effective_from'] ?? null),
            'effective_to' => $this->nullableString($validated['effective_to'] ?? null),
            'definition' => $this->jsonOrNull($definition),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('report_definitions')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['report_definition' => ['Definition could not be reloaded.']]);
        }

        return $this->respondCreated([
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'version' => $this->rowInt($row, 'version'),
            'report_type' => $this->rowString($row, 'report_type'),
            'status' => $this->rowString($row, 'status'),
            'effective_from' => $this->rowNullableString($row, 'effective_from'),
            'effective_to' => $this->rowNullableString($row, 'effective_to'),
        ], 'Report definition version created');
    }

    public function reviewReportRun(Request $request, string $runPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $runPublicId, $validated): object {
                $run = DB::table('report_runs')
                    ->where('public_id', $runPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($run)) {
                    throw new InvalidArgumentException('Report run is invalid.');
                }
                if ($this->rowString($run, 'review_status') !== 'pending') {
                    throw new InvalidArgumentException('Report run review has already been recorded.');
                }
                if ((int) (((array) $run)['generated_by_user_id'] ?? 0) === $actor->id) {
                    throw new InvalidArgumentException('Maker cannot review their own report run.');
                }

                $reviewStatus = $validated['decision'] === 'approve' ? 'approved' : 'rejected';
                DB::table('report_runs')->where('id', $this->rowInt($run, 'id'))->update([
                    'review_status' => $reviewStatus,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'review_comments' => $this->nullableString($validated['comments'] ?? null),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('report_runs')->where('id', $this->rowInt($run, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Report run could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['report_run' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('regulatory.report_run.reviewed', actor: $actor, properties: [
            'report_run_public_id' => $runPublicId,
            'review_status' => $this->rowString($row, 'review_status'),
        ], request: $request);

        return $this->respondSuccess([
            'public_id' => $this->rowString($row, 'public_id'),
            'review_status' => $this->rowString($row, 'review_status'),
            'reviewed_at' => $this->rowNullableString($row, 'reviewed_at'),
        ], 'Report run review recorded');
    }

    public function submitReportRun(Request $request, string $runPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'submission_channel' => ['required', 'string', 'max:32'],
            'submission_reference' => ['required', 'string', 'max:191'],
            'submitted_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $runPublicId, $validated): object {
                $run = DB::table('report_runs')
                    ->where('public_id', $runPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($run)) {
                    throw new InvalidArgumentException('Report run is invalid.');
                }
                if ($this->rowString($run, 'review_status') !== 'approved') {
                    throw new InvalidArgumentException('Only approved report runs can be marked as submitted.');
                }
                if ($this->rowNullableString($run, 'submitted_at') !== null) {
                    throw new InvalidArgumentException('Report run has already been submitted.');
                }

                $submittedAt = is_string($validated['submitted_at'] ?? null) && $validated['submitted_at'] !== ''
                    ? $validated['submitted_at']
                    : now()->toISOString();

                DB::table('report_runs')->where('id', $this->rowInt($run, 'id'))->update([
                    'submitted_at' => $submittedAt,
                    'submitted_by_user_id' => $actor->id,
                    'submission_channel' => (string) $validated['submission_channel'],
                    'submission_reference' => (string) $validated['submission_reference'],
                    'updated_at' => now(),
                ]);

                $updated = DB::table('report_runs')->where('id', $this->rowInt($run, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Report run could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['report_run' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('regulatory.report_run.submitted', actor: $actor, properties: [
            'report_run_public_id' => $runPublicId,
            'submission_channel' => $this->rowString($row, 'submission_channel'),
            'submission_reference' => $this->rowString($row, 'submission_reference'),
        ], request: $request);

        return $this->respondSuccess([
            'public_id' => $this->rowString($row, 'public_id'),
            'submitted_at' => $this->rowNullableString($row, 'submitted_at'),
            'submission_channel' => $this->rowNullableString($row, 'submission_channel'),
            'submission_reference' => $this->rowNullableString($row, 'submission_reference'),
        ], 'Report run submission recorded');
    }

    public function inspectMapping(Request $request, string $operationCode): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $agencyId = $request->integer('agency_id');
        $currency = $request->string('currency', 'XAF')->toString();

        $result = $this->gate->describe($operationCode, $agencyId, $currency);

        return $this->respondSuccess($result, 'Mapping completeness inspection');
    }

    /**
     * @param array<string, mixed>|array<int, mixed>|null $definition
     */
    private function assertDefinitionAllowlisted(?array $definition): void
    {
        if ($definition === null) {
            return;
        }

        $this->walkDefinition($definition);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $node
     */
    private function walkDefinition(array $node): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), self::FORBIDDEN_DEFINITION_KEYS, true)) {
                throw new InvalidArgumentException('Report definitions may not reference arbitrary tables, SQL, joins, or raw query fields.');
            }
            if ($key === 'source' && is_string($value) && ! in_array($value, self::ALLOWED_DEFINITION_SOURCES, true)) {
                throw new InvalidArgumentException('Report definition source is not allowlisted: '.$value.'.');
            }
            if ($key === 'field' && is_string($value) && ! in_array($value, self::ALLOWED_DEFINITION_FIELDS, true)) {
                throw new InvalidArgumentException('Report definition field is not allowlisted: '.$value.'.');
            }
            if ($key === 'fields' && is_array($value)) {
                foreach ($value as $field) {
                    if (! is_string($field) || ! in_array($field, self::ALLOWED_DEFINITION_FIELDS, true)) {
                        throw new InvalidArgumentException('Report definition contains a non-allowlisted field.');
                    }
                }
            }
            if (is_array($value)) {
                $this->walkDefinition($value);
            }
        }
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
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
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }
}
