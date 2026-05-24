<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class IslamicProductReadinessService
{
    public function __construct(
        private readonly IslamicStandardsBaselineService $baseline,
        private readonly IslamicRegulatorySignoffService $signoff,
        private readonly IslamicScreeningPolicyService $screening,
    ) {}

    /**
     * Failures keyed by gate identifier so callers can preserve structure in API responses.
     *
     * @return array<string, array<int, string>>
     */
    public function activationFailures(object $product, ?CarbonInterface $asOf = null, ?User $actor = null): array
    {
        $contractType = isset(((array) $product)['contract_type']) ? ((array) $product)['contract_type'] : null;
        if (! is_string($contractType) || $contractType === '') {
            return ['islamic_product' => ['Product contract type is missing.']];
        }

        $family = $this->productFamilyForContractType($contractType);
        if ($family === null) {
            return ['islamic_product' => ['Product contract type does not map to a supported Islamic product family.']];
        }

        $failures = [];

        $standardsFailures = $this->baseline->activationFailuresForProductFamily($family, $asOf);
        if ($standardsFailures !== []) {
            $failures['islamic_standards_baseline'] = $standardsFailures;
        }

        $signoffFailures = $this->signoff->activationFailuresForProductFamily($family, $asOf);
        if ($signoffFailures !== []) {
            $failures['islamic_regulatory_signoff'] = $signoffFailures;
        }

        $scopeValue = isset(((array) $product)['agency_id']) && is_numeric(((array) $product)['agency_id'])
            ? (string) ((int) ((array) $product)['agency_id'])
            : null;
        $rulesRaw = ((array) $product)['rules'] ?? null;
        $rules = [];
        if (is_string($rulesRaw) && $rulesRaw !== '') {
            $decoded = json_decode($rulesRaw, true);
            if (is_array($decoded)) {
                $rules = $decoded;
            }
        }
        $facts = [
            'scope_type' => 'product_family',
            'scope_value' => $family,
            'sector_codes' => is_array($rules['sector_codes'] ?? null) ? $rules['sector_codes'] : [],
            'goods_codes' => is_array($rules['goods_codes'] ?? null) ? $rules['goods_codes'] : [],
            'supplier_flags' => is_array($rules['supplier_flags'] ?? null) ? $rules['supplier_flags'] : [],
            'customer_business_flags' => is_array($rules['customer_business_flags'] ?? null) ? $rules['customer_business_flags'] : [],
            'source_of_funds_flags' => is_array($rules['source_of_funds_flags'] ?? null) ? $rules['source_of_funds_flags'] : [],
            'use_of_funds_flags' => is_array($rules['use_of_funds_flags'] ?? null) ? $rules['use_of_funds_flags'] : [],
            'agency_scope_value' => $scopeValue,
        ];
        $hasPolicyRegistry = DB::table('islamic_screening_policies')->exists();
        $screening = $this->screening->evaluate(
            subjectType: IslamicApprovalStateMachine::SUBJECT_PRODUCT,
            subjectPublicId: isset(((array) $product)['public_id']) && is_string(((array) $product)['public_id']) ? ((array) $product)['public_id'] : '',
            contextType: 'product_approval',
            facts: $facts,
            actor: $actor,
            strictPolicy: $hasPolicyRegistry,
        );
        if (in_array($screening['result'], ['fail', 'manual_review', 'expired'], true)) {
            $messages = ['Screening policy returned '.$screening['result'].'.'];
            if (is_string($screening['block_reason'] ?? null) && $screening['block_reason'] !== '') {
                $messages[] = (string) $screening['block_reason'];
            }
            if (is_string($screening['review_case_public_id'] ?? null) && $screening['review_case_public_id'] !== '') {
                $messages[] = 'review_case_public_id='.$screening['review_case_public_id'];
            }
            $failures['islamic_screening_policy'] = $messages;
        }

        return $failures;
    }

    private function productFamilyForContractType(string $contractType): ?string
    {
        $map = [
            'murabaha' => 'mourabaha',
            'mourabaha' => 'mourabaha',
            'ijara' => 'ijara',
            'ijara_wa_iqtina' => 'ijara_wa_iqtina',
            'salam' => 'salam',
            'istisnaa' => 'istisnaa',
            'moudaraba' => 'moudaraba',
            'moucharaka' => 'moucharaka',
        ];

        return $map[$contractType] ?? null;
    }
}
