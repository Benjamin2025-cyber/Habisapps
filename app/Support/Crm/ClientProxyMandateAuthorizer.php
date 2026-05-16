<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Models\ClientProxy;
use App\Models\CustomerAccount;
use DateTimeInterface;

final class ClientProxyMandateAuthorizer
{
    public function allows(
        ClientProxy $proxy,
        CustomerAccount $account,
        string $operationType,
        int $amountMinor,
        string $currency,
        ?DateTimeInterface $onDate = null,
    ): bool {
        $date = $onDate ?? now();

        if ($proxy->status !== ClientProxy::STATUS_ACTIVE) {
            return false;
        }

        if ($proxy->verification_status !== ClientProxy::VERIFICATION_VERIFIED) {
            return false;
        }

        if ($proxy->agency_id !== $account->agency_id || $proxy->client_id !== $account->client_id) {
            return false;
        }

        if ($proxy->customer_account_id !== null && $proxy->customer_account_id !== $account->id) {
            return false;
        }

        if ($this->isBeforeStart($proxy, $date) || $this->isAfterEnd($proxy, $date)) {
            return false;
        }

        $operationTypes = $proxy->operation_types;
        if (is_array($operationTypes) && $operationTypes !== [] && ! in_array($operationType, $operationTypes, true)) {
            return false;
        }

        if ($proxy->max_amount_minor !== null) {
            if ($proxy->limit_currency !== $currency) {
                return false;
            }

            if ($amountMinor > $proxy->max_amount_minor) {
                return false;
            }
        }

        return true;
    }

    private function isBeforeStart(ClientProxy $proxy, DateTimeInterface $date): bool
    {
        return $proxy->starts_on instanceof DateTimeInterface
            && $date < $proxy->starts_on;
    }

    private function isAfterEnd(ClientProxy $proxy, DateTimeInterface $date): bool
    {
        return $proxy->ends_on instanceof DateTimeInterface
            && $date > $proxy->ends_on;
    }
}
