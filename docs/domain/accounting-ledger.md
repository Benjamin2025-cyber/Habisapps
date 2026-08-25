# Accounting Ledger Rules

The ledger is the highest-risk part of the platform. This document defines non-negotiable accounting rules for implementation.

## Core Principle

Every financial operation must be represented as a balanced, immutable journal entry.

Examples:

- Cash deposit
- Cash withdrawal
- Loan disbursement
- Loan repayment
- Fee assessment
- Insurance charge
- Penalty assessment
- Manual journal entry
- Till transfer
- Reversal or correction

## Required Tables

### ledger_accounts

Stores the chart of accounts.

Minimum fields:

- `id`
- `code`, unique per agency, and unique institution-wide for institution-level accounts
- `name`
- `account_class` — one of the nine PCEMF classes (see below)
- `type`
- `agency_id`, nullable — `NULL` means an institution-level grouping account
- `is_postable`
- `parent_account_id`
- `normal_balance`
- `status`
- timestamps

Rules:

- Ledger account codes are stable accounting references and should not be reused after deactivation.
- Deactivating a ledger account prevents new postings but must not break historical reporting.
- The chart is consolidated: institution-level accounts group the agency detail
  accounts beneath them, and only detail accounts (`is_postable`) receive entries.
  See [consolidated-chart-of-accounts.md](consolidated-chart-of-accounts.md) for the
  full structure, invariants, and remaining work.

#### PCEMF classes

`account_class` carries the class of the **Plan Comptable des Établissements de
Microfinance** (CEMAC/COBAC), which is the chart a Cameroonian EMF is required to keep.
The class is the leading digit of the account code, and this is **enforced on write**:
`571901` is a class 5 account and cannot be saved as class 4. The nine classes and their
order are taken from the institution's own "Plan des Comptes HF 2026"; the order is the
numbering, so inserting or reordering a value re-numbers every class after it.

| # | Value | Class |
| --- | --- | --- |
| 1 | `capitaux_permanents` | Comptes de capitaux permanents |
| 2 | `valeurs_immobilisees` | Comptes de valeurs immobilisées |
| 3 | `operations_clientele` | Comptes d'opérations avec la clientèle |
| 4 | `tiers` | Comptes de tiers |
| 5 | `tresorerie_interbancaire` | Comptes de trésorerie et d'opérations interbancaires |
| 6 | `charges` | Comptes de charges |
| 7 | `produits` | Comptes de produits |
| 8 | `soldes_intermediaires_gestion` | Soldes intermédiaires de gestion |
| 9 | `hors_bilan` | Comptes de hors bilan |

These replaced IFRS-style `asset`/`liability`/`equity`/`revenue`/`expense` values, which
named an account's nature rather than its place in the national chart. Nature is not lost:
classes 6 and 7 are the income statement, 8 aggregates them into the soldes intermédiaires
de gestion, 9 is off-balance-sheet, and for classes 1–5 the
balance-sheet side follows `normal_balance_side` — a debit-side class 3 account is client
lending, a credit-side class 3 account is client deposits. Add a dedicated statement-nature
column only if a balance sheet ever needs more than that.

`emf_regulatory_accounts.account_class` is deliberately *not* constrained to this list: it
mirrors whatever classification the loaded COBAC source file uses.

### account_products

Stores customer-account behavior rules before customer accounts are opened.

Minimum fields:

- `code`, scoped to the agency when agency-owned
- `name`
- `account_family`: savings, current, recovery, or future Islamic account families
- optional `ledger_account_id`
- `minimum_balance_minor`
- `currency`
- recovery and ordinary-savings flags
- `status`
- `rules`

Rules:

- Customer accounts may link to an active account product.
- Agency-owned products can only map to active ledger accounts in the same agency.
- Global products can be listed for agencies, but cannot map to agency ledger accounts in the current safe slice.
- When a customer account is opened from a product, the account stores the product link and defaults account family, currency, and ledger mapping from the product unless explicitly overridden by a valid request.

### Client Divisionary Accounts

« le numéro du client soit directement son compte comptable parce que c'est lui
qui sera utilisé pour passer des écritures dans son compte. » At customer
account opening, the resolved control (explicit choice or product default) is
not posted to directly: a divisionary under it is opened, and the customer
account's `ledger_account_id` points at that divisionary.

- The first account of a client carries the bare client number as its GL code;
  codes being unique per agency, further accounts under different controls
  embed the number — `CLI000123.ACC00000001` — so every code they own stays
  recognizable as theirs.
- Re-opening an account for the same client under the same control adopts the
  existing divisionary rather than minting another.
- Class, balance side, and parent come from the control; divisionaries are
  active and postable, and consolidate into their control through the ordinary
  hierarchy.
- The operational account number (`ACC…`, passbook) is unchanged; responses
  expose the posting code as `ledger_account_code` so no lookup is needed.
- Accounts opened before this existed keep their assigned ledger; postings
  always follow `customer_accounts.ledger_account_id`.

### emf_regulatory_accounts

Stores the institution-level EMF/COBAC regulatory chart reference.

Rules:

- EMF accounts are global regulatory references, not operational posting accounts.
- Parent-child hierarchy is supported for reporting rollups.
- Operational `ledger_accounts` are agency-scoped when they receive entries; an
  institution-level account (`agency_id IS NULL`) is a grouping account only and can
  never be posted to or mapped. This is the local consolidated chart, and it does not
  replace the EMF regulatory chart below.
- Local ledger-to-regulatory reporting must use `emf_ledger_account_mappings`; do not make agency ledgers global just to satisfy regulatory reporting.
- EMF accounts cannot be archived while child accounts or local ledger mappings still reference them.

### emf_ledger_account_mappings

Maps agency-scoped operational ledger accounts to the global EMF/COBAC regulatory chart.

Rules:

- Mappings are exposed by public ID through the API.
- Only active EMF regulatory accounts and active ledger accounts can be newly mapped.
- Duplicate EMF/ledger pairs are rejected before the database unique constraint is hit.
- Archiving a mapping disables it for future reporting checks without deleting historical configuration.

### operation_codes and operation_account_mappings

Store business-operation codification and the ledger accounts that future posting workflows may use.

Rules:

- Supported operation modules include accounting, cash, loan, insurance, HR, currency exchange, Islamic finance, SMS, reporting, and alerts.
- Operation account mappings are configuration records only. Creating or updating them must not create journal entries or journal lines.
- New mappings require at least one active debit or credit ledger account.
- If both debit and credit accounts are configured, they must belong to the same agency.
- Operation codes cannot be archived while non-archived account mappings still reference them.

### sectors and sub_sectors

Store economic activity classifications for client and loan metadata.

Rules:

- Sectors and sub-sectors are reference data only. They must not create journal entries, balances, portfolio metrics, or reports by themselves.
- `clients` and `loans` may carry both `sector_id` and `sub_sector_id`.
- A sub-sector must always belong to the selected sector. The API enforces this for clients, and database constraints enforce it for both clients and loans.
- New client classifications require active sector/sub-sector references. Loan workflow code must apply the same active-reference rule when the loan API is implemented.
- Regulatory taxonomy decisions remain separate from the current reference model; do not treat the local catalog as an approved regulatory report until a reporting requirement names that catalog.

### journal_entries

Stores posting headers.

Minimum fields:

- `id`, `public_id`
- `reference_number`, unique
- `transaction_date`
- `agency_id`
- `source_type`, `source_id`
- `description`
- `status`: draft, submitted, approved, rejected, posted, reversed, cancelled
- `submitted_by_user_id`, `submitted_at`
- `reviewed_by_user_id`, `reviewed_at`, `review_comment`, `rejection_reason`
- `posted_by_user_id`
- `posted_at`
- `reversed_by_entry_id`, nullable
- timestamps

### journal_entry_lines

Stores debit/credit lines.

Minimum fields:

- `id`
- `journal_entry_id`
- `ledger_account_id`
- optional `customer_account_id` for sub-ledger context
- `direction`: debit or credit
- `amount`
- `currency`
- `line_memo`
- timestamps

Rules:

- `ledger_account_id` is required on every line.
- `customer_account_id` is optional context and must belong to the same agency/client context required by the source operation.

## Invariants

- A posted journal entry must have at least two lines.
- Total debit must equal total credit per currency.
- All lines in a single journal entry must use the same currency unless an explicit foreign-exchange posting model is introduced.
- Posted entries are immutable.
- Corrections are reversal entries plus new corrected entries.
- Draft entries can be voided; posted entries cannot be voided.
- All amounts are positive decimals; direction carries debit/credit meaning.
- No float arithmetic is allowed.
- Financial actions must run inside database transactions.
- External side effects must happen after the database transaction commits.
- Every external financial mutation must be idempotent.
- Every posting must have a source reference to the domain operation that created it.

## Balance Projections

Balances shown in stakeholder screens should be projections:

- accounting balance
- available balance
- unavailable amount
- daily movement
- cumulative debit movement
- cumulative credit movement
- outstanding principal
- due amount
- unpaid amount

Projection rules:

- A projection must be rebuildable from ledger entries and domain schedules.
- If stored for performance, it must include reconciliation tests.
- Projection updates must occur in the same transaction as the posting that changes them.
- Available balance must subtract holds, unavailable funds, pending withdrawals, and other constraints from accounting balance.
- Do not expose a projection unless its rebuild rule is documented.

Current accounting-balance rebuild rule:

- Ledger account balance is derived from posted journal lines only.
- For debit-normal ledger accounts, balance is total posted debits less total posted credits.
- For credit-normal ledger accounts, balance is total posted credits less total posted debits.
- Customer account accounting balance is derived from posted journal lines linked to that customer account, using each line ledger account's normal balance side.
- Draft, submitted, approved, rejected, cancelled, and reversed source entries are excluded. Reversal effects appear through the posted reversal journal created by the reversal workflow.
- Balance endpoints support currency and business-date range filters. The date range is a period projection, not an opening/closing statement.
- Customer account available balance is currently derived as accounting balance minus product minimum balance, recorded unavailable amount, and active account holds in the requested currency.
- Released, cancelled, and archived holds do not reduce available balance.
- Pending withdrawals and loan restrictions are not included until those workflows produce explicit reservation records.
- Customer account statement and ledger movement endpoints list posted journal-line movements only.
- Statement opening balance is rebuilt from posted entries before the requested `from` date; closing balance is opening balance plus the requested period movement.
- Statement and movement responses expose public IDs only and paginate movement lines.

## Accounting Reports

Trial balance and general-ledger report runs use `report_definitions` and `report_runs`.

Rules:

- Only active report definitions can be run.
- The current accounting slice supports `trial_balance` and `general_ledger` report types.
- EMF/COBAC trial balance uses `emf_ledger_account_mappings`; generation is blocked while any posted ledger account in scope lacks an active EMF mapping.
- Report runs read posted journal lines only and store generated summaries in `report_runs.summary`.
- If an export document already exists, a run may link to it through `report_runs.document_id`; the report runner does not fabricate document rows or media files.
- Agency-scoped runs reject export documents from another agency.

## Customer Accounts Vs Ledger Accounts

Avoid polymorphic foreign keys like `account_id -> customer_accounts.id OR ledger_accounts.id`.

Use one of these safe designs:

- `journal_entry_lines.ledger_account_id` always points to a ledger account, plus optional `customer_account_id` for customer sub-ledger context.
- Or represent customer accounts as ledger-account-backed records with a one-to-one relationship.

The first option is recommended for this project because stakeholder screens need both customer account information and general ledger reporting.

## Manual Journal Entries

Manual journal entries are allowed only as controlled accounting operations.

Rules:

- Draft entries can be edited.
- Submitting validates the entry has at least two lines and debit equals credit.
- Approval/rejection requires a reviewer with `journal.entries.review` permission who is not the maker/submitter.
- Rejected entries must store a rejection reason.
- Posting validates debit equals credit and requires an approved entry.
- Posting is idempotent: posting an already posted entry returns the posted entry without creating another mutation.
- Posted entries cannot be edited or deleted.
- Reversal requires permission, only applies to posted entries, creates a posted reversal entry with debit/credit lines swapped, and marks the original as reversed.
- Every manual entry must include reference number, description, actor, and agency.

## Holds And Unavailable Funds

Customer account availability requires a separate hold/reservation model.

Examples:

- guarantee deposit holds
- pending withdrawals
- legal/account freezes
- collateral-related restrictions
- operational corrections pending approval

Rules:

- Holds must have amount, currency, reason, actor, start time, optional expiry, and release actor/time.
- Holds do not replace ledger postings.
- Available balance is accounting balance minus active holds and other approved constraints.

## Reconciliation Requirements

At minimum, the implementation must support:

- ledger trial balance
- customer account balance rebuild
- till theoretical balance rebuild
- loan principal/interest outstanding rebuild
- projection-vs-ledger mismatch detection

## Loan Divisionary Accounts

The accounting team's rule: « il n'y a pas de compte comptable par défaut car
chaque ligne de crédit entraîne automatiquement la création de plusieurs comptes
lors de la mise en place ». Nothing on a loan product names a GL account;
instead, mise en place (setup-charge assessment, and again at disbursement as a
safety net) opens the dossier's divisionary accounts under the control accounts
resolved through the approved agency operation-account mappings:

| Divisionary | Parent mapping leg | Typical PCEMF parent |
|---|---|---|
| principal receivable | `loan_principal_disbursement` debit | 3261 Crédits à la consommation aux clients |
| guarantee deposit held | `loan_setup_guarantee_deposit` credit | 3742 Dépôts de garantie clients |

Only balance-sheet positions the institution genuinely carries per dossier earn
a divisionary. Penalties do not: they are recognised in the ledger solely when
collected, so nothing is carried between assessment and collection, and their
credit leg is income. PCEMF class 7 is not subdivided per borrower — one revenue
account per loan would leave an EMF with thousands of them and an income
statement nobody can read.

Properties:

- codes are `<parent code>.<loan number>` while that fits the column, else a
  stable `D` + digest of (parent code, loan number); unique per agency like any
  ledger code;
- account class, normal balance side, and parent come from the mapped control
  account; divisionaries are active and postable;
- creation is idempotent: an account is opened once per loan and adopted, never
  duplicated;
- a missing or unusable mapping leaves the divisionary unopened — postings fail
  closed on the same mapping anyway, and configuring it later lets the next
  ensure call open the account.

Postings:

- disbursement debits the principal receivable; principal repayments credit it.
- guarantee collection credits the deposit-held liability; its release/settlement
  is a status workflow today and posts nothing.
- interest, penalties, dossier-fee income, both VATs, and insurance stay on
  their shared mapped accounts — income legs do not get per-dossier accounts.
  Every journal line carries `loan_id`, so per-dossier income analysis needs no
  extra accounts.

Divisionaries consolidate into their controls through the ordinary account
hierarchy, so portfolio balances by control account keep working unchanged.
Loans disbursed before this existed carry no divisionaries and keep posting to
the mapped control accounts.
