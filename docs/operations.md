# Operational Baseline

## Local development

1. Copy `.env.example` to `.env`.
2. Configure PostgreSQL credentials in `.env`.
3. Run `composer install`.
4. Run `php artisan key:generate`.
5. Run `php artisan migrate`.

## Test database

- Automated tests use the PostgreSQL database named `habis_finance_api_test`.
- `phpunit.xml` points to that database while reusing the rest of the PostgreSQL connection settings from `.env`.
- Keep the test database isolated from local development data.

## Environment policy

- `.env` is local-only and must never be committed.
- `.env.example` is the contract for required variables and should be kept current.
- Runtime code should read configuration through `config(...)`, not direct `env(...)` calls outside config files.

## Deployment assumptions

- Production runs on PostgreSQL.
- Production should run `php artisan config:cache`, `php artisan route:cache`, and migrations as part of deployment.
- Production should use a centralized cache store such as database or Redis. The bootstrap defaults to database cache because idempotency locks must work across processes.
- The queue connection may remain `sync` during early development, but production should use an async queue before heavy background work is introduced.
- Logs should be sent to a centralized sink before handling real customer traffic.

## Quality gate

- Required checks: `vendor/bin/pint --test`, `vendor/bin/phpstan analyze`, `php artisan test`.
- GitHub Actions runs these checks on pushes and pull requests.

## API documentation

- The OpenAPI contract is committed at `public/openapi.yaml`.
- A lightweight Swagger UI page is available at `/docs.html` when the app is served.
- Feature work must update the OpenAPI contract in the same change as route/request/response changes.
