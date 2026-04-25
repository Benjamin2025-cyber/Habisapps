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
- `X-API-Version: 1`

**Idempotency:** Pass `Idempotency-Key` on POST/PATCH to safely retry without duplicate processing. Keys are bound to the request method, path, actor, tenant context, query string, and payload.

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
    └── Traits/            # Optional domain traits under evaluation
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
