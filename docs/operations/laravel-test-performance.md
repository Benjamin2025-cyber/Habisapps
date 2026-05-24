
## Optimization Changes Applied On 2026-05-24

Implemented changes:

- Installed `brianium/paratest` so Laravel parallel testing is available.
- Changed `composer test` to run `php artisan test --parallel`.
- Added explicit scripts for serial, profile, unit, and feature test runs.
- Set test `LOG_CHANNEL=null` to avoid unnecessary test logging I/O.
- Rewrote `RolesAndPermissionsSeeder` to use bulk database upserts instead of repeated Spatie model `findOrCreate()` and `givePermissionTo()` calls.
- Added `database/schema/pgsql-schema.sql` so Laravel can load a stored PostgreSQL schema instead of replaying every migration for fresh test databases.

Measured results after changes:

| Command | Result |
|---|---|
| `php artisan test tests/Feature/Api/StaffUserManagementTest.php --compact` | 12 tests passed in about 4.5s |
| `php artisan test --parallel --recreate-databases --compact tests/Feature/Api/PolicyAuthorizationHardeningTest.php` | 6 tests passed in about 4.1s |
| `composer test` | 424 tests completed in about 22.6s, with 3 pre-existing/reproduced failures |

The full-suite timing is now seconds-scale rather than hour-scale. The remaining failures reproduced individually and are behavioral/test-expectation issues, not parallel execution failures:

- `Module4CreditLoansTest::test_loan_repayment_allocates_oldest_installment_capital_first_and_retains_overpayment`
- `Module3AccountingArchitectureTest::test_accounting_balances_are_derived_from_posted_journal_lines`
- `Module2CrmKycTest::test_metadata_only_kyc_evidence_cannot_be_verified`

Do not run multiple plain `php artisan test ...` commands concurrently against the same `habis_finance_api_test` database. Use `php artisan test --parallel` when running concurrently so Laravel creates isolated worker databases.
