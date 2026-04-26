# Corrected Data Model

This document converts the stakeholder ER mapping into implementation-ready entity guidance. It is intentionally not a complete migration script.

## Naming Conventions

- Use singular Eloquent model names and plural table names.
- Use internal `id` primary keys unless a future ADR changes this.
- Add `public_id` to externally exposed business records.
- Use explicit unique business references: `agency_code`, `account_number`, `loan_number`, `event_number`, `matricule`.
- Use `status` enums only when transitions are controlled by application logic.
- Store enum values as stable ASCII machine values. Translate labels such as French UI text at the presentation layer.
- Every financial table needs timestamps and actor fields where a user or batch creates/approves/posts the record.

## Money Fields

Rules:

- Every amount field must have a currency unless the table has one documented currency invariant.
- Use decimal columns with consistent precision/scale until a minor-unit integer strategy is explicitly chosen.
- Use `brick/money` for calculations.
- Do not use floats.

## Administration Entities

### users

Current table exists and has been extended for the reusable staff-auth foundation.

Foundation fields:

- `public_id`
- `email`, nullable unique contact field
- `phone_number`, unique login and OTP identity
- `phone_verified_at`
- `matricule`, nullable staff reference
- `job_title`
- `agency_code`, nullable metadata until agency module exists
- `agency_name`, nullable metadata until agency module exists
- `invited_by_user_id`
- `status`: pending_verification, active, suspended, deactivated
- `activated_at`, nullable
- `last_login_at`, nullable

Do not store:

- `password_hash` as a separate field. Laravel already uses `password`.
- Domain-specific agency, portfolio, and supervisor foreign keys until those modules are intentionally implemented.

### otp_challenges

Required fields:

- `id`
- `user_id`
- `purpose`: activation now; future values can include login_challenge and password_reset
- `phone_number`
- `code_hash`
- `expires_at`
- `used_at`
- `attempts`
- `max_attempts`
- `created_ip`
- `created_user_agent`
- `last_sent_at`
- `resend_count`
- timestamps

Rules:

- Store hashed OTPs only.
- OTPs are single-use.
- OTP creation and verification are rate-limited.
- OTP records should be deleted or anonymized according to retention policy after expiry/use.

### otp_deliveries

Required fields:

- `id`
- `otp_challenge_id`
- `channel`: sms, email, or future delivery channel
- `destination_hash`
- `destination_masked`
- `status`
- `provider_reference`
- `error_summary`
- `sent_at`
- `failed_at`
- timestamps

Rules:

- Do not store plaintext OTPs in delivery records.
- Do not store full destinations where a hash plus masked display value is sufficient.
- One OTP challenge may have multiple delivery records so activation can be attempted through all configured channels.

### agencies

Required fields:

- `id`, `public_id`
- `code`, unique
- `name`
- `region`, `city`, `branch_type`
- `po_box`, `phone`, `fax`, `address_description`
- `creation_date`
- `status`
- `manager_id`, nullable until manager assignment
- timestamps

Rules:

- Agency deletion should be restricted once staff, clients, accounts, loans, or tills reference it.

### batch_procedures

Required fields:

- `id`, `code`, `name`
- `execution_priority`
- `execution_timing`
- `is_active`
- timestamps

Future companion table:

- `batch_runs` to record each execution, status, started_at, completed_at, error summary, and actor/system trigger.

## CRM Entities

### clients

Required fields:

- `id`, `public_id`
- `agency_id`
- `prospector_id`
- `matricule`, unique
- `status`
- identity fields: title, last_name, first_name, birth_date, birth_place
- contact fields: mobile_phone, home_phone
- family fields: father_name, mother_name
- addresses: home_address, business_address
- business_start_date
- collection configuration: collection_type, collection_frequency, collection_amount, collection_agent_id
- timestamps

Corrections:

- Identification documents should be separate records, not just columns on `clients`.
- Photos and signatures should be stored as document/file records with metadata and access control.
- KYC state should be explicit: draft, pending_review, verified, rejected, suspended, archived.
- PII reads and writes must be audit-sensitive.

### identity_documents

Shared for clients, guarantors, proxies, and possibly staff.

Required fields:

- `id`
- explicit owner columns are preferred. Use polymorphic ownership only if authorization and uniqueness rules remain clear.
- `document_type`
- `document_number`
- `issue_date`, `issue_place`, `expiry_date`
- `verification_status`
- `verified_by_user_id`, `verified_at`
- file metadata reference
- `expires_at`, nullable when the document type does not expire
- `rejection_reason`, nullable
- timestamps

Rules:

- Document numbers should be unique within document type and owner category when required by business rules.
- Verification status changes must be audited.

### files

Use a file metadata table instead of storing raw URLs on domain records.

Required fields:

- `id`, `public_id`
- owner reference
- storage disk/path
- original filename
- mime type
- byte size
- checksum
- visibility/access policy
- uploaded_by_user_id
- timestamps

Rules:

- Do not expose raw private storage paths in API responses.
- File access must be authorized per owner/resource.

### guarantors

Similar to client identity/contact structure, with agency and unique guarantor code.

Rules:

- A guarantor record can be linked to multiple loans/collaterals over time.

### proxies

Required additions:

- `client_id` as contextual owner where appropriate
- `account_id`
- `start_date`, `end_date`
- `status`
- `proxy_type`
- signature/file metadata

Rules:

- A proxy can operate only while active and within date range.

## Accounting Entities

### customer_accounts

Use a specific table name such as `customer_accounts` instead of generic `accounts` if ambiguity with ledger accounts becomes a problem.

Required fields:

- `id`, `public_id`
- `client_id`
- `agency_id`
- `manager_id`
- `account_number`, unique
- `account_title`
- `account_type`
- `currency`
- `status`
- `opening_date`, `closing_date`
- `created_by_user_id`
- `closed_by_user_id`, nullable
- `closure_reason`, nullable
- timestamps

Corrections:

- Do not treat accounting/available/unavailable balances as authoritative mutable columns.
- If persisted, balances are projections and must be rebuildable.
- Account availability requires active holds/reservations, not only ledger balance.

### account_holds

Required fields:

- `id`, `customer_account_id`
- `amount`, `currency`
- `reason`
- `source_type`, `source_id`
- `placed_by_user_id`, `placed_at`
- `released_by_user_id`, `released_at`
- timestamps

Rules:

- Active holds reduce available balance.
- Released holds remain auditable.

### ledger_accounts

Represents general ledger accounts and optionally customer sub-ledgers through explicit type fields.

Required fields:

- `id`, `code`, `name`
- `account_class`
- `type`: asset, liability, equity, income, expense
- `normal_balance`: debit or credit
- `status`
- timestamps

### journal_entries and journal_entry_lines

See `accounting-ledger.md`.

### sectors and sub_sectors

Required fields:

- sectors: `id`, `name`, `code`, `status`
- sub-sectors: `id`, `sector_id`, `name`, `code`, `status`

Rules:

- Names are not enough for stable references. Use codes for imports/reporting.

## Credit Entities

### loan_products

Required fields follow stakeholder mapping, with corrections:

- Use decimal columns for rates and amounts.
- Add currency for fixed amount rules.
- Add status and effective date range.
- Keep formulas as controlled enum/config references, not arbitrary strings, where possible.

### loans

Core fields:

- identity: `id`, `public_id`, `loan_number`
- actors: `client_id`, `agency_id`, `credit_agent_id`, `loan_product_id`
- linked accounts: amortization, unpaid, recovery, transfer
- requested/granted amount and currency
- product snapshot fields for rates/fees at approval time
- dates: application_date, first_installment_date
- schedule parameters
- lifecycle `status`
- `approved_at`, `disbursed_at`, `closed_at` as lifecycle timestamps where applicable
- timestamps

Corrections:

- Financial state fields in the stakeholder document should be projections.
- Product values that influence repayment must be snapshotted on the loan when approved so later product edits do not rewrite history.
- Loan creation, approval, disbursement, repayment, rescheduling, write-off, and closure must be implemented as actions, not generic CRUD updates.

### loan_approval_transitions

Required fields:

- `loan_id`
- `step`
- `decision`: submitted, approved, rejected, returned, cancelled
- `from_status`, `to_status`
- `user_id`
- `comments`
- `rejection_reason`
- `acted_at`
- timestamps

### loan_schedules

Required fields:

- `loan_id`
- `installment_number`
- `due_date`
- principal, interest, tax, penalty, total installment amounts
- currency
- `remaining_principal`
- `status`
- paid principal/interest/tax/penalty projections if persisted
- timestamps

Rules:

- Schedules are generated by the loan engine.
- Adjustments require explicit recalculation or rescheduling action.
- Schedule rows should not be edited after repayments are allocated without an explicit rescheduling record.

### collaterals and collateral_items

Corrections:

- Avoid accented enum values in storage. Use stable ASCII enum values such as `real_estate`, `movable`, `personal_guarantee`.
- Add valuation date, valuation actor, release date, and lifecycle status.

## Teller Entities

### tills

Required fields:

- `id`, `public_id`, `agency_id`
- `code`, `name`
- `assigned_user_id`
- `ledger_account_id`
- `status`, `daily_state`
- limits: max_balance_limit, max_withdrawal_limit
- configuration: requires_denominations, till_type, nature, is_central_till
- timestamps

### teller_transactions

Required fields:

- `id`, `public_id`
- `transaction_date`
- `agency_id`, `till_id`, `client_account_id`
- `event_number`
- direction: deposit or withdrawal
- amount and currency
- status
- `journal_entry_id`, nullable until posted
- `idempotency_key_id`, nullable for traceability
- actor fields and timestamps

Rules:

- Validation posts a journal entry.
- Cancellation posts a reversal, never deletes the original.
- Validated transactions must be immutable.

### till_reconciliations

Required fields:

- header with till, date, theoretical balance, counted balance, difference, status, actor
- lines by denomination, quantity, and line total

Rules:

- Theoretical balance comes from ledger/till postings.
- Differences must be approved or resolved through controlled adjustment postings.
- Approved reconciliations are immutable.
