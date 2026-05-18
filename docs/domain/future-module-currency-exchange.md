# Future Module: Currency Exchange

Stakeholder source: section 28, `Change de Devises`.

Terminology correction: this section must be understood as `echange de devises`, meaning a currency exchange service. It is not a generic request to make the whole platform multi-currency.

## Scope Boundary

This module is a counter service for buying and selling foreign currency.

In scope:

- published buy/sell exchange rates;
- customer identity capture for the exchange operation;
- foreign-currency cash stock held in a dedicated exchange till;
- currency exchange slips and register entries;
- margins/commissions earned on exchange operations;
- end-of-day reconciliation of foreign-currency cash stock;
- partner-bank replenishment or sale of foreign-currency stock.

Not in scope:

- customer savings accounts denominated in EUR, USD, or other foreign currencies;
- loans issued or repaid in foreign currencies;
- product catalogues priced in multiple currencies;
- platform-wide multi-currency balances;
- system-wide foreign-currency financial statements.

The implementation can still need currency-aware records inside this module, but that is limited support for currency exchange operations, not a full multi-currency banking platform.

## Stakeholder Intent

The stakeholder describes counter currency exchange performed immediately at the agency:

1. Customer presents identity document and currency.
2. Staff verifies identity and banknote authenticity.
3. Staff applies displayed buy/sell rate.
4. Transaction is recorded with customer name, currency, rate, and amount.
5. Currency exchange slip is generated.
6. Transaction is recorded in a currency exchange register.
7. End-of-day foreign currency stocks are reconciled.
8. Surpluses may be sold to a partner bank; shortages may be replenished from a partner bank.

Important stakeholder note:

- Currency exchange must use a separate foreign-currency till, not the main XAF till.

## Regulatory Boundary

Currency exchange is regulated. Discovery must validate requirements against BEAC/CEMAC rules before implementation. BEAC publishes CEMAC foreign exchange regulation material at:

- https://www.beac.int/p-des-changes/circulaires-et-decisions/circulaires_et_decisions-vf/

Do not implement production currency exchange until licensing, authorization, reporting, KYC, AML, cash-stock, and register requirements are confirmed.

## Domain Model

Recommended entities:

- `currencies`: ISO code, name, precision, status.
- `currency_exchange_tills`: agency, assigned user, status.
- `currency_exchange_till_balances`: till, currency, theoretical balance.
- `currency_exchange_rates`: currency pair, reference rate, buy margin, sell margin, buy rate, sell rate, effective date/time, status, approved by.
- `currency_exchange_transactions`: customer/client, identity document snapshot, direction, source currency, target currency, source amount, rate, margin, target amount, status, register number.
- `currency_exchange_slips`: generated slip metadata.
- `currency_exchange_stock_adjustments`: replenishment, sale to bank, shortage/overage correction.
- `currency_exchange_register_entries`: immutable operational register.

## Rate Rules

Stakeholder margin examples:

- Euro client purchase commission: 2%.
- Euro client sale commission: 5%.
- Other currencies purchase commission: 5%.
- Other currencies sale commission: 5%.

Example:

- Market rate USD = 500.
- Margin = 5%.
- Sale rate = 525.
- Purchase rate = 475.

Implementation rules:

- Store the reference rate and the customer-applied rate.
- Store the margin separately so profitability can be reported.
- Rates must be effective-dated and approved before use.
- Transactions must snapshot the rate used; later rate changes do not rewrite history.
- Displayed rate must match the rate applied to the transaction.

## Workflow

### Rate Publication

Acceptance criteria:

- Draft rate can be entered by authorized staff.
- Rate requires approval before becoming active.
- Only one active rate per currency pair, direction, and effective window.
- Published rates are visible to the transaction screen.

### Currency Exchange Transaction

Acceptance criteria:

- Customer identity is required for non-client walk-ins.
- Existing clients should be linked by client public ID when available.
- Currency stock availability is checked before sale.
- Cash stock increases/decreases in the correct currency.
- XAF leg posts to XAF cash/accounting.
- Currency exchange slip and register number are generated.
- Transaction is immutable after posting; correction uses reversal.

### End-Of-Day Currency Exchange Reconciliation

Acceptance criteria:

- Count foreign currency stock by currency and denomination if denominations are configured.
- Compare counted stock to theoretical stock.
- Block close on unexplained differences.
- Partner-bank replenishment/sale must be separately recorded and approved.

## Accounting Impact

Currency exchange requires limited currency-aware accounting support for this module:

- currency-specific cash accounts;
- realized exchange margin/commission income;
- partner bank settlement accounts;
- exchange gain/loss handling if applicable;
- revaluation rules if foreign currency stock is held across reporting periods.

The base accounting currency remains `XAF`. The additional accounting support exists only to record and reconcile the foreign-currency stock and the XAF value/margin of exchange operations.

## Backlog

1. Regulatory and licensing discovery.
2. Currency exchange ADR covering the limited currency-aware support required by this service.
3. Currency and precision reference data.
4. Currency exchange rate workflow.
5. Dedicated foreign-currency till and stock model.
6. Currency exchange transaction posting.
7. Currency exchange slip/register.
8. End-of-day currency exchange reconciliation.
9. Partner-bank replenishment/sale workflow.
10. Currency exchange reports.
