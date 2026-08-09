<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $agency_id
 * @property string $code
 * @property string $name
 * @property string $account_class
 * @property string|null $account_type
 * @property bool $is_postable
 * @property int|null $parent_account_id
 * @property string $normal_balance_side
 * @property string $status
 */
#[Fillable([
    'public_id',
    'agency_id',
    'code',
    'name',
    'account_class',
    'account_type',
    'is_postable',
    'parent_account_id',
    'normal_balance_side',
    'status',
])]
final class LedgerAccount extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    /*
     * PCEMF classes — the eight top-level classes of the Plan Comptable des
     * Établissements de Microfinance (CEMAC/COBAC), which is the chart a
     * Cameroonian EMF is required to keep. The class is the first digit of the
     * account code: 571001 is a class 5 account.
     *
     * These replaced the IFRS-style asset/liability/equity/revenue/expense
     * values, which named the *nature* of an account rather than its place in
     * the national chart, and which accountants here do not recognise.
     *
     * Nature is not lost: classes 6 and 7 are the income statement, 8 aggregates
     * them into the soldes intermédiaires de gestion, 9 is off-balance-sheet,
     * and for classes 1–5 the balance-sheet side follows `normal_balance_side`
     * — a debit-side class 3 is client lending, a credit-side class 3 is client
     * deposits.
     *
     * Nine classes, per the institution's own "Plan des Comptes" (extrait du
     * manuel de procédures comptables): hors bilan is class **9**, not 8. Class
     * 8 was previously modelled as hors bilan, which left the 136 class-9
     * accounts of that chart with nowhere correct to go.
     */

    /** Class 1 — Comptes de capitaux permanents. */
    public const ACCOUNT_CLASS_CAPITAUX_PERMANENTS = 'capitaux_permanents';

    /** Class 2 — Comptes de valeurs immobilisées. */
    public const ACCOUNT_CLASS_VALEURS_IMMOBILISEES = 'valeurs_immobilisees';

    /** Class 3 — Comptes d'opérations avec la clientèle. */
    public const ACCOUNT_CLASS_OPERATIONS_CLIENTELE = 'operations_clientele';

    /** Class 4 — Comptes de tiers. */
    public const ACCOUNT_CLASS_TIERS = 'tiers';

    /** Class 5 — Comptes de trésorerie et d'opérations interbancaires. */
    public const ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE = 'tresorerie_interbancaire';

    /** Class 6 — Comptes de charges. */
    public const ACCOUNT_CLASS_CHARGES = 'charges';

    /** Class 7 — Comptes de produits. */
    public const ACCOUNT_CLASS_PRODUITS = 'produits';

    /**
     * Class 8 — Soldes intermédiaires de gestion (PNF, résultat d'exploitation,
     * résultat courant …). Functional groupings the income statement is built
     * from, so these carry no movements of their own.
     */
    public const ACCOUNT_CLASS_SOLDES_INTERMEDIAIRES_GESTION = 'soldes_intermediaires_gestion';

    /** Class 9 — Comptes de hors bilan. */
    public const ACCOUNT_CLASS_HORS_BILAN = 'hors_bilan';

    public const NORMAL_BALANCE_DEBIT = 'debit';

    public const NORMAL_BALANCE_CREDIT = 'credit';

    /** Grouping account owned by the institution; consolidates agency detail accounts. */
    public const SCOPE_INSTITUTION = 'institution';

    /** Operational account owned by one agency; the only kind entries can post to. */
    public const SCOPE_AGENCY = 'agency';

    /**
     * The eight PCEMF classes, in class order (1 → 8).
     *
     * @return array<int, string>
     */
    public static function accountClasses(): array
    {
        return [
            self::ACCOUNT_CLASS_CAPITAUX_PERMANENTS,
            self::ACCOUNT_CLASS_VALEURS_IMMOBILISEES,
            self::ACCOUNT_CLASS_OPERATIONS_CLIENTELE,
            self::ACCOUNT_CLASS_TIERS,
            self::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
            self::ACCOUNT_CLASS_CHARGES,
            self::ACCOUNT_CLASS_PRODUITS,
            self::ACCOUNT_CLASS_SOLDES_INTERMEDIAIRES_GESTION,
            self::ACCOUNT_CLASS_HORS_BILAN,
        ];
    }

    /**
     * The PCEMF class number (1–9) this account's class corresponds to, which is
     * also the leading digit its code carries.
     */
    public function accountClassNumber(): ?int
    {
        $position = array_search($this->account_class, self::accountClasses(), true);

        return $position === false ? null : $position + 1;
    }

    /**
     * The class a code belongs to, read from its leading digit, or null when the
     * code does not start with a digit in the 1–9 range.
     *
     * The convention is not a preference: every one of the 1 430 accounts in the
     * institution's own "Plan des Comptes" classifies this way, and `571901` is
     * therefore a class 5 account and can never be class 4. Enforcing it turns a
     * mistyped class — which used to be unrecoverable, because the code it
     * occupies comes from the regulated chart and cannot be reinvented — into an
     * error caught at the moment of entry.
     */
    public static function classImpliedByCode(string $code): ?string
    {
        $leading = substr($code, 0, 1);
        if ($leading === '' || ! ctype_digit($leading)) {
            return null;
        }

        return self::accountClasses()[((int) $leading) - 1] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
        ];
    }

    public function isInstitutionLevel(): bool
    {
        return $this->agency_id === null;
    }

    public function accountScope(): string
    {
        return $this->isInstitutionLevel() ? self::SCOPE_INSTITUTION : self::SCOPE_AGENCY;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    /** @return HasMany<self, $this> */
    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return HasMany<EmfLedgerAccountMapping, $this> */
    public function emfMappings(): HasMany
    {
        return $this->hasMany(EmfLedgerAccountMapping::class);
    }
}
