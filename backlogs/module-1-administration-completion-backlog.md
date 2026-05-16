# Module 1 Completion Backlog: Administration, Security, And Batch Operations

This backlog complements `backlogs/module-1-administration-security-backlog.md`. The existing backlog completed staff, auth, agency, role, permission, and batch-registry foundations. This backlog covers what remains for complete stakeholder Module 1 implementation.

## Completion Scope

- Real batch execution, not only batch metadata.
- Scheduler/queue integration and operational monitoring.
- Production notification/OTP/SMS provider integration where administration workflows require it.
- Administration dashboards and audit review workflows that support operational control.

## Epic 1: Batch Execution Engine

- [x] DEV-0101: Implement batch procedure executable registry.
  - [x] Add a service that maps `batch_procedures.code` to executable job handlers.
  - [x] Reject execution when a procedure has no registered handler.
  - [x] Preserve current metadata-only APIs.
  - [x] Tests cover missing handler, inactive procedure, and successful handler dispatch.

- [x] DEV-0102: Implement batch run execution locking.
  - [x] Lock by procedure, business date, and agency scope.
  - [x] Prevent concurrent execution of the same batch scope.
  - [x] Preserve retry semantics for failed runs.
  - [x] Tests cover concurrent duplicate denial and retry after failure.

- [x] DEV-0103: Implement batch dependency ordering.
  - [x] Support execution priority and prerequisite procedures.
  - [x] Block dependent batches until prerequisites succeed.
  - [x] Record dependency failures in `batch_runs.summary` or structured metadata.
  - [x] Tests cover blocked, failed, and succeeded dependency flows.

## Epic 1A: Staff And Agency Structural Completion

- [x] DEV-0151: Complete staff professional-profile handoff.
  - [x] Decide which staff profile fields remain in Module 1 versus move to HR: Module 1 writes only the operational handoff subset into `hr_employees`; salary, identity, emergency contact, and professional history remain HR-owned.
  - [x] Cover gender, birth date/place, title/function, supervisor hierarchy, and portfolio assignment.
  - [x] Avoid duplicating HR employee records once HR is implemented.
  - [x] Tests cover public response contract and no sensitive profile leakage.
  - Evidence: `database/migrations/2026_05_16_030000_add_profile_handoff_fields_to_hr_employees_table.php`; `app/Application/Staff/SyncStaffUser.php`; `app/Http/Resources/StaffUserResource.php`; `php artisan test tests/Feature/Api/Module1AdministrationTest.php --filter=staff_professional_profile` passes with 1 test / 20 assertions; `php artisan test tests/Feature/Api/Module1AdministrationTest.php` passes with 18 tests / 182 assertions; `vendor/bin/phpstan analyse app/Application/Staff/SyncStaffUser.php app/Http/Controllers/Api/V1/StaffUserController.php app/Http/Requests/Api/V1/CreateStaffUserRequest.php app/Http/Requests/Api/V1/UpdateStaffUserRequest.php app/Http/Resources/StaffUserResource.php app/Models/HrEmployee.php app/Models/User.php tests/Feature/Api/Module1AdministrationTest.php --memory-limit=1G` passes with no errors.

- [x] DEV-0152: Complete agency structural metadata.
  - [x] Decide whether branch type, PO box, fax, and geographic-description fields are required in production.
  - [x] Add migrations/API fields if they remain Module 1 concerns.
  - [x] Preserve current agency public contract compatibility.
  - [x] Tests cover validation, update, and response contract.

## Epic 2: End-Of-Day Operational Batch Jobs

- [x] DEV-0201: Add cash close verification batch stub.
  - [x] Detect open teller sessions and unresolved till reconciliations.
  - [x] Do not compute formula-dependent cash differences until Module 5 completion.
  - [x] Tests cover agency close blocked by open sessions.

- [x] DEV-0202: Add accounting close verification batch stub.
  - [x] Detect draft/unposted journals that block business date close.
  - [x] Do not compute balances until Module 3 posting/balance engines are complete.
  - [x] Tests cover blocking and non-blocking journal states.

- [x] DEV-0203: Add loan servicing batch hooks.
  - [x] Provide hook points for penalties, arrears, portfolio reports, and notifications.
    - [x] Arrears and monthly penalty batch handlers are registered.
    - [x] Portfolio report and notification hooks queue pending `report_runs` and `notification_deliveries`; they do not compute metrics or send messages.
  - [x] Keep jobs disabled until formula gates are approved.
  - [x] Tests prove disabled formula jobs fail closed.
  - Evidence: `app/Application/BatchRuns/ExecuteLoanServicingHooksBatch.php`; `app/Application/BatchRuns/ExecuteRegisteredBatchRun.php`; `php artisan test tests/Feature/Api/Module1AdministrationTest.php --filter=loan_servicing_batch_hooks` passes with 1 test / 11 assertions; `php artisan test tests/Feature/Api/Module1AdministrationTest.php` passes with 19 tests / 193 assertions; `vendor/bin/phpstan analyse app/Application/BatchRuns/ExecuteRegisteredBatchRun.php app/Application/BatchRuns/ExecuteLoanServicingHooksBatch.php tests/Feature/Api/Module1AdministrationTest.php --memory-limit=1G` passes with no errors.

## Epic 3: Administration Operations Monitoring

- [x] DEV-0301: Implement batch run monitoring API.
  - [x] Expose run status, timing, failure reason, summary, and actor.
  - [x] Support agency and date filters.
  - [x] Deny cross-agency read access without explicit authority.
  - [x] Tests cover authorization and response contract.

- [x] DEV-0302: Implement batch retry/cancel controls.
  - [x] Permit retry only for failed or cancelled runs.
  - [x] Permit cancellation only before execution starts or through a safe abort state.
  - [x] Audit all operator actions.
  - [x] Tests cover invalid state transitions and audit records.

## Epic 4: Production Delivery Channels

- [x] DEV-0401: Implement production SMS/OTP delivery provider abstraction.
  - [x] Keep log provider for local/testing.
  - [x] Add provider configuration validation.
  - [x] Mask destinations in logs and audit records.
  - [x] Tests cover provider failure and retry-safe behavior.
  - Evidence: `app/Support/Otp/HttpSmsOtpDeliveryChannel.php`; `app/Support/Otp/OtpDeliveryChannelManager.php`; `config/security.php`; `php artisan test tests/Feature/Api/AuthTest.php --filter=http_sms_otp_provider` passes with 2 tests / 5 assertions; `php artisan test tests/Feature/Api/AuthTest.php` passes with 17 tests / 80 assertions; `vendor/bin/phpstan analyse app/Support/Otp/HttpSmsOtpDeliveryChannel.php app/Support/Otp/OtpDeliveryChannelManager.php app/Support/Otp/OtpService.php tests/Feature/Api/AuthTest.php --memory-limit=1G` passes with no errors.

- [x] DEV-0402: Implement notification delivery retries for administrative alerts.
  - [x] Store retry count, next attempt, and failure reason.
  - [x] Support batch failure, OTP, password reset, and operational alert templates.
  - [x] Tests cover retry, permanent failure, and no plaintext secret leakage.
  - Evidence: `database/migrations/2026_05_16_040000_add_retry_state_to_notification_and_otp_deliveries.php`; `app/Application/Notifications/NotificationDeliveryRetryManager.php`; `app/Support/Otp/OtpService.php`; `php artisan test tests/Feature/Api/Module1AdministrationTest.php --filter=notification_delivery_retry_manager` passes with 1 test / 12 assertions; `php artisan test tests/Feature/Api/AuthTest.php` passes with 17 tests / 80 assertions; `php artisan test tests/Feature/Api/Module1AdministrationTest.php` passes with 20 tests / 205 assertions; `vendor/bin/phpstan analyse app/Application/Notifications/NotificationDeliveryRetryManager.php app/Models/OtpDelivery.php app/Support/Otp/OtpService.php tests/Feature/Api/Module1AdministrationTest.php tests/Feature/Api/AuthTest.php --memory-limit=1G` passes with no errors.

## Completion Gate

- [x] Module 1 batch execution has tests.
- [x] Formula-dependent jobs remain disabled until their owning modules are complete.
- [x] Scheduler/queue failure modes are documented in `docs/operations.md`.
- [x] `vendor/bin/phpstan analyse --memory-limit=1G` passes.
- [x] `vendor/bin/pint --test` passes.
- [x] `php artisan scramble:export` passes and exports `api.json`.
- [x] Focused post-format verification passes: `php artisan test tests/Feature/Api/Module1AdministrationTest.php --filter='staff_professional_profile|loan_servicing_batch_hooks|notification_delivery_retry_manager'` passes with 3 tests / 43 assertions; `php artisan test tests/Feature/Api/AuthTest.php --filter='http_sms_otp_provider|otp'` passes with 10 tests / 43 assertions.
- [x] Production-readiness unit verification passes: `php artisan test tests/Unit/Support/ProductionReadinessCheckerTest.php` passes as part of a 7-test unit slice.
- [ ] `php artisan test` passes.
  - Status: not rerun to completion on 2026-05-16 because the full suite takes too long and was cancelled by operator request. Targeted Module 1, Auth, and PHPStan checks passed.
