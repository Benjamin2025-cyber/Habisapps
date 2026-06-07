<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\AccountingDay\AccountingDayException;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

final class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'api.version'])->group(function (): void {
            Route::get('/api/v1/test/localized-success', static function () {
                return ApiResponse::success([
                    ['ok' => true],
                    ['ok' => false],
                ]);
            });

            Route::post('/api/v1/test/localized-validation', static function (Request $request) {
                Validator::make($request->all(), [
                    'email' => ['required', 'email'],
                ])->validate();

                return ApiResponse::success();
            });

            Route::get('/api/v1/test/accounting-day-error', static function (): never {
                throw AccountingDayException::missing();
            });

            Route::get('/api/v1/test/forbidden', static function (): never {
                throw new AccessDeniedHttpException;
            });

            Route::get('/api/v1/test/throttled', static function (): never {
                throw new TooManyRequestsHttpException(60);
            });

            Route::get('/api/v1/test/boom', static function (): never {
                throw new RuntimeException('Sensitive internal failure: db password is hunter2');
            });

            Route::get('/api/v1/test/machine-values', static function () {
                return ApiResponse::success([
                    'status' => 'active',
                    'kyc_status' => 'verified',
                    'permission' => 'roles.create',
                    'operation_type' => 'cash_deposit',
                    'public_id' => 'acct_01HZX9',
                    'reference_number' => 'REF-2026-000123',
                    'stored_name' => 'api.success',
                    'stored_description' => 'Success',
                ], meta: [
                    'contract_code' => 'api.success',
                ]);
            });

            Route::get('/api/v1/test/locale-state', static function () {
                return ApiResponse::success([
                    'locale' => app()->getLocale(),
                    'fallback_locale' => app()->getFallbackLocale(),
                ]);
            });

            Route::get('/api/v1/test/error-machine-values', static function () {
                return ApiResponse::error(__('api.error'), [
                    'code' => 'api.success',
                    'field' => ['api.validation_failed'],
                ]);
            });

            Route::post('/api/v1/test/validation-rules', static function (Request $request) {
                Validator::make($request->all(), [
                    'email' => ['required', 'email'],
                    'amount' => ['required', 'numeric'],
                ])->validate();

                return ApiResponse::success();
            });

            Route::post('/api/v1/test/validation-attributes', static function (Request $request) {
                Validator::make($request->all(), [
                    'phone_number' => ['required', 'string'],
                ])->validate();

                return ApiResponse::success();
            });
        });
    }

    public function test_not_found_is_localized_without_exposing_internals(): void
    {
        $english = $this->withApiHeaders()->getJson('/api/v1/test/does-not-exist');
        $this->assertJsonError($english, 404, 'Resource not found');

        $french = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/does-not-exist');
        $this->assertJsonError($french, 404, 'Ressource introuvable');
        // Model/route class names must never leak in the message.
        $message = $french->json('message');
        self::assertIsString($message);
        self::assertStringNotContainsStringIgnoringCase('Model', $message);
    }

    public function test_forbidden_is_localized(): void
    {
        $english = $this->withApiHeaders()->getJson('/api/v1/test/forbidden');
        $this->assertJsonError($english, 403, 'Forbidden');

        $french = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/forbidden');
        $this->assertJsonError($french, 403, 'Interdit');
    }

    public function test_too_many_requests_is_localized(): void
    {
        $english = $this->withApiHeaders()->getJson('/api/v1/test/throttled');
        $this->assertJsonError($english, 429);
        self::assertSame('Rate limit exceeded. Try again in 60 seconds.', $english->json('message'));

        $french = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/throttled');
        $this->assertJsonError($french, 429);
        self::assertSame('Limite de requêtes dépassée. Réessayez dans 60 secondes.', $french->json('message'));
    }

    public function test_production_generic_error_is_localized_and_safe(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $english = $this->withApiHeaders()->getJson('/api/v1/test/boom');
            $this->assertJsonError($english, 500, 'Internal server error');
            $englishBody = json_encode($english->json());
            self::assertIsString($englishBody);
            self::assertStringNotContainsString('hunter2', $englishBody);

            $french = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/boom');
            $this->assertJsonError($french, 500, 'Erreur interne du serveur');
            $frenchBody = json_encode($french->json());
            self::assertIsString($frenchBody);
            self::assertStringNotContainsString('hunter2', $frenchBody);
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }

    public function test_machine_contract_values_are_identical_across_locales(): void
    {
        $english = $this->withApiHeaders()->getJson('/api/v1/test/machine-values');
        $french = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/machine-values');

        $this->assertJsonSuccess($english);
        $this->assertJsonSuccess($french);

        foreach ([
            'status',
            'kyc_status',
            'permission',
            'operation_type',
            'public_id',
            'reference_number',
            'stored_name',
            'stored_description',
        ] as $key) {
            self::assertSame(
                $english->json("data.{$key}"),
                $french->json("data.{$key}"),
                "Machine value {$key} must not change between locales"
            );
        }

        self::assertSame('api.success', $french->json('data.stored_name'));
        self::assertSame('Success', $french->json('data.stored_description'));
        self::assertSame('api.success', $french->json('meta.contract_code'));

        // Envelope keys themselves remain stable English machine keys.
        $frenchBody = $french->json();
        self::assertIsArray($frenchBody);
        self::assertArrayHasKey('success', $frenchBody);
        self::assertArrayHasKey('message', $frenchBody);
        self::assertArrayHasKey('data', $frenchBody);
    }

    public function test_error_payload_values_are_not_translated_as_message_keys(): void
    {
        $response = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/error-machine-values');

        $this->assertJsonError($response, 400, 'Une erreur est survenue');
        $response->assertJsonPath('errors.code', 'api.success');
        $response->assertJsonPath('errors.field.0', 'api.validation_failed');
    }

    public function test_validation_rule_messages_are_localized_in_english_and_french(): void
    {
        $english = $this->withApiHeaders()->postJson('/api/v1/test/validation-rules', ['amount' => 'abc']);
        $this->assertJsonError($english, 422, 'Validation failed');
        $english->assertJsonPath('errors.email.0', 'The email address field is required.');
        $english->assertJsonPath('errors.amount.0', 'The amount field must be a number.');

        $french = $this->withApiHeaders(['X-Locale' => 'fr'])->postJson('/api/v1/test/validation-rules', ['amount' => 'abc']);
        $this->assertJsonError($french, 422, 'Échec de la validation');
        $french->assertJsonPath('errors.email.0', 'Le champ adresse e-mail est obligatoire.');
        $french->assertJsonPath('errors.amount.0', 'Le champ montant doit être un nombre.');
        // Field keys remain machine-readable, not translated labels.
        $french->assertJsonStructure(['errors' => ['email', 'amount']]);
    }

    public function test_validation_attribute_names_are_humanized_and_localized(): void
    {
        // I13-2: snake_case field names must render as friendly labels, not the
        // raw `phone_number`, in both locales — while the error-bag KEY stays
        // machine-readable.
        $english = $this->withApiHeaders()->postJson('/api/v1/test/validation-attributes', []);
        $this->assertJsonError($english, 422, 'Validation failed');
        $english->assertJsonPath('errors.phone_number.0', 'The phone number field is required.');

        $french = $this->withApiHeaders(['X-Locale' => 'fr'])->postJson('/api/v1/test/validation-attributes', []);
        $this->assertJsonError($french, 422, 'Échec de la validation');
        $french->assertJsonPath('errors.phone_number.0', 'Le champ numéro de téléphone est obligatoire.');
        // Field key stays machine-readable.
        $french->assertJsonStructure(['errors' => ['phone_number']]);
    }

    public function test_validation_attribute_key_sets_match_between_english_and_french(): void
    {
        $english = require lang_path('en/validation.php');
        $french = require lang_path('fr/validation.php');
        self::assertIsArray($english);
        self::assertIsArray($french);

        $englishAttributes = $english['attributes'] ?? null;
        $frenchAttributes = $french['attributes'] ?? null;
        self::assertIsArray($englishAttributes);
        self::assertIsArray($frenchAttributes);
        self::assertNotEmpty($englishAttributes);
        self::assertSame(
            array_keys($englishAttributes),
            array_keys($frenchAttributes),
            'Validation attribute keys differ between en and fr'
        );
    }

    public function test_french_default_locale_applies_when_no_locale_is_requested(): void
    {
        // I13-1: a French-first product must serve French to callers that send no
        // usable X-Locale / Accept-Language (server-to-server, webhooks, cron),
        // while English remains the safe fallback. The test HTTP kernel injects a
        // default `Accept-Language: en-us` header, so we send an empty one to
        // represent a client that expresses no language preference.
        Config::set('localization.default_locale', 'fr');
        Config::set('localization.fallback_locale', 'en');

        $headerless = ['Accept-Language' => ''];

        $state = $this->withApiHeaders($headerless)->getJson('/api/v1/test/locale-state');
        $this->assertJsonSuccess($state);
        $state->assertJsonPath('data.locale', 'fr');
        $state->assertJsonPath('data.fallback_locale', 'en');

        $message = $this->withApiHeaders($headerless)->getJson('/api/v1/test/localized-success');
        $this->assertJsonSuccess($message);
        $message->assertJsonPath('message', 'Succès');
    }

    public function test_dynamic_domain_messages_render_in_both_locales_with_placeholders(): void
    {
        // I13-3: representative dynamic/concatenated domain messages — previously
        // English-only — now render in French with their machine values preserved
        // as placeholder substitutions. English output is asserted byte-for-byte
        // by the existing module suites; here we lock the French rendering and the
        // placeholder contract for one message per high-traffic module.
        $cases = [
            // [key, replacements, expected English, expected French]
            ['loans.status_invalid_transition', ['from' => 'active', 'to' => 'closed'],
                'Loan status cannot transition from active to closed.',
                'Le statut du prêt ne peut pas passer de active à closed.'],
            ['reporting.fx_currency_not_active', ['currency' => 'USD'],
                'Currency USD is not active for currency-exchange operations.',
                'La devise USD n’est pas active pour les opérations de change.'],
            ['domain.unsupported_filter_keys', ['keys' => 'foo, bar'],
                'The following filter keys are not supported: foo, bar',
                'Les clés de filtre suivantes ne sont pas prises en charge : foo, bar'],
            ['crm.document_requires_both_faces', ['type' => 'national_id'],
                'A national_id requires both front and back faces before it can be verified.',
                'Un national_id nécessite à la fois le recto et le verso avant de pouvoir être vérifié.'],
            ['islamic_governance.authority_status_cannot_transition', ['previous' => 'draft', 'to' => 'active'],
                'Authority status draft cannot transition to active.',
                'Le statut d’autorité draft ne peut pas passer à active.'],
            ['insurance.claim_required_evidence_missing', ['types' => 'police_report, invoice'],
                'Required claim evidence is missing: police_report, invoice.',
                'Des pièces justificatives de sinistre requises sont manquantes : police_report, invoice.'],
            ['cash_journal.physical_cash_whole_units', ['currency' => 'XAF'],
                'Physical XAF cash amounts must be whole cash units.',
                'Les montants en espèces physiques en XAF doivent être des unités de caisse entières.'],
            ['database_management.driver_unsupported', [],
                'The configured database driver is not supported for managed backups.',
                'Le pilote de base de données configuré n’est pas pris en charge pour les sauvegardes gérées.'],
        ];

        foreach ($cases as [$key, $replacements, $expectedEnglish, $expectedFrench]) {
            $this->app->setLocale('en');
            self::assertSame($expectedEnglish, __($key, $replacements), "EN render for {$key}");

            $this->app->setLocale('fr');
            self::assertSame($expectedFrench, __($key, $replacements), "FR render for {$key}");
        }
    }

    public function test_translation_calls_do_not_use_colon_prefixed_replacement_keys(): void
    {
        // Guard against a subtle regression: Laravel replacement keys must be
        // bare (`['code' => $x]`), NOT colon-prefixed (`[':code' => $x]`). The
        // latter silently leaves the `:code` placeholder unsubstituted in the
        // rendered message, which content-less feature tests don't catch.
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (str_contains($line, '__(') && preg_match("/(\[|,\s*)':[a-z_]+'\s*=>/", $line) === 1) {
                    $violations[] = $file->getPathname().':'.($number + 1);
                }
            }
        }

        self::assertSame([], $violations, "Colon-prefixed translation replacement keys found:\n".implode("\n", $violations));
    }

    public function test_requested_locale_translates_api_envelope_and_validation_errors(): void
    {
        $success = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/localized-success');

        $this->assertJsonSuccess($success);
        $success->assertJsonPath('message', 'Succès');

        $validation = $this->withApiHeaders(['X-Locale' => 'fr'])->postJson('/api/v1/test/localized-validation', []);

        $this->assertJsonError($validation, 422, 'Échec de la validation');
        $validation->assertJsonPath('errors.email.0', 'Le champ adresse e-mail est obligatoire.');
    }

    public function test_unsupported_locale_falls_back_to_english(): void
    {
        $response = $this->withApiHeaders(['X-Locale' => 'es'])->getJson('/api/v1/test/localized-success');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Success');
    }

    public function test_regional_and_accept_language_locales_resolve_to_supported_locale(): void
    {
        $regional = $this->withApiHeaders(['X-Locale' => 'fr-CM'])->getJson('/api/v1/test/localized-success');
        $this->assertJsonSuccess($regional);
        $regional->assertJsonPath('message', 'Succès');

        $acceptLanguage = $this->withApiHeaders(['Accept-Language' => 'fr-CM,fr;q=0.9,en;q=0.8'])->getJson('/api/v1/test/localized-success');
        $this->assertJsonSuccess($acceptLanguage);
        $acceptLanguage->assertJsonPath('message', 'Succès');
    }

    public function test_malformed_locale_falls_back_to_default_locale(): void
    {
        $response = $this->withApiHeaders(['X-Locale' => '*** invalid ***'])->getJson('/api/v1/test/localized-success');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Success');
    }

    public function test_api_fallback_locale_is_applied_for_request_lifecycle(): void
    {
        Config::set('localization.default_locale', 'fr');
        Config::set('localization.fallback_locale', 'en');

        $response = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/locale-state');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.locale', 'fr');
        $response->assertJsonPath('data.fallback_locale', 'en');
    }

    public function test_locale_metadata_can_be_enabled_without_breaking_pagination(): void
    {
        Config::set('localization.meta_enabled', true);

        try {
            $response = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/localized-success?page=2&per_page=5');

            $this->assertJsonSuccess($response);
            $response->assertJsonPath('message', 'Succès');
            $response->assertJsonPath('meta.locale', 'fr');
            $response->assertJsonPath('meta.pagination.current_page', 2);
            $response->assertJsonPath('meta.pagination.per_page', 5);
        } finally {
            Config::set('localization.meta_enabled', false);
        }
    }

    public function test_domain_exception_messages_are_localized(): void
    {
        $response = $this->withApiHeaders(['X-Locale' => 'fr'])->getJson('/api/v1/test/accounting-day-error');

        $this->assertJsonError($response, 423, 'Aucun jour comptable n’est actuellement ouvert. Le système est en mode consultation seulement ; ouvrez un jour comptable avant d’enregistrer des opérations.');
        $response->assertJsonPath('errors.code', 'accounting_day_missing');
    }

    public function test_api_response_default_messages_are_translation_keys_not_hardcoded_english(): void
    {
        $source = file_get_contents(app_path('Support/ApiResponse.php'));
        self::assertIsString($source);

        // AC-004 / AC-013 static search: the shared envelope must default to
        // translation keys, never to hardcoded English literals.
        foreach ([
            "__('api.success')",
            "__('api.created')",
            "__('api.error')",
            "__('api.not_found')",
            "__('api.unauthorized')",
            "__('api.forbidden')",
            "__('api.validation_failed')",
            "__('api.too_many_requests')",
        ] as $needle) {
            self::assertStringContainsString($needle, $source, "ApiResponse must default to {$needle}");
        }

        foreach (["'Success'", "'Validation failed'", "'Resource not found'", "'Unauthorized'", "'Forbidden'"] as $hardcoded) {
            self::assertStringNotContainsString(
                $hardcoded,
                $source,
                "ApiResponse must not hardcode the English default {$hardcoded}"
            );
        }
    }

    public function test_remaining_untranslated_messages_are_catalogued_for_followup(): void
    {
        // AC-013: a follow-up catalogue documents dynamic/concatenated domain
        // messages that cannot be dictionary-translated and need placeholder
        // refactors in a later task.
        $catalogue = base_path('backlogs/api-localization-followup.md');
        self::assertFileExists($catalogue, 'A localization follow-up catalogue must exist.');

        $contents = file_get_contents($catalogue);
        self::assertIsString($contents);
        self::assertStringContainsString('Dynamic / concatenated', $contents);
    }

    public function test_translation_key_sets_match_between_english_and_french_core_api_files(): void
    {
        $files = [
            'api',
            'accounting_day',
            'database_management',
            'domain',
            'system',
            'validation',
            // I13-3 per-module dynamic-message catalogues.
            'loans',
            'cash_journal',
            'islamic_finance',
            'islamic_governance',
            'crm',
            'reporting',
            'insurance',
            'notifications',
        ];

        foreach ($files as $file) {
            $englishMessages = require lang_path("en/{$file}.php");
            $frenchMessages = require lang_path("fr/{$file}.php");
            self::assertIsArray($englishMessages);
            self::assertIsArray($frenchMessages);

            self::assertSame(array_keys($englishMessages), array_keys($frenchMessages), "Translation keys differ for {$file}");
        }

        $englishRaw = file_get_contents(lang_path('en.json'));
        $frenchRaw = file_get_contents(lang_path('fr.json'));
        self::assertIsString($englishRaw);
        self::assertIsString($frenchRaw);

        $englishDecoded = json_decode($englishRaw, true, 512, JSON_THROW_ON_ERROR);
        $frenchDecoded = json_decode($frenchRaw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($englishDecoded);
        self::assertIsArray($frenchDecoded);

        $englishJson = array_keys($englishDecoded);
        $frenchJson = array_keys($frenchDecoded);
        sort($englishJson);
        sort($frenchJson);

        self::assertSame($englishJson, $frenchJson, 'Translation keys differ for JSON fallback files');
    }
}
