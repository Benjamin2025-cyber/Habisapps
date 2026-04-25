# Habis Finance API

Microfinance core banking API built on Laravel 13.

## Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.4+ |
| Framework | Laravel 13 |
| Database | PostgreSQL |
| Auth | Laravel Sanctum (token-based) |
| Static analysis | PHPStan level 9 |
| Testing | PHPUnit |
| Financial math | brick/money (no floats) |
| RBAC | spatie/laravel-permission |
| Audit trail | spatie/laravel-activitylog |
| API querying | spatie/laravel-query-builder |
| DTOs | spatie/laravel-data |

## Setup

```bash
cp .env.example .env
# Edit .env with your database credentials, then:

composer setup
```

**Manual steps if you prefer:**
```bash
composer install
php artisan key:generate
php artisan migrate
```

## Commands

```bash
composer dev     # Start dev server + queue + logs
composer test    # Clear config cache and run tests
php artisan test # Run tests directly
vendor/bin/phpstan analyze  # Static analysis
```

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
- `Accept: application/json` (forced by middleware)
- `X-API-Version: 1`

**Idempotency:** Pass `Idempotency-Key` on POST/PATCH to safely retry without duplicates.

## Directory Structure

```
app/
├── Http/
│   ├── Actions/          # Single-responsibility command objects
│   ├── Controllers/      # Thin controllers
│   └── Middleware/        # ApiVersion, Idempotency, ForceJson
├── Repositories/          # Data access layer with generics
└── Support/
    ├── ApiResponse.php    # Standard JSON response builder
    ├── Casts/             # MoneyCast (brick/money Eloquent cast)
    └── Traits/            # HasUuid, HasAuditLog, BelongsToTenant
routes/
├── api.php                # API entry point
└── api/v1/
    └── auth.php           # Auth route stubs
```

## Architecture Patterns

- **UUIDs** on all models via `HasUuid` trait
- **Actions** encapsulate business logic (`BaseAction::run([...])`)
- **Repositories** provide typed CRUD with `spatie/laravel-query-builder`
- **Money** stored as decimal subunits, handled via `brick/money`
- **Audit logs** on every model mutation (dirty fields only)
- **Multi-tenant** ready via `BelongsToTenant` trait and Laravel Context

## API Endpoints

| Method | Path | Auth | Status |
|---|---|---|---|
| GET | `/api/v1/health` | No | Live |
| POST | `/api/v1/login` | No | Stub |
| POST | `/api/v1/register` | No | Stub |
| POST | `/api/v1/logout` | Sanctum | Stub |
