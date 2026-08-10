<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * The PCEMF soldes intermédiaires de gestion — classes 80 to 87.
 *
 * These are not ledger accounts. The accounting team's answers of 2026-08-09 and
 * 2026-08-10 are explicit on both halves of that:
 *
 *   « Les comptes 80 à 87 ne doivent pas être créés comme de vrais comptes où
 *     l'on enregistre des écritures. Ce sont des totaux calculés automatiquement
 *     à partir des classes 6 (charges) et 7 (produits) au moment de sortir le
 *     compte de résultat. »
 *
 *   « "Aucun compte de classe 8 n'existe dans le paramétrage" ne veut pas dire
 *     que la classe 8 est absente du plan comptable — elle y figure bien, avec
 *     ses intitulés comme les autres classes. […] La formule de calcul de chaque
 *     SIG doit quand même être paramétrée quelque part dans le progiciel, au
 *     niveau du module qui édite le compte de résultat. »
 *
 * So the class exists, nothing posts to it, and this is the "quelque part": the
 * eight formulas, in one place, as data rather than as arithmetic buried in a
 * report builder. Each term names a code prefix; every account whose code starts
 * with it is summed.
 *
 * The sign is never imposed. A résultat is bénéfice at credit and perte at
 * debit, decided by the figure at each arrêté — the same rule as the bivalent
 * accounts, and the reason none of these carries a normal side.
 */
final class SoldesIntermediairesDeGestion
{
    /**
     * Composition of each solde, confirmed by the accounting team on 2026-08-10.
     *
     * `plus` and `minus` hold code prefixes summed into the solde; `from` holds
     * other soldes carried forward. A solde is computed after everything it
     * refers to, which the declaration order guarantees.
     *
     * Returned as a list with an explicit `code`, not keyed by code: PHP casts
     * a numeric-string array key to an integer, so a definitions() keyed by '80'
     * hands back the integer 80 and every string comparison against it fails.
     *
     * @return array<int, array{code: string, label: string, from: array<int, string>, plus: array<int, string>, minus: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => '80',
                'label' => 'Produit net financier (PNF)',
                'from' => [],
                'plus' => ['70', '71', '72', '73'],
                'minus' => ['60', '61', '62', '63'],
            ],
            [
                'code' => '81',
                'label' => "Produit d'exploitation global",
                'from' => ['80'],
                // Confirmed: a subvention d'exploitation funds the activity
                // rather than earning interest, so it belongs with the other
                // produits d'exploitation and not in the PNF.
                'plus' => ['74', '75', '76'],
                'minus' => ['64'],
            ],
            [
                'code' => '82',
                'label' => "Résultat d'exploitation",
                'from' => ['81'],
                // 6611 is added back, not omitted. The whole of class 66 is
                // subtracted as usual, then the corporate income tax alone is
                // returned, because it belongs to solde 86 and would otherwise
                // be counted twice. Every other direct tax — patente, taxe
                // foncière, now under 6612 — stays inside the résultat
                // d'exploitation, which is the reason for the split.
                'plus' => ['78', '79', '6611'],
                // Confirmed: provisions for credit risk and losses on
                // irrecoverable receivables are the ordinary business of a
                // lender, not an exceptional event, so 69 and 79 belong here.
                'minus' => ['65', '66', '68', '69'],
            ],
            [
                'code' => '83',
                'label' => 'Résultat courant',
                // Confirmed equal to 82: « il n'y a pas de résultat financier
                // séparé à ajouter entre le 82 et le 83 : la partie financière
                // est déjà comptée tout en haut, dans le 80. »
                'from' => ['82'],
                'plus' => [],
                'minus' => [],
            ],
            [
                'code' => '84',
                'label' => 'Résultat exceptionnel',
                'from' => [],
                'plus' => ['77'],
                'minus' => ['67'],
            ],
            [
                'code' => '85',
                'label' => 'Résultat avant impôt',
                'from' => ['83', '84'],
                'plus' => [],
                'minus' => [],
            ],
            [
                'code' => '86',
                'label' => 'Impôts sur le résultat',
                'from' => [],
                // Only the corporate income tax, which is why 6611 had to exist:
                // 661 also carries other direct taxes, so using it here would
                // have charged those to the tax line as well.
                'plus' => ['6611'],
                'minus' => [],
            ],
            [
                'code' => '87',
                'label' => 'Résultat net avant certification',
                'from' => ['85'],
                'plus' => [],
                'minus' => ['86'],
            ],
        ];
    }

    /**
     * Where the net result is carried at year end: 131 when it is a profit, 132
     * when it is a loss. Confirmed 2026-08-10 — « le résultat du 87 doit être
     * transféré dans le 131 s'il est positif (bénéfice) ou dans le 132 s'il est
     * négatif (perte) ». The chart keeps two accounts rather than one signed
     * account, so the sign chooses the destination.
     */
    public const RESULT_ACCOUNT_PROFIT = '131';

    public const RESULT_ACCOUNT_LOSS = '132';

    /** The solde carried to 131/132. */
    public const NET_RESULT = '87';

    public static function resultAccountFor(int $netResultMinor): string
    {
        return $netResultMinor < 0 ? self::RESULT_ACCOUNT_LOSS : self::RESULT_ACCOUNT_PROFIT;
    }

    /**
     * Every code prefix the definitions read from the chart, deduplicated. Used
     * to check the formulas against the chart: a term naming a prefix no account
     * carries would silently contribute zero for the life of the report.
     *
     * @return array<int, string>
     */
    public static function referencedCodePrefixes(): array
    {
        $soldeCodes = array_column(self::definitions(), 'code');

        // Collected as a list, not as array keys: '70' used as a key becomes the
        // integer 70, and the strings would come back out as integers — the same
        // silent cast that made definitions() a list.
        $prefixes = [];
        foreach (self::definitions() as $definition) {
            foreach ([...$definition['plus'], ...$definition['minus']] as $term) {
                // Soldes refer to each other through `from`, and 87 subtracts 86,
                // which is a solde rather than a chart code.
                if (in_array($term, $soldeCodes, true) || in_array($term, $prefixes, true)) {
                    continue;
                }

                $prefixes[] = $term;
            }
        }

        return $prefixes;
    }
}
