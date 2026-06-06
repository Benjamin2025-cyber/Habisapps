# GitHub Issue 12 Backlog: Role-Tailored Dashboard Backend Requests

Source issue: GitHub `Benjamin2025-cyber/Habisapps#12`.

Issue title: "Dashboard — Backend requests".

Investigation date: 2026-06-06.

## Legitimacy Finding

Issue #12 is legitimate, but several requested items are already partially or fully covered by the current backend worktree. The remaining backend gaps are mostly around role-specific scoping, status-count/stat endpoints, accountant queue counts, and teller summary enrichments.

The issue should not be implemented as one broad "dashboard endpoint" change. It should be split into small API contracts with explicit scope rules because the requested consumers are different roles: loan officer, agency manager, accountant, KYC officer, regional manager, teller, user admin, and platform management.

## Current-State Evidence

- `habis` remote points to `Benjamin2025-cyber/Habisapps`; issue #12 exists there.
- `origin` points to `bad1987/habis-finance-api`; issue #12 does not exist there.
- `routes/api/v1/dashboards.php` already registers `GET /dashboards/operational`, `GET /dashboards/operational/timeseries`, `GET /dashboards/agencies-performance`, and `GET /dashboards/executive`.
- `app/Application/Dashboard/DashboardWorkflow.php` already returns `active_loan_count`, `delinquent_loan_count`, operational timeseries, and agencies-performance rows.
- `app/Application/Loans/LoanCrudWorkflow.php` already supports `filter[in_arrears]`, but does not support `filter[credit_agent_public_id]`, `filter[par_bucket]`, or `filter[awaiting_disbursement]`.
- `app/Application/Loans/LoanCrudWorkflow.php` filters by top-level `status`, not bracketed `filter[status]`.
- `app/Application/Staff/StaffUserProfileWorkflow.php` already supports `filter[status]` and status counts in `StaffUserCollection` metadata.
- `app/Application/JournalEntries/JournalEntryWorkflow.php` does not support `filter[status]` and does not expose status counts.
- `app/Http/Resources/TellerSessionResource.php` exposes a cash summary, but not `commissions_total_minor` or `distinct_clients_served_count`.
- No routes were found for `GET /loans/stats`, `GET /clients/stats`, or `GET /journal-entries/stats`.
- `DashboardWorkflow::canViewOperational()` still restricts operational dashboards to platform-admin, agency-manager, and `accounting.audit.view`; field roles such as loan officer and KYC officer still need a self-scoped dashboard contract.

## Current Coverage Classification

| Issue item | Current status | Backlog action |
| --- | --- | --- |
| Loan-officer "my portfolio" scoping | Missing | Add `filter[credit_agent_public_id]` and self-scope helpers. |
| Delinquency/arrears list + counts | Partial | Existing `filter[in_arrears]`; add arrears projection fields, PAR bucket filtering, and count/stat shape. |
| Aggregated loan/client status counts | Missing | Add `GET /loans/stats` and `GET /clients/stats`. |
| Journal-entry status filter + counts | Missing | Add `filter[status]` and `GET /journal-entries/stats`. |
| Staff-user status filter + counts | Implemented in current worktree | Keep regression tests/docs; no new backlog implementation unless tests fail. |
| Scoped operational summary for field roles | Missing | Add role-specific/self-scoped summaries or safely relax operational dashboard with strict scope. |
| Operational timeseries | Implemented in current worktree | Keep regression tests/docs; no new backlog implementation unless tests fail. |
| Per-agency performance | Implemented in current worktree | Keep regression tests/docs; no new backlog implementation unless tests fail. |
| Teller commissions + clients served | Missing | Extend teller session summary and resource. |
| Disbursement queue count | Missing | Add `filter[awaiting_disbursement]` and/or stats count. |

## Scope

Deliver backend contracts that allow frontend dashboards to replace placeholders with real scoped data without unsafe cross-role leakage or N-round-trip status counting.

Scope rules are mandatory:

- Platform admins may query institution-wide data and optionally filter by agency.
- Agency managers may query only their current agency.
- Loan officers may query only loans assigned to themselves unless they also hold a broader permission.
- KYC officers may query only KYC/client workload visible under their current agency unless a broader CRM permission exists.
- Accountants may query only journal/disbursement queues in their authorized accounting scope.
- Regional managers require an explicit region/agency-scope source before cross-agency access is allowed; do not infer broad access from the role name alone.
- Tellers may only see their own teller session summary unless they hold teller-session management permissions.

## GHI-012A: Loan Officer Portfolio Filter

Add loan-officer scoping to `GET /api/v1/loans`.

Required query parameters:

- `filter[credit_agent_public_id]` optional string, public id of a staff user assigned as `loans.credit_agent_id`.
- Continue accepting top-level `status`; add `filter[status]` as a bracketed alias for dashboard clients.
- Preserve existing `filter[in_arrears]`.

Required behavior:

- Platform admins and institution-scope credit readers may filter by any valid credit agent.
- Agency managers may filter by credit agents in their current agency only.
- Loan officers without broader scope may omit the filter and receive only their own loans, or provide their own public id; other credit-agent ids must return forbidden or an empty scope-safe page by policy decision.
- Unknown credit-agent ids must not leak whether out-of-scope agents exist.
- Pagination `meta.pagination.total` must honor the credit-agent filter so `?per_page=1` count helpers are accurate.

Acceptance criteria:

- Feature test proves a loan officer sees only loans where `credit_agent_id` is their user id.
- Feature test proves an agency manager can filter by an in-agency credit agent and cannot widen to another agency.
- Feature test proves a platform admin can filter by credit agent across agencies.
- Feature test proves `filter[status]` and top-level `status` produce the same count for the same status.
- Feature test proves `filter[credit_agent_public_id]` combines correctly with `filter[in_arrears]` and pagination total.
- OpenAPI docs include the new filters.

## GHI-012B: Delinquent Loans Projection And PAR Buckets

Extend `GET /api/v1/loans` delinquency filtering so dashboard clients can render arrears cards without per-loan tracking calls.

Required query parameters:

- `filter[in_arrears]=true|false` existing behavior remains.
- `filter[par_bucket]=30|60|90` optional; only valid when arrears filtering is requested or when the endpoint explicitly defines non-arrears bucket behavior.
- `filter[as_of_date]` optional date; defaults to today.

Required response additions when arrears fields are requested:

```json
{
  "public_id": "01J...",
  "days_in_arrears": 37,
  "overdue_amount_minor": 125000,
  "par_bucket": 30
}
```

Acceptance criteria:

- Existing `filter[in_arrears]=true` still returns only loans with overdue unpaid schedule exposure under the same delinquency definition as dashboards.
- `filter[par_bucket]=30` returns loans at or above PAR30 and below PAR60 unless the product owner chooses cumulative buckets; the chosen policy must be documented in code and tests.
- `filter[par_bucket]=60` and `filter[par_bucket]=90` are tested with boundary dates.
- `days_in_arrears` is calculated from the oldest overdue unpaid schedule line as of `filter[as_of_date]`.
- `overdue_amount_minor` sums unpaid overdue schedule exposure, not the full outstanding principal, unless the dashboard explicitly requests PAR outstanding separately.
- Loans with multiple overdue lines appear once.
- Agency and credit-agent scoping cannot be widened by arrears filters.
- Invalid bucket values return 422 validation errors.

## GHI-012C: Loan And Client Stats Endpoints

Add aggregate endpoints to replace N status-count calls.

Required routes:

- `GET /api/v1/loans/stats`
- `GET /api/v1/clients/stats`

Suggested loan response:

```json
{
  "success": true,
  "message": "Loan statistics",
  "data": {
    "by_status": {
      "application": 3,
      "in_review": 2,
      "approved": 1,
      "disbursed": 9,
      "active": 7,
      "closed": 4,
      "rejected": 1
    },
    "in_arrears_count": 2,
    "par_buckets": {
      "par30": 2,
      "par60": 1,
      "par90": 0
    }
  },
  "errors": null
}
```

Suggested client response:

```json
{
  "success": true,
  "message": "Client statistics",
  "data": {
    "by_status": {
      "active": 12,
      "inactive": 1
    },
    "by_kyc_status": {
      "pending": 4,
      "verified": 10,
      "rejected": 1
    }
  },
  "errors": null
}
```

Required query parameters:

- Loan stats must support the same actor scope as `GET /loans`.
- Loan stats must support `filter[credit_agent_public_id]`, `filter[in_arrears]`, `filter[par_bucket]`, `agency_public_id` where authorized, `currency`, and relevant date filters if implemented by the list.
- Client stats must support the same actor scope as `GET /clients`.
- Client stats must support agency scope, search-compatible filters where already available, and KYC/status filters where already available.

Acceptance criteria:

- Stats counts are computed from the same scoped base query as the corresponding list endpoint.
- Applying a supported filter changes both list totals and stats consistently.
- Unsupported filters return 422 rather than being silently ignored.
- Platform-admin, agency-manager, loan-officer, and KYC-officer access are tested where applicable.
- Empty scopes return zero-filled known status keys instead of omitting them.
- Tests prove the stats endpoint avoids page-size dependence by counting records beyond the first page.
- OpenAPI docs include both stats routes and query parameters.

## GHI-012D: Journal Entry Status Filter And Stats

Add accountant queue filtering to journal entries.

Required route changes:

- Extend `GET /api/v1/journal-entries` with `filter[status]=draft|submitted|approved|posted|rejected|cancelled|reversed`.
- Add `GET /api/v1/journal-entries/stats`.

Suggested stats response:

```json
{
  "success": true,
  "message": "Journal entry statistics",
  "data": {
    "by_status": {
      "draft": 4,
      "submitted": 7,
      "approved": 2,
      "posted": 15,
      "rejected": 1,
      "cancelled": 0,
      "reversed": 0
    },
    "submitted_count": 7
  },
  "errors": null
}
```

Acceptance criteria:

- `filter[status]=submitted` returns only submitted journal entries and `meta.pagination.total` reflects all submitted entries, not only page 1.
- Stats counts use the same authorization and agency/accounting scope as the journal list.
- Invalid statuses return 422.
- Search and status filters compose predictably.
- Counts include zero values for known statuses.
- Feature tests cover accountant access, unauthorized user denial, agency scope, invalid status, and page-size-independent counts.
- OpenAPI docs include the new filter and stats route.

## GHI-012E: Field-Role Operational Summaries

Provide safe dashboard summaries for field roles currently forbidden from `GET /api/v1/dashboards/operational`.

Preferred implementation:

- Add role-specific endpoints, rather than loosening the existing operational endpoint:
  - `GET /api/v1/dashboards/loan-officer`
  - `GET /api/v1/dashboards/kyc-officer`
  - `GET /api/v1/dashboards/accountant`
  - `GET /api/v1/dashboards/regional`

Alternative implementation:

- Relax `GET /api/v1/dashboards/operational` only if the response is self-scoped by role and cannot leak broader operational metrics.

Required behavior:

- Loan officer summary includes own active loans, own applications/demands, own delinquent loans, portfolio outstanding, and collection indicators where attribution exists.
- KYC officer summary includes assigned/current-agency KYC pending, verified, rejected, and recent workload counts.
- Accountant summary includes submitted journal entries, approved/unposted entries, ready-to-disburse loans, and posted/rejected counts.
- Regional summary includes only agencies explicitly in the regional manager's scope. If no regional-scope model exists, implement only after adding that scope source.

Acceptance criteria:

- Each role receives only data permitted by explicit role/scope rules.
- Cross-agency and cross-user leakage tests exist for every field-role summary.
- Platform-admin behavior is either denied for role-specific self endpoints or explicitly documented and tested.
- Responses expose `scope` metadata naming the scope basis, such as `self`, `current_agency`, `assigned_region`, or `institution`.
- Empty workloads return zero-filled metrics.
- Existing `GET /dashboards/operational` authorization remains unchanged unless the alternative implementation is intentionally selected and covered by tests.

## GHI-012F: Teller Session Summary Enrichment

Extend teller session summary payloads with commissions and distinct clients served.

Required response additions in `TellerSessionResource.summary`:

```json
{
  "commissions_total_minor": 2500,
  "distinct_clients_served_count": 8
}
```

Required behavior:

- `commissions_total_minor` must be derived from posted cash/teller transactions or posted journal-backed fee allocations tied to the teller session. If the current domain does not identify commission components, first add a precise source mapping instead of hardcoding zero.
- `distinct_clients_served_count` must count unique clients served through posted teller transactions tied to the session. Reversals and cancelled/pending transactions must not inflate the count.
- The fields must appear in list, show, open, and close teller session responses whenever `summary` is attached.

Acceptance criteria:

- Feature test proves deposits/withdrawals/loan cash disbursement transactions for the same client count as one distinct client.
- Feature test proves transactions for multiple clients increment the distinct count.
- Feature test proves cancelled/pending/reversed transactions are excluded.
- Feature test proves commission totals use only posted eligible components.
- Feature test proves a teller cannot read another teller's session summary unless authorized by policy.
- OpenAPI docs include the new summary fields.

## GHI-012G: Ready-To-Disburse Queue Count

Expose an accurate accountant-facing count of loans ready to disburse.

Preferred implementation:

- Add `filter[awaiting_disbursement]=true` to `GET /api/v1/loans`.
- Include `awaiting_disbursement_count` in `GET /api/v1/loans/stats` and/or accountant dashboard summary.

Required definition:

- Loan status is `approved`.
- Setup-charge state reports `ready_for_disbursement=true`.
- Required principal disbursement ledger mapping is active, approved, effective for the relevant date/currency, and not cross-agency.
- Loan has no already-posted disbursement.
- Actor is authorized to view/disburse the loan in the relevant agency/accounting scope.

Acceptance criteria:

- Approved loan with all blocking setup charges collected/waived is counted.
- Approved loan with unpaid blocking setup charges is not counted.
- Approved loan with missing or unusable principal disbursement ledger mapping is not counted, and stats expose optional diagnostic counts only if the API contract includes them.
- Already disbursed/active loans are not counted.
- Agency scoping is enforced for accountants and agency managers.
- Pagination total for `GET /loans?filter[awaiting_disbursement]=true&per_page=1` matches the stats count.
- OpenAPI docs include the filter and stats field.

## Already Implemented Items To Protect

These issue-12 requests appear implemented in the current worktree and should be protected with regression tests/docs rather than reworked:

- `GET /dashboards/operational/timeseries`.
- `GET /dashboards/agencies-performance`.
- `GET /dashboards/operational` active and delinquent loan counts.
- `GET /loans?filter[in_arrears]=true`.
- `GET /staff-users?filter[status]=...` and `meta.status_counts`.

Acceptance criteria:

- Existing focused tests for these implemented contracts pass under `php artisan test --parallel --recreate-databases`.
- Generated OpenAPI includes these routes, filters, and response fields.
- If any listed item lacks direct test coverage, add tests before treating issue #12 as closed.

## Implementation Notes

- Prefer shared query builders for list and stats endpoints so counts cannot drift from paginated results.
- Avoid duplicating delinquency logic; use `DashboardMetrics` or extract a dedicated delinquency projection service if response fields require more than ids/counts.
- Normalize bracketed `filter[...]` query support across dashboards, loans, clients, staff users, and journal entries.
- Return 422 for unsupported filters where the endpoint has an explicit filter contract.
- Do not use frontend-only names when a durable domain term exists, but keep response field names stable once documented.
- Regenerate `public/docs/api.json` after implementation.

## Suggested Test Targets

Run focused tests first:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/Module4CreditLoansTest.php --filter 'loan.*filter|arrears|disbursement'
php artisan test --parallel --recreate-databases tests/Feature/Api/DashboardsTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/AdminDashboardRealDataTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module3AccountingProductTest.php --filter journal
php artisan test --parallel --recreate-databases tests/Feature/Api/StaffUserManagementTest.php --filter status
```

Then run the broader API suite if shared filters, scope helpers, or dashboard metrics are refactored:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api
```

Test-running notes:

- Put `--parallel` before any path argument.
- Do not run multiple `php artisan test --parallel --recreate-databases ...` commands concurrently; database recreation can collide.
