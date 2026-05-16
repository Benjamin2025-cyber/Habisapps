<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE client_identity_documents DROP CONSTRAINT IF EXISTS uniq_identity_document_number');
        DB::statement('ALTER TABLE client_identity_documents ALTER COLUMN document_number TYPE text');
        DB::statement('ALTER TABLE client_identity_documents ALTER COLUMN issuing_authority TYPE text');

        Schema::table('client_identity_documents', function ($table): void {
            $table->string('document_number_hash', 64)->nullable()->after('document_number');
        });

        DB::table('client_identity_documents')
            ->orderBy('id')
            ->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    $documentNumber = is_string($record->document_number) ? $record->document_number : '';
                    $normalizedNumber = self::normalizeDocumentNumber($documentNumber);

                    DB::table('client_identity_documents')
                        ->where('id', $record->id)
                        ->update([
                            'document_number' => Crypt::encryptString($normalizedNumber),
                            'document_number_hash' => self::documentNumberHash($normalizedNumber),
                            'issuing_authority' => is_string($record->issuing_authority)
                                ? Crypt::encryptString($record->issuing_authority)
                                : null,
                        ]);
                }
            });

        DB::statement('CREATE UNIQUE INDEX uniq_identity_document_number_hash ON client_identity_documents (document_type, document_number_hash) WHERE document_number_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_identity_document_number_hash');

        DB::table('client_identity_documents')
            ->orderBy('id')
            ->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    $documentNumber = is_string($record->document_number)
                        ? self::decryptIfEncrypted($record->document_number)
                        : '';
                    $issuingAuthority = is_string($record->issuing_authority)
                        ? self::decryptIfEncrypted($record->issuing_authority)
                        : null;

                    DB::table('client_identity_documents')
                        ->where('id', $record->id)
                        ->update([
                            'document_number' => self::normalizeDocumentNumber($documentNumber),
                            'issuing_authority' => $issuingAuthority,
                        ]);
                }
            });

        Schema::table('client_identity_documents', function ($table): void {
            $table->dropColumn('document_number_hash');
        });

        DB::statement('ALTER TABLE client_identity_documents ALTER COLUMN document_number TYPE varchar(128)');
        DB::statement('ALTER TABLE client_identity_documents ALTER COLUMN issuing_authority TYPE varchar(255)');
        DB::statement('ALTER TABLE client_identity_documents ADD CONSTRAINT uniq_identity_document_number UNIQUE (document_type, document_number)');
    }

    private static function normalizeDocumentNumber(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($value));

        return is_string($normalized) ? $normalized : strtoupper($value);
    }

    private static function documentNumberHash(string $normalizedNumber): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', $normalizedNumber, is_string($key) && $key !== '' ? $key : 'habis-finance-api');
    }

    private static function decryptIfEncrypted(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }
};
