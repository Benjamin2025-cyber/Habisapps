# Database Conventions

These conventions are the default until the finance domain introduces a stronger ledger-specific model.

## Keys and table shape

- Use Laravel's default integer `id` primary keys.
- Externally exposed financial resources should also have immutable public identifiers, preferably ULIDs, so API clients do not depend on internal integer IDs.
- Foreign keys should use `foreignId()->constrained()` unless there is a documented reason not to.
- Keep table names plural and model names singular.
- Use explicit unique indexes for natural identifiers such as email, external references, or idempotency keys.

## Timestamps and lifecycle columns

- Mutable business tables should use `$table->timestamps()`.
- Use soft deletes only when the business meaning is "hidden from normal reads but retained". Do not add soft deletes by habit.
- Financial history tables should favor immutable rows and reversal records over soft deletion once finance modules exist.

## Money rules

- Never store money in floating-point columns.
- Amount columns must use `decimal(p, s)` with an adjacent ISO currency code column, or an integer minor-unit column with an adjacent currency code column.
- The project default before ledger work is `decimal` plus a currency column because it stays readable during early modeling.
- All money arithmetic in PHP should continue to use `brick/money`.

## Foreign keys

- Reference integrity is on by default.
- Prefer `cascadeOnDelete()` only for pure dependent records.
- Prefer `restrictOnDelete()` or nullable relations for business records that should survive parent deletion.
- Every deletion rule must reflect an explicit business lifecycle.

## Transactions

- Wrap multi-statement state changes in a database transaction.
- Controllers do not own transactions; actions do.
- Do not perform external network calls inside open database transactions.
- When work must happen after commit, dispatch it after the transaction succeeds.

## Migration discipline

- Every schema change must be reversible or have a documented rollback limitation.
- New migrations should include indexes needed for the feature being introduced.
- Do not change primary-key strategy or tenancy assumptions opportunistically in feature migrations.
