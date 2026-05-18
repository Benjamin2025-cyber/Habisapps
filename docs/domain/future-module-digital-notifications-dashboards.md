# Future Module: Digital Notifications, Alerts, Reporting, And Dashboards

Stakeholder source: section 30, `Modules complementaires demandes`.

## Stakeholder Intent

The stakeholder requests:

- SMS Banking;
- automatic alerts for repayments and HR;
- automatic reporting;
- executive dashboards;
- operation code directories and codification guides.

## Product Boundary

These are cross-module services, not one module:

- SMS banking is a client communication and possibly self-service channel.
- Alerts are event-driven notifications.
- Automatic reporting is report scheduling and delivery.
- Dashboards are read models and analytics.
- Codification directories are reference data for operations and accounting.

## SMS Banking

Potential capabilities:

- transaction alerts;
- repayment reminders;
- balance alerts;
- OTP delivery;
- mini-statements;
- loan due notifications;
- account status alerts.

Acceptance criteria:

- Client notification consent is stored.
- Phone numbers are verified before sensitive messages.
- Message templates are versioned.
- No full sensitive account numbers in SMS.
- Delivery status is tracked.
- Failed deliveries are retryable with limits.
- SMS content is auditable but protected from excessive PII exposure.

Open decisions:

- inbound SMS commands or outbound notifications only;
- provider integration;
- language support;
- fees for SMS service;
- opt-in/opt-out policy.

## Automatic Alerts

Alert categories:

- loan repayment due;
- loan late after grace period;
- account hold/release;
- teller close pending;
- contract expiration;
- leave approval;
- insurance premium due;
- claim decision;
- report due.

Acceptance criteria:

- Alerts are generated from domain events or scheduled checks.
- Alerts have severity, recipient, channel, and delivery state.
- Duplicate alerts are suppressed by idempotency key.
- Critical alerts have escalation rules.

## Automatic Reporting

Acceptance criteria:

- Report schedules define report type, period, recipients, format, and approval rule.
- Draft reports can be reviewed before delivery when required.
- Delivered report artifacts are stored with checksum.
- Failures are visible and retryable.

## Executive Dashboards

Dashboard domains:

- portfolio outstanding;
- PAR30/PAR60/PAR90;
- collections;
- cash position;
- deposits;
- teller variances;
- loan approvals;
- insurance subscriptions and claims;
- HR headcount and payroll;
- regulatory report status.

Acceptance criteria:

- Dashboards read from approved reporting views, not raw ungoverned queries.
- Figures reconcile with report definitions.
- Filters include agency, period, product, and status.
- Role permissions control sensitive dashboards.
- Dashboard tiles show data freshness.

## Codification Directories

Purpose:

- provide a reference list of operation codes, labels, accounting mappings, and usage rules.

Acceptance criteria:

- Codes have effective dates and status.
- Codes cannot be deleted if used historically.
- Codes can be exported for operations/accounting teams.
- API exposes code, label, module, direction, and mapping completeness.

## Backlog

1. Notification consent and template model.
2. Outbound SMS provider integration.
3. Domain event alert engine.
4. Scheduled report engine.
5. Dashboard read models.
6. Codification directory UI/API.
7. Monitoring and delivery audit.
