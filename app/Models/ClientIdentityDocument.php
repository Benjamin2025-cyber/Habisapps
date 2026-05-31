<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'public_id',
    'agency_id',
    'client_id',
    'document_type',
    'document_number',
    'document_number_hash',
    'issuing_authority',
    'issued_on',
    'expires_on',
    'verification_status',
    'submitted_at',
    'verified_at',
    'verified_by_user_id',
    'rejected_at',
    'rejection_reason',
    'document_id',
    'back_document_id',
    'created_by_user_id',
    'status',
    'archived_at',
])]
final class ClientIdentityDocument extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ARCHIVED = 'archived';

    public const string VERIFICATION_PENDING = 'pending';

    public const string VERIFICATION_PENDING_REVIEW = 'pending_review';

    public const string VERIFICATION_VERIFIED = 'verified';

    public const string VERIFICATION_REJECTED = 'rejected';

    protected static function booted(): void
    {
        self::saving(function (self $document): void {
            $documentType = $document->getAttribute('document_type');
            $documentNumber = $document->getAttribute('document_number');

            if (! is_string($documentType) || $documentType === '' || ! is_string($documentNumber) || $documentNumber === '') {
                return;
            }

            $document->document_number_hash = self::documentNumberHash(
                self::normalizeDocumentNumber($documentNumber)
            );
        });
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function documentNumber(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => $this->decryptStoredString(is_string($value) ? $value : null),
            set: fn (string $value): string => Crypt::encryptString(self::normalizeDocumentNumber($value)),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function issuingAuthority(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => is_string($value) ? $this->decryptStoredString($value) : null,
            set: fn (?string $value): ?string => $value === null ? null : Crypt::encryptString($value),
        );
    }

    public static function normalizeDocumentNumber(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($value));

        return is_string($normalized) ? $normalized : strtoupper($value);
    }

    public static function documentNumberHash(string $normalizedNumber): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', $normalizedNumber, is_string($key) && $key !== '' ? $key : 'habis-finance-api');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function backDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'back_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    private function decryptStoredString(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
}
