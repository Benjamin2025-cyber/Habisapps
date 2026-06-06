<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\BatchRuns\BatchProcedureRegistry;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class BatchProcedureCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_executable_batch_procedure_codes_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/batch-procedures/executable-codes');

        $response->assertUnauthorized();
    }

    public function test_executable_batch_procedure_codes_endpoint_rejects_users_without_browse_permission(): void
    {
        $actor = $this->createUserWithRole('teller');

        $response = $this->actingWith($actor)->getJson('/api/v1/batch-procedures/executable-codes');

        $response->assertForbidden();
    }

    public function test_executable_codes_endpoint_returns_every_dispatchable_batch_procedure_code(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->actingWith($actor)->getJson('/api/v1/batch-procedures/executable-codes');

        $this->assertJsonSuccess($response);

        $codes = array_column($this->arrayJsonPath($response, 'data.executable_codes'), 'code');

        $expected = [
            'loan_arrears_assessment',
            'loan_monthly_arrears_penalty',
            'cash_close_verification',
            'cash_daily_close',
            'agency_cash_close',
            'accounting_close_verification',
            'accounting_daily_close',
            'journal_close_verification',
            'loan_portfolio_report_hook',
            'credit_portfolio_report_hook',
            'portfolio_report_generation',
            'loan_servicing_notification_hook',
            'loan_notifications_hook',
            'credit_notification_hook',
        ];

        foreach ($expected as $code) {
            self::assertContains($code, $codes, "Catalog endpoint is missing executable code {$code}.");
        }

        // The endpoint and execution dispatch read the same registry source.
        self::assertEqualsCanonicalizing(BatchProcedureRegistry::codes(), $codes);
    }

    public function test_executable_codes_items_expose_required_metadata_fields(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->actingWith($actor)->getJson('/api/v1/batch-procedures/executable-codes');

        $this->assertJsonSuccess($response);

        foreach ($this->arrayJsonPath($response, 'data.executable_codes') as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('label', $item);
            self::assertArrayHasKey('description', $item);
            self::assertArrayHasKey('group', $item);
            self::assertArrayHasKey('default_schedule_type', $item);
            self::assertArrayHasKey('prerequisite_codes', $item);
        }
    }

    public function test_openapi_documents_executable_codes_success_envelope_and_item_fields(): void
    {
        $schema = json_decode((string) file_get_contents(public_path('docs/api.json')), true);
        self::assertIsArray($schema);

        $responseSchema = $this->dig($schema, ['paths', '/v1/batch-procedures/executable-codes', 'get', 'responses', '200', 'content', 'application/json', 'schema']);
        self::assertIsArray($responseSchema);
        self::assertSame('object', $responseSchema['type'] ?? null);

        $envelopeProperties = $this->dig($responseSchema, ['properties']);
        self::assertIsArray($envelopeProperties);
        foreach (['success', 'message', 'data', 'errors', 'meta'] as $field) {
            self::assertArrayHasKey($field, $envelopeProperties);
        }

        $executableCodes = $this->dig($responseSchema, ['properties', 'data', 'properties', 'executable_codes']);
        self::assertIsArray($executableCodes);
        self::assertSame('array', $executableCodes['type'] ?? null);

        $items = $executableCodes['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame('#/components/schemas/ExecutableBatchProcedureCodeResource', $items['$ref'] ?? null);

        $itemSchema = $this->dig($schema, ['components', 'schemas', 'ExecutableBatchProcedureCodeResource']);
        self::assertIsArray($itemSchema);

        $itemProperties = $itemSchema['properties'] ?? null;
        self::assertIsArray($itemProperties);
        $required = $itemSchema['required'] ?? null;
        self::assertIsArray($required);
        foreach (['code', 'label', 'description', 'group', 'default_schedule_type', 'prerequisite_codes'] as $field) {
            self::assertArrayHasKey($field, $itemProperties);
            self::assertContains($field, $required);
        }
    }

    /**
     * Walks a decoded JSON structure along the given key path, asserting an
     * array exists at each step. Returns the value at the path (mixed).
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $path
     */
    private function dig(array $data, array $path): mixed
    {
        $current = $data;
        foreach ($path as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }

        return $current;
    }

    public function test_batch_procedure_index_marks_supported_and_unsupported_rows_with_executable_flag(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $supported = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'loan_arrears_assessment',
            'name' => 'Loan Arrears Assessment',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $unsupported = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'custom_unsupported_procedure',
            'name' => 'Custom Unsupported Procedure',
            'schedule_type' => 'manual',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $response = $this->actingWith($actor)->getJson('/api/v1/batch-procedures?per_page=100');

        $this->assertJsonSuccess($response);

        $executableByPublicId = [];
        foreach ($this->arrayJsonPath($response, 'data.procedures') as $procedure) {
            self::assertIsArray($procedure);
            $publicId = $procedure['public_id'];
            self::assertIsString($publicId);
            $executableByPublicId[$publicId] = $procedure['executable'];
        }

        self::assertSame(true, $executableByPublicId[$supported->public_id]);
        self::assertSame(false, $executableByPublicId[$unsupported->public_id]);
    }

    public function test_unsupported_batch_procedure_still_returns_structured_422_execution_failure(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AG-CAT1');

        $unsupportedProcedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'custom_unsupported_procedure',
            'name' => 'Custom Unsupported Procedure',
            'schedule_type' => 'daily',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $run = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $unsupportedProcedure->id,
            'agency_id' => $agency['id'],
            'business_date' => '2026-05-12',
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
        ]);

        $response = $this->actingWith($actor)->postJson('/api/v1/batch-runs/'.$run->public_id.'/execute');

        $this->assertJsonError($response, 422, 'This batch procedure is not executable.');
    }

    private function actingWith(User $actor): self
    {
        return $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('catalog-test')->plainTextToken,
        ]);
    }

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{id:int, code:string, name:string}
     */
    private function createAgency(string $code): array
    {
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $code.' Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'code' => $code, 'name' => $code.' Agency'];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arrayJsonPath(TestResponse $response, string $path): array
    {
        $value = $response->json($path);
        self::assertIsArray($value);

        return $value;
    }
}
