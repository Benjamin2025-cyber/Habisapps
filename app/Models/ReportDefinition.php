<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'code', 'name', 'report_type', 'module', 'status', 'definition'])]
final class ReportDefinition extends Model
{
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string TYPE_TRIAL_BALANCE = 'trial_balance';

    public const string TYPE_GENERAL_LEDGER = 'general_ledger';

    public const string TYPE_EMF_TRIAL_BALANCE = 'emf_trial_balance';

    public const string TYPE_CREDIT_PORTFOLIO_OUTSTANDING = 'credit_portfolio_outstanding';

    public const string TYPE_CREDIT_PAR_DELINQUENCY = 'credit_par_delinquency';

    public const string TYPE_CREDIT_COLLECTION_PERFORMANCE = 'credit_collection_performance';

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

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    /** @return HasMany<ReportRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }
}
