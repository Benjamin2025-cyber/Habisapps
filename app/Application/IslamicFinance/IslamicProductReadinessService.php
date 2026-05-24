<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use Carbon\CarbonInterface;

final class IslamicProductReadinessService
{
    public function __construct(
        private readonly IslamicStandardsBaselineService $baseline,
    ) {}

    /**
     * @return array<int, string>
     */
    public function activationFailures(object $product, ?CarbonInterface $asOf = null): array
    {
        $contractType = isset(((array) $product)['contract_type']) ? ((array) $product)['contract_type'] : null;
        if (! is_string($contractType) || $contractType === '') {
            return ['Product contract type is missing.'];
        }

        $family = $this->productFamilyForContractType($contractType);
        if ($family === null) {
            return ['Product contract type does not map to a supported Islamic product family.'];
        }

        return $this->baseline->activationFailuresForProductFamily($family, $asOf);
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
