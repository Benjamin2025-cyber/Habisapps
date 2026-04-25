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

## API Documentation

API documentation is generated from Laravel routes, Form Requests, controller signatures, and response types using Dedoc Scramble.

- Docs UI: `http://localhost:8000/docs/api`
- OpenAPI JSON: `http://localhost:8000/docs/api.json`
- Export command: `php artisan scramble:export`

Scramble restricts docs to the `local` environment by default unless a `viewApiDocs` gate is explicitly defined.

## Foundation Policy

The repo now carries explicit initialization rules in:

- `docs/architecture.md`
- `docs/database-conventions.md`
- `docs/operations.md`
- `docs/security-baseline.md`

Current explicit decisions:

- Primary keys remain integer `id` columns.
- Multitenancy is out of scope for the current bootstrap.
- Repositories are optional and must be justified by reuse or complexity.
- Sanctum tokens currently default to the `access-api` ability.
- Sanctum tokens expire after `AUTH_TOKEN_TTL_MINUTES` / `SANCTUM_TOKEN_EXPIRATION_MINUTES`.
- Public registration is disabled unless `AUTH_REGISTRATION_ENABLED=true`.
- Login and registration are rate-limited.

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
| POST | `/api/v1/register` | No | Live |
| POST | `/api/v1/logout` | Sanctum | Live |
