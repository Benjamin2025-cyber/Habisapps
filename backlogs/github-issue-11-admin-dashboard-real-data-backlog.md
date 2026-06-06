# GitHub Issue 11 Backlog: Admin Dashboard Real Data Gaps

Source issue: GitHub `Benjamin2025-cyber/Habisapps#11`.

Issue title: "Last issues - Admin (management) dashboard, real-data gaps".

Investigation date: 2026-06-06.

## Legitimacy Finding

Issue #11 is legitimate.

The current backend exposes `GET /api/v1/dashboards/operational` and `GET /api/v1/dashboards/executive` only. The operational dashboard returns aggregate portfolio, PAR, collection, cash, teller variance, insurance, and claim metrics, but it does not expose:

- operational dashboard time-series buckets for charting;
- per-agency performance rows;
- active and delinquent loan counts;
- staff-user counts by status or a staff-user status filter.

The related existing APIs also do not close the gaps:

- `GET /api/v1/loans` supports `status`, `search`, and client filters, but no `filter[in_arrears]` or derived delinquent filter.
- `GET /api/v1/staff-users` supports search, pagination, and agency scoping, but no `filter[status]` and no status-count summary.

## Evidence

- `routes/api/v1/dashboards.php` registers only `dashboards/operational` and `dashboards/executive`.
- `app/Application/Dashboard/DashboardWorkflow.php` computes aggregate operational metrics, but has no public timeseries or agencies-performance workflow.
- `app/Application/Dashboard/DashboardWorkflow.php` computes PAR amounts through `parBuckets()` and `portfolioAtRiskOutstanding()`, but does not return delinquent loan counts.
- `app/Application/Loans/LoanCrudWorkflow.php` filters loans by top-level `status`, search, and client only.
- `app/Application/Staff/StaffUserProfileWorkflow.php` filters staff users by search and agency scope only.
- `app/Models/User.php` defines statuses `pending_verification`, `active`, `suspended`, and `deactivated`, so the requested user status counts are computable.

## Scope

Deliver the backend data required to remove all four management-dashboard placeholders called out by the frontend.

Implementation should preserve existing authorization behavior:

- Platform admins may view institution-wide dashboard data and optionally filter by agency.
- Agency managers and other authorized non-platform operational actors stay restricted to their current agency scope.
- Unauthorized actors continue to receive existing forbidden responses.

## GHI-011A: Operational Dashboard Timeseries

Add `GET /api/v1/dashboards/operational/timeseries`.

Required query parameters:

- `agency_public_id` optional, same scope rules as `GET /dashboards/operational`.
- `currency` optional, default `XAF`.
- `period` optional enum: `today`, `week`, `month`, `year`.
- `granularity` optional enum: `hour`, `day`, `week`, `month`.
- `period_starts_on` optional date.
- `period_ends_on` optional date, after or equal to `period_starts_on`.
- `loan_product_public_id` optional.
- `loan_status` optional.
- `product_status` optional alias behavior should match the operational dashboard.

Required response shape:

```json
{
  "success": true,
  "message": "Operational dashboard timeseries",
  "data": {
    "agency_public_id": null,
    "currency": "XAF",
    "period": { "from": "2026-06-01", "to": "2026-06-06" },
    "granularity": "day",
    "points": [
      {
        "bucket": "2026-06-01T00:00:00+00:00",
        "balance_minor": 1000000,
        "collection_minor": 250000
      }
    ]
  },
  "errors": null
}
```

Acceptance criteria:

- Returns one point per expected bucket, including zero-value buckets.
- `balance_minor` uses the same outstanding calculation policy as the operational dashboard for the bucket date.
- `collection_minor` uses posted repayment allocations for the bucket window.
- Honors agency, period, currency, loan status, and loan-product filters.
- Includes feature tests for platform-admin institution-wide access, agency-manager scope restriction, period bucketing, and zero buckets.

## GHI-011B: Per-Agency Performance

Add `GET /api/v1/dashboards/agencies-performance`.

Required query parameters:

- `currency` optional, default `XAF`.
- `period` optional enum: `today`, `week`, `month`, `year`.
- `period_starts_on` optional date.
- `period_ends_on` optional date, after or equal to `period_starts_on`.
- `agency_public_id` optional for platform admins only.

Required response shape:

```json
{
  "success": true,
  "message": "Agency performance dashboard",
  "data": {
    "currency": "XAF",
    "period": { "from": "2026-06-01", "to": "2026-06-06" },
    "agencies": [
      {
        "agency_public_id": "01J...",
        "agency_code": "AG001",
        "agency_name": "Tsinga",
        "collections_minor": 2500000,
        "loans_count": 18,
        "loans_amount_minor": 9000000,
        "delinquent_count": 2,
        "delinquent_amount_minor": 350000,
        "best_agent_public_id": "01J...",
        "best_agent_name": "Belinga Karine"
      }
    ]
  },
  "errors": null
}
```

Acceptance criteria:

- Platform admins can retrieve rows for all agencies.
- Agency managers receive only their current agency row.
- `collections_minor` uses posted repayment allocation totals inside the period.
- `loans_count` and `loans_amount_minor` use reportable loans inside scope.
- `delinquent_count` and `delinquent_amount_minor` use the same arrears/PAR definition as the operational dashboard.
- `best_agent_name` is derived from the top collector for the period when the data can be attributed to a staff user; otherwise return `null`.
- Includes feature tests for platform-admin, agency-manager, cross-agency denial, and empty-agency rows.

## GHI-011C: Loan Counts For Dashboard KPIs

Extend `GET /api/v1/dashboards/operational` with loan counts.

Preferred response additions:

```json
{
  "data": {
    "active_loan_count": 42,
    "delinquent_loan_count": 5
  }
}
```

Optional secondary support:

- Add `GET /api/v1/loans?filter[in_arrears]=true` only if needed by frontend or reporting callers.

Acceptance criteria:

- `active_loan_count` counts reportable active/disbursed/rescheduled loans in the current scope and filters.
- `delinquent_loan_count` counts distinct loans with overdue unpaid schedule exposure under the same date and filter rules used by PAR.
- Dashboard sections include count rows so search/pagination consumers can discover them.
- Existing PAR amount behavior remains unchanged.
- Includes feature tests proving counts for no arrears, one delinquent loan with multiple overdue lines, and agency scoping.

## GHI-011D: Staff User Status Counts

Add staff-user status counts for the admin dashboard.

Preferred implementation:

- Add `filter[status]=pending_verification|active|suspended|deactivated` to `GET /api/v1/staff-users`.
- Add status counts to the `StaffUserCollection` metadata, scoped to the same actor visibility as the list.

Suggested metadata shape:

```json
{
  "meta": {
    "status_counts": {
      "total": 12,
      "pending_verification": 2,
      "active": 8,
      "suspended": 1,
      "deactivated": 1
    }
  }
}
```

Acceptance criteria:

- Platform admins receive institution-wide status counts.
- Agency-scoped actors receive counts only for their current agency staff.
- `filter[status]` narrows the returned `data.users` page but does not change the full scoped `status_counts` unless a separate filtered-count field is intentionally added.
- Unsupported statuses return a validation error.
- Includes feature tests for platform-admin counts, agency-scoped counts, status filtering, and invalid status validation.

## Implementation Notes

- Prefer extracting shared dashboard date-window, agency-scope, and loan-count helpers inside the dashboard application layer before adding new routes.
- Keep route authorization aligned with the existing operational dashboard.
- Use existing `respondSuccess()` envelopes and resource/collection response conventions.
- Avoid frontend-specific field names where a domain name is clearer, but keep the response stable enough for the dashboard contract.

## Suggested Test Targets

Run focused tests first:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/DashboardsTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/StaffUserManagementTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module4CreditLoansTest.php --filter loans
```

Then run the broader API suite if the dashboard helpers are significantly refactored:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api
```

Test-running notes:

- Put `--parallel` before any path argument.
- Do not run multiple `php artisan test --parallel --recreate-databases ...` commands concurrently; database recreation can collide.
