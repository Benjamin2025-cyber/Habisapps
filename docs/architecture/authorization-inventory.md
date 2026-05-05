# Authorization Inventory

This inventory supports `backlogs/laravel-13-professional-api-hardening-backlog.md` API-0101. It classifies current authorization paths before policy migration so later refactors can be checked against existing behavior.

Generated from static inspection of:

- `app/Http/Controllers/Api/V1`
- `app/Http/Requests`
- `app/Http/Requests/Api/V1`
- `app/Policies`
- `routes/api/v1`

## Classification Legend

- `policy-ready`: A policy exists with meaningful resource and scope methods, but the controller may still need to call it consistently.
- `request-authorized`: Form Requests contain the primary simple permission check.
- `mixed`: Authorization is split between Form Requests, policies, and controller checks.
- `manual-only`: Controller methods do most authorization inline.
- `placeholder-policy`: A policy exists but mostly duplicates a broad role check and does not yet encode resource scope.

## Controller Classification

| Controller | Current classification | Notes | Priority |
| --- | --- | --- | --- |
| `ClientController` | `mixed` / `policy-ready` | `ClientPolicy` has meaningful scope methods, but controller still performs manual permission and agency checks. | High |
| `ClientIdentityDocumentController` | `mixed` / `policy-ready` | `ClientIdentityDocumentPolicy` exists, but nested ownership and workflow checks are mostly manual. | High |
| `ClientGuarantorController` | `mixed` / `policy-ready` | `ClientGuarantorPolicy` exists, but status workflows and scope checks are manual. | High |
| `ClientProxyController` | `mixed` / `policy-ready` | `ClientProxyPolicy` exists, but date/status workflows and scope checks are manual. | High |
| `LedgerAccountController` | `mixed` / `policy-ready` | `LedgerAccountPolicy` exists and Form Requests authorize create/update, but index/show/destroy still check manually. | High |
| `CustomerAccountController` | `mixed` / `placeholder-policy` | Form Requests authorize platform-admin mutation; controller manually handles agency-scoped listing. Policy is too broad and must be aligned before use. | High |
| `AccountHoldController` | `mixed` / `placeholder-policy` | Form Requests authorize store/update/release; index/show/destroy manually require platform-admin. Policy is placeholder. | High |
| `JournalEntryController` | `manual-only` / `placeholder-policy` | Store/update Form Requests authorize, but index/show/submit/reverse/destroy manually require platform-admin. Policy is placeholder. | High |
| `JournalLineController` | `manual-only` / `placeholder-policy` | Store/update Form Requests authorize, but index/show/destroy manually require platform-admin. Policy is placeholder. | High |
| `SectorController` | `manual-only` / `placeholder-policy` | Store/update Form Requests authorize, but list/show/delete manually require platform-admin. Policy is placeholder. | Medium |
| `SubSectorController` | `manual-only` / `placeholder-policy` | Store/update Form Requests authorize, but list/show/delete manually require platform-admin. Policy is placeholder. | Medium |
| `DocumentController` | `mixed` | `StoreDocumentRequest` authorizes create. Controller handles view/archive and agency scope manually. | High |
| `StaffUserController` | `mixed` / `request-authorized` | Several Form Requests authorize simple actions. Controller performs high-risk role and agency checks manually. | High |
| `StaffAssignmentController` | `manual-only` | Controller owns assignment authority and agency checks. | High |
| `AgencyController` | `manual-only` | Controller owns agency visibility, mutation authority, manager assignment, and platform-admin safeguards. | High |
| `RoleController` | `manual-only` | Controller handles role catalog and permission mutation checks. | Medium |
| `BatchProcedureController` | `manual-only` | Controller checks batch permissions inline. | Medium |
| `BatchRunController` | `manual-only` | Controller owns batch-run authority and idempotency-specific checks. | Medium |
| `ReferenceNumberController` | `request-authorized` | `ReserveReferenceNumberRequest` authorizes reservation. | Low |
| `AuditEventController` | `manual-only` | Controller checks audit permission inline. Permission naming should be reviewed because Module 3 added `accounting.audit.view`. | Medium |
| `AuthController` | `request-authorized` / special-case | Public auth endpoints use Form Requests and rate limiters. Logout uses authenticated user context. | Low |

## Existing Policy Map

| Policy | Status | Notes |
| --- | --- | --- |
| `ClientPolicy` | Meaningful | Encodes client view/create/update/archive/KYC/review with agency and institution scopes. |
| `ClientIdentityDocumentPolicy` | Meaningful | Encodes view/create/update/archive/verify/reject with agency and institution scopes. |
| `ClientGuarantorPolicy` | Meaningful | Encodes view/create/update/archive/verify/reject with agency and institution scopes. |
| `ClientProxyPolicy` | Meaningful | Encodes view/create/update/archive/verify/reject/expire with agency and institution scopes. |
| `LedgerAccountPolicy` | Partial | Encodes broad ledger permissions but not all controller agency-scope behavior. |
| `CustomerAccountPolicy` | Placeholder | Platform-admin only; does not encode current agency-scoped listing behavior. |
| `AccountHoldPolicy` | Placeholder | Platform-admin only. |
| `JournalEntryPolicy` | Placeholder | Platform-admin only. |
| `JournalLinePolicy` | Placeholder | Platform-admin only. |
| `SectorPolicy` | Placeholder | Platform-admin only. |
| `SubSectorPolicy` | Placeholder | Platform-admin only. |

## Form Request Authorization Map

| Area | Request authorization state |
| --- | --- |
| Auth | Public auth requests generally allow request validation; route throttles handle abuse controls. |
| Staff users | Create/update/status/role requests contain simple permission checks. Controller still protects role escalation and agency scope. |
| Documents | Create request checks `documents.create`; view/archive remains controller-owned. |
| CRM clients | Create/update requests check simple CRM permissions; resource-specific scope remains controller-owned. |
| Identity documents | Create/update/status requests check simple permissions; parent/client scope and workflow rules remain controller-owned. |
| Guarantors | Create/update/status requests check simple permissions; parent/client scope and workflow rules remain controller-owned. |
| Proxies | Create/update/status requests check simple permissions; parent/client scope and workflow rules remain controller-owned. |
| Reference numbers | Reservation request checks `references.reserve`. |
| Accounting | Store/update/release requests exist for most resources. Many still use platform-admin checks instead of policy methods. |
| Sectors | Store/update requests use platform-admin checks. |

## Risk Priorities

High-risk migration targets:

- CRM nested resources, because valid child public IDs from a different parent can become confused without policy and scoped binding consistency.
- Accounting resources, because placeholder policies create false confidence and controller checks can drift as more roles are introduced.
- Staff and agency administration, because privilege escalation and cross-agency mutation risks are high.
- Documents, because file metadata and PII-adjacent access need consistent authorization.

Medium-risk migration targets:

- Batch procedure / batch run endpoints.
- Role catalog and permission mutation endpoints.
- Audit browsing endpoints.
- Sector and sub-sector reference endpoints.

Low-risk migration targets:

- Auth public endpoints, which are special-case flows.
- Reference-number reservation, which already uses request authorization and idempotency-specific logic.

## Migration Guidance

- Do not remove existing controller checks until a policy or Form Request test proves the replacement preserves behavior.
- Prefer one controller family per pull/change set.
- Start with accounting policies because the policies are placeholders and the current expected behavior is narrower.
- Then migrate CRM nested resources because policies already contain most scope semantics.
- Keep domain invariants, lifecycle checks, and cross-record compatibility checks outside policies unless they are purely authorization decisions.
