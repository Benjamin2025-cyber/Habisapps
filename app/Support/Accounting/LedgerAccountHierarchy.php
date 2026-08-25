<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * Parent/child navigation over the consolidated chart of accounts.
 *
 * A grouping account (institution-level, or an agency account with children)
 * holds no movements of its own: its balance is the sum of the detail accounts
 * beneath it. Both consolidated balances and the consolidated trial balance
 * need the same subtree resolution, so it lives here once.
 *
 * The chart is read once per instance. Charts of accounts are small (hundreds
 * to a few thousand rows) and resolving the tree in PHP keeps this cycle-safe
 * without recursive SQL.
 */
final class LedgerAccountHierarchy
{
    /** @var array<int, int|null>|null parent id keyed by account id */
    private ?array $parents = null;

    /** @var array<int, array<int, int>>|null child id lists keyed by parent id */
    private ?array $children = null;

    /** @var array<int, array<int, int>> memoised inclusive subtrees keyed by root id */
    private array $subtrees = [];

    /**
     * Ids of the account itself plus every account beneath it.
     *
     * @return array<int, int>
     */
    public function subtreeIds(int $accountId): array
    {
        if (array_key_exists($accountId, $this->subtrees)) {
            return $this->subtrees[$accountId];
        }

        $this->load();

        $ids = [];
        $seen = [];
        $stack = [$accountId];
        while ($stack !== []) {
            $current = array_pop($stack);
            // A parent cycle is rejected by the API and by the not-self-parent
            // check constraint, but guarding here keeps a corrupt row from
            // turning a report into an infinite loop.
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $ids[] = $current;
            foreach ($this->children[$current] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return $this->subtrees[$accountId] = $ids;
    }

    /**
     * Whether anything hangs beneath this account.
     *
     * Having children is what makes an account a grouping account, and it is
     * independent of `is_postable`: a control that operations still post to
     * directly can also carry per-dossier divisionaries beneath it. Its balance
     * is then its own movements plus theirs, which is what `subtreeIds()`
     * returns.
     */
    public function hasChildren(int $accountId): bool
    {
        $this->load();

        return ($this->children[$accountId] ?? []) !== [];
    }

    /**
     * Inclusive subtree ids for every account in the chart.
     *
     * @return array<int, array<int, int>>
     */
    public function subtreeMap(): array
    {
        $map = [];
        foreach (array_keys($this->parentMap()) as $accountId) {
            $map[$accountId] = $this->subtreeIds($accountId);
        }

        return $map;
    }

    /**
     * @return array<int, int|null> parent id keyed by account id
     */
    public function parentMap(): array
    {
        $this->load();

        /** @var array<int, int|null> $parents */
        $parents = $this->parents;

        return $parents;
    }

    private function load(): void
    {
        if ($this->parents !== null) {
            return;
        }

        $parents = [];
        $children = [];
        foreach (DB::table('ledger_accounts')->get(['id', 'parent_account_id']) as $row) {
            $id = (int) $row->id;
            $parentId = $row->parent_account_id === null ? null : (int) $row->parent_account_id;

            $parents[$id] = $parentId;
            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        $this->parents = $parents;
        $this->children = $children;
    }
}
