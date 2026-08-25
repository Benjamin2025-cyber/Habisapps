<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Client;
use App\Models\LedgerAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens the client's own divisionary GL account at account opening.
 *
 * « Nous sollicitions que le numéro du client soit directement son compte
 * comptable parce que c'est lui qui sera utilisé pour passer des écritures dans
 * son compte. » The client number (CLI000123) becomes the code of a divisionary
 * account under the control ledger the account resolves to — explicit choice or
 * product default — so every entry touching this customer account posts against
 * a code the accountant already has on paper, without looking anything up.
 *
 * The chosen/product ledger stops receiving the postings directly: it is their
 * control account, and the divisionaries consolidate into it through the
 * ordinary hierarchy, exactly like the loan dossiers.
 *
 * One client can hold several accounts (savings, current, guarantee…). Their
 * controls differ in the chart, but codes are unique per agency, so only one
 * account may carry the bare client number; the rest embed it —
 * `CLI000123.ACC00000001` — keeping the client's number as the leading,
 * recognizable part of every code they own.
 */
final class OpenCustomerLedgerAccount
{
    public function open(Client $client, LedgerAccount $control, int $agencyId, string $accountNumber): LedgerAccount
    {
        $reference = $client->client_reference;

        // A twin opened moments ago for the same client under the same control
        // is adopted, not duplicated.
        $own = $this->find($agencyId, $reference, $control->id);
        if ($own instanceof LedgerAccount) {
            return $own;
        }

        try {
            return $this->createInSavepoint($client, $control, $agencyId, $reference);
        } catch (UniqueConstraintViolationException) {
            // The failed insert rolled back to its savepoint, so these lookups
            // are safe even inside an enclosing transaction.
            $adopted = $this->find($agencyId, $reference, $control->id);
            if ($adopted instanceof LedgerAccount) {
                return $adopted;
            }

            // The bare number belongs to another control of this same client:
            // fall back to the embedded form.
            $suffixed = $reference.'.'.$accountNumber;
            $existing = $this->find($agencyId, $suffixed);
            if ($existing instanceof LedgerAccount) {
                return $existing;
            }

            try {
                return $this->createInSavepoint($client, $control, $agencyId, $suffixed);
            } catch (UniqueConstraintViolationException $exception) {
                $adopted = $this->find($agencyId, $suffixed);
                if (! $adopted instanceof LedgerAccount) {
                    throw $exception;
                }

                return $adopted;
            }
        }
    }

    /**
     * The insert runs inside its own transaction: with an enclosing
     * transaction already open (the test harness, a caller's own) Laravel
     * issues a savepoint, so a code collision rolls back to it instead of
     * leaving the surrounding transaction aborted.
     */
    private function createInSavepoint(Client $client, LedgerAccount $control, int $agencyId, string $code): LedgerAccount
    {
        return DB::transaction(function () use ($client, $control, $agencyId, $code): LedgerAccount {
            // Parent-scoped: the same code under another control belongs to a
            // different balance sheet line and must not be adopted here.
            $existing = $this->find($agencyId, $code, $control->id);
            if ($existing instanceof LedgerAccount) {
                return $existing;
            }

            return $this->create($client, $control, $agencyId, $code);
        });
    }

    private function find(int $agencyId, string $code, ?int $parentId = null): ?LedgerAccount
    {
        $query = LedgerAccount::query()
            ->where('agency_id', $agencyId)
            ->where('code', $code);

        if ($parentId !== null) {
            $query->where('parent_account_id', $parentId);
        }

        return $query->first();
    }

    private function create(Client $client, LedgerAccount $control, int $agencyId, string $code): LedgerAccount
    {
        $name = 'Client '.$client->client_reference;
        $lastName = trim($client->last_name);
        $firstName = trim($client->first_name);
        if ($lastName !== '' || $firstName !== '') {
            $name .= ' – '.strtoupper($lastName).($firstName !== '' ? ' '.ucfirst($firstName) : '');
        }

        return LedgerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => $code,
            'name' => $name,
            'account_class' => $control->account_class,
            'account_type' => $control->account_type,
            'is_postable' => true,
            'parent_account_id' => $control->id,
            'normal_balance_side' => $control->normal_balance_side,
            'status' => LedgerAccount::STATUS_ACTIVE,
        ]);
    }
}
