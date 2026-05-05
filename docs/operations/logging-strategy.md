# Production Logging Strategy

Date: 2026-05-04

## Docker And VPS Defaults

Recommended production environment:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=stderr
LOG_LEVEL=info
```

Rationale:

- Docker captures `stderr` cleanly and leaves rotation/retention to the host or log collector.
- Application diagnostics stay separate from security audit records.
- Local development can keep file logs by leaving `LOG_STACK` unset.

## Operational Logs vs Audit Evidence

Operational logs are for debugging application health and failures.

Security audit events are for answering who performed a business/security action. They must remain conceptually separate from operational diagnostics and should not be replaced by `Log::info(...)` calls.

## Standard Context Keys

New operational logging and exception context should use these keys:

- `app_env`
- `api_version`
- `request_id`
- `route_name`
- `module`
- `public_resource_id`
- `actor_public_id`
- `agency_public_id`

Do not add these values to global exception context:

- raw request body
- bearer tokens
- passwords
- OTPs
- raw phone numbers
- internal integer IDs
- full document numbers

## Critical Alerts

Critical alerts are not enabled by default. If an external alert sink is added, prefer a dedicated channel such as Slack or the hosting provider's alert integration and include it in `LOG_STACK` only for `critical` and above.

Example:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=stderr,slack
LOG_LEVEL=info
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
```

Do not send audit evidence to chat alert channels.
