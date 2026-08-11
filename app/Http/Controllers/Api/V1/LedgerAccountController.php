<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLedgerAccountRequest;
use App\Http\Requests\UpdateLedgerAccountRequest;
use App\Http\Resources\LedgerAccountCollection;
use App\Http\Resources\LedgerAccountResource;
use App\Models\Agency;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LedgerAccountController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[QueryParameter('agency_public_id', 'Limit the list to one agency chart. The actor must be allowed to read that agency.', type: 'string')]
    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{ledger_accounts: array<int, \App\Http\Resources\LedgerAccountResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): LedgerAccountCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', LedgerAccount::class)) {
            return $this->respondForbidden();
        }

        $query = LedgerAccount::query()->with(['agency', 'parentAccount'])->latest();

        if (! $actor->hasRole('platform-admin') && ! $actor->can('ledger.scope.institution.read')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->where(function ($builder) use ($agencyId): void {
                $builder->where('agency_id', $agencyId)->orWhere('agency_id', null);
            });
        }

        // Account pickers know the agency of the document they are editing.
        // Apply that scope before pagination; otherwise the first page can be
        // made entirely of institution grouping accounts, which are correctly
        // removed by the picker and leave an apparently empty list.
        $agencyPublicId = $request->query('agency_public_id');
        if (is_string($agencyPublicId) && trim($agencyPublicId) !== '') {
            $agency = Agency::query()->where('public_id', trim($agencyPublicId))->first();
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.staff_selected_agency_invalid')]]);
            }

            if (! $actor->hasRole('platform-admin')
                && ! $actor->can('ledger.scope.institution.read')
                && $this->staffAgencyScope->currentAgencyId($actor) !== $agency->id) {
                return $this->respondForbidden('You can only read the ledger chart for your current agency.');
            }

            $query->where('agency_id', $agency->id);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('code', 'ilike', '%'.$term.'%')
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('account_class', 'ilike', '%'.$term.'%')
                    ->orWhere('account_type', 'ilike', '%'.$term.'%')
                    ->orWhere('normal_balance_side', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%');
            });
        }

        return new LedgerAccountCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{ledger_account: \App\Http\Resources\LedgerAccountResource}, errors: null, meta: null}')]
    public function store(StoreLedgerAccountRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $scope = $request->filled('scope')
            ? $request->string('scope')->toString()
            : LedgerAccount::SCOPE_AGENCY;
        $institutionScoped = $scope === LedgerAccount::SCOPE_INSTITUTION;

        $agency = null;
        if ($institutionScoped) {
            // The institution chart of accounts governs every agency below it,
            // so minting grouping accounts is an institution-control action.
            if (! $this->canManageInstitutionScope($actor)) {
                return $this->respondForbidden();
            }
            if ($request->filled('agency_public_id')) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.ledger_institution_account_has_no_agency')]]);
            }
            if ($request->has('is_postable') && $request->boolean('is_postable')) {
                return $this->respondUnprocessable(errors: ['is_postable' => [__('domain.ledger_institution_account_not_postable')]]);
            }
        } else {
            if ($request->filled('agency_public_id')) {
                $agency = Agency::query()->where('public_id', $request->string('agency_public_id'))->first();
                if (! $agency instanceof Agency) {
                    return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.staff_selected_agency_invalid')]]);
                }
            } elseif (! $actor->hasRole('platform-admin')) {
                $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
                $agency = $agencyId !== null ? Agency::query()->find($agencyId) : null;
            }

            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.ledger_agency_account_requires_agency')]]);
            }
            // An explicit agency_public_id must still be one the actor may write
            // to. Without this an agency accountant could file accounts into
            // another agency's chart just by naming it.
            if (! $this->canCreateInAgency($actor, $agency)) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.ledger_agency_outside_actor_scope')]]);
            }
        }

        $code = $request->string('code')->toString();
        $classError = $this->accountClassCodeMismatch($code, $request->string('account_class')->toString());
        if ($classError !== null) {
            return $this->respondUnprocessable(errors: ['account_class' => [$classError]]);
        }

        // Class 8 is in the chart, with its intitulés, like every other class —
        // but as the accounting team put it, « aucun compte de la classe 8 n'est
        // créé dans la table des comptes sur lesquels on peut saisir une
        // écriture, parce qu'on n'y saisit jamais rien directement ». The eight
        // soldes are computed from classes 6 and 7 when the compte de résultat is
        // drawn.
        //
        // The seeder honours that and is tested for it, but it is not the only
        // way an account appears. A class 8 account created here would be a place
        // to file entries that the income statement never reads, because it sums
        // classes 6 and 7 — so those amounts would vanish from the résultat while
        // still balancing perfectly in the journal. Nothing downstream would look
        // wrong.
        if (LedgerAccount::classImpliedByCode($code) === LedgerAccount::ACCOUNT_CLASS_SOLDES_INTERMEDIAIRES_GESTION) {
            return $this->respondUnprocessable(errors: ['code' => [__('domain.ledger_class_eight_is_computed')]]);
        }

        if ($this->codeTakenInScope($code, $agency?->id)) {
            return $this->respondUnprocessable(errors: ['code' => [
                $agency instanceof Agency
                    ? __('domain.ledger_account_code_taken_in_agency', ['agency' => $agency->name])
                    : __('domain.ledger_account_code_taken_at_institution'),
            ]]);
        }

        $parent = null;
        if ($request->filled('parent_account_public_id')) {
            $parent = LedgerAccount::query()->where('public_id', $request->string('parent_account_public_id'))->first();
            if (! $parent instanceof LedgerAccount) {
                return $this->respondUnprocessable(errors: ['parent_account_public_id' => [__('The selected parent account is invalid.')]]);
            }

            $parentError = $this->parentError($agency?->id, $parent);
            if ($parentError !== null) {
                return $this->respondUnprocessable(errors: ['parent_account_public_id' => [$parentError]]);
            }
        } else {
            $parent = $this->institutionParentImpliedByCode($code);
        }

        try {
            $ledgerAccount = LedgerAccount::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agency?->id,
                'code' => $code,
                'name' => $request->string('name')->toString(),
                'account_class' => $request->string('account_class')->toString(),
                'account_type' => $request->input('account_type'),
                'is_postable' => ! $institutionScoped && $request->boolean('is_postable', true),
                'parent_account_id' => $parent?->id,
                // Null, not '': a bivalent account has no side, and ->string()
                // would coerce that to an empty string the column would accept
                // while meaning nothing.
                'normal_balance_side' => $this->normalBalanceSideInput($request->input('normal_balance_side')),
                'status' => $request->input('status', LedgerAccount::STATUS_ACTIVE),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The check above answers the ordinary case; this closes the window
            // between it and the insert. Reaching the partial unique index must
            // still read as a validation error, never as a 500 leaking the SQL.
            return $this->respondUnprocessable(errors: ['code' => [
                $agency instanceof Agency
                    ? __('domain.ledger_account_code_taken_in_agency', ['agency' => $agency->name])
                    : __('domain.ledger_account_code_taken_at_institution'),
            ]]);
        }

        if ($parent instanceof LedgerAccount) {
            $this->convertToGroupingAccount($parent, $actor, $request);
        }

        $this->securityAudit->record('ledger.account.created', actor: $actor, subject: $ledgerAccount, properties: [
            'code' => $ledgerAccount->code,
            'scope' => $ledgerAccount->accountScope(),
            'agency_public_id' => $agency?->public_id,
        ], request: $request);

        return $this->respondCreated(
            LedgerAccountResource::make($ledgerAccount->loadMissing(['agency', 'parentAccount'])),
            'Ledger account created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{ledger_account: \App\Http\Resources\LedgerAccountResource}, errors: null, meta: null}')]
    public function show(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $ledgerAccount)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(LedgerAccountResource::make($ledgerAccount->loadMissing(['agency', 'parentAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{ledger_account: \App\Http\Resources\LedgerAccountResource}, errors: null, meta: null}')]
    public function update(UpdateLedgerAccountRequest $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $request->validated();
        $newParent = null;

        // Correcting the class or the normal balance side of an account that has
        // never been used is a referential fix, not an accounting event — a
        // mistyped class must not strand its code forever, because the codes come
        // from PCEMF and cannot be invented. Once the account carries movements
        // both are frozen: reclassifying retroactively would restate the balance
        // sheet, and the accounting answer is a new account plus a transfer entry
        // (écriture de reclassement), not an edit here.
        foreach (['account_class', 'normal_balance_side'] as $structural) {
            if (! array_key_exists($structural, $validated)) {
                continue;
            }
            if ($validated[$structural] === $ledgerAccount->{$structural}) {
                unset($validated[$structural]);

                continue;
            }
            if (DB::table('journal_lines')->where('ledger_account_id', $ledgerAccount->id)->exists()) {
                return $this->respondUnprocessable(errors: [$structural => [
                    __('domain.ledger_account_structure_locked_by_movements'),
                ]]);
            }
        }

        if (array_key_exists('account_class', $validated) && is_string($validated['account_class'])) {
            $classError = $this->accountClassCodeMismatch($ledgerAccount->code, $validated['account_class']);
            if ($classError !== null) {
                return $this->respondUnprocessable(errors: ['account_class' => [$classError]]);
            }
        }

        if (array_key_exists('parent_account_public_id', $validated)) {
            if ($validated['parent_account_public_id'] !== null) {
                $newParent = LedgerAccount::query()->where('public_id', $validated['parent_account_public_id'])->first();
                if (! $newParent instanceof LedgerAccount) {
                    return $this->respondUnprocessable(errors: ['parent_account_public_id' => [__('The selected parent account is invalid.')]]);
                }
                if ($newParent->id === $ledgerAccount->id) {
                    return $this->respondUnprocessable(errors: ['parent_account_public_id' => [__('The parent account cannot reference itself.')]]);
                }

                $parentError = $this->parentError($ledgerAccount->agency_id, $newParent);
                if ($parentError !== null) {
                    return $this->respondUnprocessable(errors: ['parent_account_public_id' => [$parentError]]);
                }

                $ancestor = $newParent->parentAccount;
                while ($ancestor instanceof LedgerAccount) {
                    if ($ancestor->id === $ledgerAccount->id) {
                        return $this->respondUnprocessable(errors: ['parent_account_public_id' => [__('The selected parent account would create a cycle.')]]);
                    }
                    $ancestor = $ancestor->parentAccount;
                }
            }

            $ledgerAccount->parent_account_id = $newParent?->id;
            unset($validated['parent_account_public_id']);
        }

        if (array_key_exists('is_postable', $validated)) {
            $postable = $request->boolean('is_postable');
            if ($postable && $ledgerAccount->isInstitutionLevel()) {
                return $this->respondUnprocessable(errors: ['is_postable' => [__('domain.ledger_institution_account_not_postable')]]);
            }
            if ($postable && DB::table('ledger_accounts')->where('parent_account_id', $ledgerAccount->id)->exists()) {
                return $this->respondUnprocessable(errors: ['is_postable' => [__('domain.ledger_grouping_account_not_postable')]]);
            }

            $validated['is_postable'] = $postable;
        }

        $ledgerAccount->fill($validated);
        $ledgerAccount->save();

        if ($newParent instanceof LedgerAccount) {
            $this->convertToGroupingAccount($newParent, $actor, $request);
        }

        return $this->respondSuccess(
            LedgerAccountResource::make($ledgerAccount->loadMissing(['agency', 'parentAccount'])),
            'Ledger account updated successfully'
        );
    }

    public function destroy(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if ($actor instanceof User && $actor->can('delete', $ledgerAccount)) {
            $ledgerAccount->update(['status' => LedgerAccount::STATUS_ARCHIVED]);

            return $this->respondSuccess(message: 'Ledger account archived successfully');
        }

        return $this->respondForbidden();
    }

    private function canManageInstitutionScope(User $actor): bool
    {
        return $actor->hasRole('platform-admin') || $actor->can('ledger.scope.institution.manage');
    }

    /**
     * Institution-scope holders may build any agency's chart — that is what
     * deploying the institution chart across agencies requires. Everyone else
     * writes only into the agency they are assigned to.
     */
    /**
     * The requested normal side, or null for a bivalent account.
     *
     * Validation has already restricted this to 'debit', 'credit' or null; this
     * only keeps an empty string from reaching a column where it would be
     * indistinguishable from a real value.
     */
    private function normalBalanceSideInput(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Why the class contradicts the code, or null when they agree.
     *
     * Consulted only where the class is being set, so a code that does not start
     * with a digit — or an account being edited for some other reason — is never
     * retroactively blocked.
     */
    private function accountClassCodeMismatch(string $code, string $accountClass): ?string
    {
        $implied = LedgerAccount::classImpliedByCode($code);
        if ($implied === null || $implied === $accountClass) {
            return null;
        }

        return __('domain.ledger_account_class_contradicts_code', [
            'digit' => substr($code, 0, 1),
            'expected' => __('domain.ledger_account_class_'.$implied),
        ]);
    }

    /**
     * Whether the code is already used in the namespace the account would join.
     *
     * Codes are unique per agency chart, and once more across the institution
     * level — two partial unique indexes rather than one, because Postgres
     * treats NULL `agency_id` values as distinct and would otherwise allow a
     * second institution-level `571000`.
     */
    private function codeTakenInScope(string $code, ?int $agencyId): bool
    {
        $query = DB::table('ledger_accounts')->where('code', $code);

        return ($agencyId === null
            ? $query->whereNull('agency_id')
            : $query->where('agency_id', $agencyId)
        )->exists();
    }

    private function canCreateInAgency(User $actor, Agency $agency): bool
    {
        if ($this->canManageInstitutionScope($actor)) {
            return true;
        }

        return $this->staffAgencyScope->currentAgencyId($actor) === $agency->id;
    }

    /**
     * Why $parent cannot group an account belonging to $childAgencyId, or null
     * when the link is valid.
     *
     * A consolidated chart of accounts flows one way: agency detail accounts
     * roll up into institution grouping accounts. Two agencies may therefore
     * share an institution parent, but never each other's accounts, and an
     * institution account is never grouped under a single agency.
     */
    private function parentError(?int $childAgencyId, LedgerAccount $parent): ?string
    {
        if ($childAgencyId === null && ! $parent->isInstitutionLevel()) {
            return __('domain.ledger_institution_parent_must_be_institution');
        }

        if ($childAgencyId !== null && ! $parent->isInstitutionLevel() && $parent->agency_id !== $childAgencyId) {
            return __('The selected parent account must belong to the same agency scope.');
        }

        if ($parent->is_postable && DB::table('journal_lines')->where('ledger_account_id', $parent->id)->exists()) {
            return __('domain.ledger_parent_has_movements');
        }

        return null;
    }

    /**
     * The institution grouping account a code belongs under, found by its
     * longest proper prefix — 578001 hangs off 578, or off 57 if 578 does not
     * exist. Mirrors PcemfChartSeeder::parentCodeOf(), so an account added
     * through the API lands where the seeded chart would have put it.
     *
     * The accounting team's answer to question 1 is that agency totals reach the
     * institution accounts « automatiquement ». That only holds if every detail
     * account is attached to the tree, and leaving the attachment to whoever
     * fills the form does not hold it: one skipped field silently keeps that
     * account's money out of the consolidated total, on every report, with
     * nothing on screen looking wrong. Deriving it costs one query and makes the
     * guarantee structural.
     *
     * Returns null when no ancestor exists — a new class root, which legitimately
     * sits at the top and must not be forced under an unrelated account.
     */
    private function institutionParentImpliedByCode(string $code): ?LedgerAccount
    {
        for ($length = strlen($code) - 1; $length >= 2; $length--) {
            $id = DB::table('ledger_accounts')
                ->where('code', substr($code, 0, $length))
                ->whereNull('agency_id')
                ->value('id');

            if ($id !== null) {
                return LedgerAccount::query()->whereKey($id)->first();
            }
        }

        return null;
    }

    /**
     * An account that gains a sub-account becomes a grouping account: its
     * balance is the consolidation of its children and it stops accepting
     * entries of its own.
     */
    private function convertToGroupingAccount(LedgerAccount $parent, User $actor, Request $request): void
    {
        if (! $parent->is_postable) {
            return;
        }

        $parent->update(['is_postable' => false]);

        $this->securityAudit->record('ledger.account.converted_to_grouping', actor: $actor, subject: $parent, properties: [
            'code' => $parent->code,
        ], request: $request);
    }
}
