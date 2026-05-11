# Habis Finance API

Microfinance core banking API built on Laravel 13.

## Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.4+ |
| Framework | Laravel 13 |
| Database | PostgreSQL |
| Auth | Laravel Sanctum (bearer token auth) |
| Static analysis | PHPStan level 9 |
| Testing | PHPUnit |
| Financial math | brick/money (no floats) |
| RBAC | spatie/laravel-permission |
| Audit trail | spatie/laravel-activitylog |
| API querying | spatie/laravel-query-builder |
| DTOs | spatie/laravel-data |
| API docs | Dedoc Scramble (OpenAPI 3.1) |

## Setup

```bash
cp .env.example .env
# Edit `.env` with your PostgreSQL credentials, then:

composer setup
```

**Manual steps if you prefer:**
```bash
composer install
php artisan key:generate
php artisan migrate
```

For automated tests, create a separate PostgreSQL database such as `habis_finance_api_test`. The committed `phpunit.xml` points tests at that database name while reusing the rest of your PostgreSQL connection settings from `.env`.

## Commands

```bash
composer dev     # Start dev server + queue + logs
composer test    # Clear config cache and run tests
php artisan test # Run tests directly
vendor/bin/phpstan analyze  # Static analysis
vendor/bin/pint --test      # Code style gate
```

## Docker Deployment

The repository now includes a Docker-based deployment path for the API.

### Local build

```bash
cp .env.example .env
docker compose up --build
```

### GitHub auto-deploy

The workflow in `.github/workflows/deploy.yml` expects these GitHub secrets:

- `VPS_HOST`
- `VPS_USER`
- `VPS_SSH_KEY`
- `VPS_SSH_PORT`

On the VPS, place the production `.env` file at `/srv/habis-finance-api/.env` before the first deployment. The workflow assumes the repository is already cloned at `/srv/habis-finance-api`, runs `git fetch origin main` followed by `git reset --hard origin/main`, and then runs `docker compose up -d --build --remove-orphans`.

The API container is published through Traefik at `https://api.abbisapps.site`, and the container itself only exposes port `8000` on the Docker network.

After each deploy, the workflow exports a static Scramble document with `php artisan scramble:export --path=public/docs/api.json` so `/docs/api` is served from that file instead of live-generating the specification on every request.

## API Documentation

API documentation is generated from Laravel routes, Form Requests, controller signatures, and response types using Dedoc Scramble.

- Docs UI: `http://localhost:8000/docs/api`
- OpenAPI JSON: `http://localhost:8000/docs/api.json`
- Module 3 architecture notes: `docs/domain/module-3-accounting-architecture.md`
- Export command: `php artisan scramble:export`

Scramble restricts docs to the `local` environment by default unless a `viewApiDocs` gate is explicitly defined.

## Foundation Policy

The repo now carries explicit initialization rules in:

- `docs/architecture.md`
- `docs/database-conventions.md`
- `docs/operations.md`
- `docs/security-baseline.md`
- `docs/domain/`

Current explicit decisions:

- Primary keys remain integer `id` columns.
- Multitenancy is out of scope for the current bootstrap.
- Repositories are optional and must be justified by reuse or complexity.
- Sanctum tokens currently default to the `access-api` ability.
- Sanctum tokens expire after `AUTH_TOKEN_TTL_MINUTES` / `SANCTUM_TOKEN_EXPIRATION_MINUTES`.
- Public registration is disabled unless `AUTH_REGISTRATION_ENABLED=true`.
- Login and registration are rate-limited.

Domain developer docs generated from stakeholder resources:

- `docs/domain/modules.md`
- `docs/domain/architecture-decisions.md`
- `docs/domain/data-model.md`
- `docs/domain/agency-scope.md`
- `docs/domain/accounting-ledger.md`
- `docs/domain/auth-and-staff.md`
- `docs/domain/loan-lifecycle.md`
- `docs/domain/cash-operations.md`
- `docs/domain/implementation-roadmap.md`
- `docs/domain/stakeholder-formula-questions.md`
- `docs/domain/stakeholder-formula-responses.md`
- `docs/domain/stakeholder-formula-items-to-explain.md`
- `docs/domain/stakeholder-response-scope-audit.md`
- `docs/domain/module-2-crm-kyc-operations.md`

Backlog analysis:

- `backlogs/stakeholder-formula-response-unblock-analysis.md`
- `backlogs/stakeholder-warning-notes-migration-impact.md`
- `backlogs/stakeholder-complete-migration-finalization-backlog.md`
- `backlogs/stakeholder-complete-migration-completion-audit.md`
- `backlogs/islamic-finance-discovery-backlog.md`

## API Design

All responses follow a standard envelope:

```json
{
  "success": true,
  "message": "Success",
  "data": { ... },
  "errors": null,
  "meta": null
}
```

**Headers required on every request:**
- `X-API-Version: 1`

**Idempotency:** Pass `Idempotency-Key` on POST/PATCH to safely retry without duplicate processing. Keys are persisted in the `api_idempotency_keys` table and bound to the request method, path, actor, query string, and payload.

## Directory Structure

```
app/
├── Http/
│   ├── Actions/          # Single-responsibility command objects
│   ├── Controllers/      # Thin controllers
│   └── Middleware/       # ApiVersion, Idempotency
├── Repositories/          # Data access layer with generics
└── Support/
    ├── ApiResponse.php    # Standard JSON response builder
    ├── Casts/             # MoneyCast (brick/money Eloquent cast)
    └── Traits/            # Shared support traits such as audit helpers
routes/
├── api.php                # API entry point
└── api/v1/
    └── auth.php           # Auth routes
```

## Architecture Patterns

- **Actions** encapsulate business logic (`BaseAction::run([...])`)
- **Repositories** provide typed CRUD with `spatie/laravel-query-builder`
- **Money** handled with `brick/money`
- **Audit logs** on every model mutation (dirty fields only)

## API Endpoints

| Method | Path | Auth | Status |
|---|---|---|---|
| GET | `/api/v1/health` | No | Live |
| POST | `/api/v1/login` | No | Live |
| POST | `/api/v1/activate` | No | Live |
| POST | `/api/v1/activation/resend` | No | Live |
| POST | `/api/v1/password/otp` | No | Live |
| POST | `/api/v1/password/reset` | No | Live |
| POST | `/api/v1/logout` | Sanctum | Live |
| GET | `/api/v1/staff-users` | Sanctum | Live |
| POST | `/api/v1/staff-users` | Sanctum | Live |
| GET | `/api/v1/staff-users/{staffUser}` | Sanctum | Live |
| PATCH | `/api/v1/staff-users/{staffUser}` | Sanctum | Live |
| PATCH | `/api/v1/staff-users/{staffUser}/status` | Sanctum | Live |
| PUT | `/api/v1/staff-users/{staffUser}/roles` | Sanctum | Live |
| GET | `/api/v1/documents` | Sanctum | Live |
| POST | `/api/v1/documents` | Sanctum | Live |
| GET | `/api/v1/documents/{document}` | Sanctum | Live |
| PATCH | `/api/v1/documents/{document}/archive` | Sanctum | Live |
| POST | `/api/v1/reference-numbers` | Sanctum | Live |
| GET | `/api/v1/audit-events` | Sanctum | Live |
