# Security Test Commands - Habis Finance API

This document contains all test commands executed during the security assessment.

## Setup & Environment

```bash
# Check Laravel version
php artisan --version
# Laravel Framework 13.6.0

# Check database migrations
php artisan migrate:status

# Start development server
php artisan serve --host=127.0.0.1 --port=8000 &

# Health check
curl http://127.0.0.1:8000/api/v1/health | python3 -m json.tool
```

---

## 1. Basic Authentication Flows

### Registration (Disabled by Default)
```bash
curl -X POST http://127.0.0.1:8000/api/v1/register \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"Password123!","password_confirmation":"Password123!"}' \
  | python3 -m json.tool
```
Expected: `403 Forbidden` - "Registration is disabled."

### Enable Registration via Tinker (for testing)
```bash
php artisan tinker --execute="config(['security.auth.registration.enabled' => true]);"
```

### Create User Directly
```bash
php artisan tinker --execute="App\Models\User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('Password123!')]); echo 'User created';"
```

### Login (Valid Credentials)
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}' \
  | python3 -m json.tool
```

Response includes token:
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { "id": 1, "name": "...", "email": "test@example.com" },
    "token": "1|..."
  }
}
```

### Logout (Token Revocation)
```bash
TOKEN="<plain_text_token>"
curl -X POST http://127.0.0.1:8000/api/v1/logout \
  -H "X-API-Version: 1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  | python3 -m json.tool
```
Expected: `200 OK` - "Logout successful"

Test token immediately after logout (should fail):
```bash
curl -X POST http://127.0.0.1:8000/api/v1/logout \
  -H "X-API-Version: 1" \
  -H "Authorization: Bearer $TOKEN" \
  | python3 -m json.tool
```
Expected: `401 Unauthorized`

---

## 2. Rate Limiting Tests

### Login Rate Limit (5 attempts / minute)
```bash
for i in {1..6}; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST http://127.0.0.1:8000/api/v1/login \
    -H "X-API-Version: 1" \
    -H "Content-Type: application/json" \
    -d '{"email":"test@example.com","password":"wrongpass"}')
  echo "Attempt $i: HTTP $STATUS"
done
```
Expected: `401` for attempts 1-5, `429` for attempt 6

### Register Rate Limit (3 attempts / minute)
```bash
# Enable registration first
php artisan tinker --execute="config(['security.auth.registration.enabled' => true]);"

for i in {1..4}; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST http://127.0.0.1:8000/api/v1/register \
    -H "X-API-Version: 1" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"Test$i\",\"email\":\"rate-limit-$i@example.com\",\"password\":\"Password123!\",\"password_confirmation\":\"Password123!\"}")
  echo "Attempt $i: HTTP $STATUS"
done
```
Expected: `201` for attempts 1-3, `429` for attempt 4

Clear rate limit cache (for testing):
```bash
php artisan cache:clear
```

---

## 3. API Versioning Tests

### Missing API Version Header
```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}' \
  | python3 -m json.tool
```
Expected: **Works** - defaults to version 1

### Unsupported API Version
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 99" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}' \
  | python3 -m json.tool
```
Expected: `400 Bad Request`
```json
{
  "success": false,
  "message": "Unsupported API version: 99. Supported versions: 1"
}
```

---

## 4. Idempotency Tests

### Same Key + Same Payload (Should Replay)
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-key-123" \
  -d '{"email":"test@example.com","password":"Password123!"}' \
  | python3 -m json.tool
```
Response: `200 OK` with token

Repeat same request:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-key-123" \
  -d '{"email":"test@example.com","password":"Password123!"}' \
  | python3 -m json.tool
```
Expected: Same token returned, header `Idempotency-Replayed: true`

### Same Key + Different Payload (Should Reject)
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-key-123" \
  -d '{"email":"test@example.com","password":"DifferentPassword!"}' \
  | python3 -m json.tool
```
Expected: `409 Conflict`
```json
{
  "success": false,
  "message": "Idempotency-Key has already been used for a different request."
}
```

---

## 5. SQL Injection Tests

All payloads should return validation errors, not database errors.

```bash
PAYLOADS=(
  "' OR '1'='1"
  "' OR 1=1 --"
  "admin@example.com' UNION SELECT NULL,NULL,NULL--"
  "test@example.com\" OR \"1\"=\"1"
  "test@example.com' AND '1'='1"
)

for p in "${PAYLOADS[@]}"; do
  echo "Testing: $p"
  curl -s -X POST http://127.0.0.1:8000/api/v1/login \
    -H "X-API-Version: 1" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"$p\",\"password\":\"Password123!\"}" \
    | python3 -c "import sys, json; data=json.load(sys.stdin); print(f\"Status: {data.get('success')}, Message: {data.get('message')}\")"
done
```
Expected for all: `422 Validation failed` with "The email field must be a valid email address."

---

## 6. Token Tampering Tests

First get a valid token:
```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}' \
  | python3 -c "import sys, json; print(json.load(sys.stdin)['data']['token'])")
echo "Valid token: $TOKEN"
```

### Tampering Tests

```bash
# 1. Change ID part
TAMPERED="${TOKEN%|*}||hacked"
curl -X POST http://127.0.0.1:8000/api/v1/logout \
  -H "X-API-Version: 1" \
  -H "Authorization: Bearer $TAMPERED" \
  | python3 -m json.tool
# Expected: 401 Unauthorized

# 2. Change ID to non-existent
TAMPERED="999|${TOKEN#*|}"
curl -X POST ...  # Same as above
# Expected: 401 Unauthorized

# 3. Corrupt hash
TAMPERED="${TOKEN%?}A"
curl -X POST ...  # Same as above
# Expected: 401 Unauthorized
```

---

## 7. Mass Assignment Tests

```bash
curl -X POST http://127.0.0.1:8000/api/v1/register \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"Test User",
    "email":"test2@example.com",
    "password":"Password123!",
    "password_confirmation":"Password123!",
    "is_admin":true,
    "role":"admin"
  }' | python3 -m json.tool
```
Expected: `422` with prohibited field errors:
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "is_admin": ["The is admin field is prohibited."],
    "role": ["The role field is prohibited."]
  }
}
```

---

## 8. XSS & Input Validation Tests

### Create User with XSS in Name
```bash
# Temporarily enable registration
php artisan tinker <<'EOF'
config(['security.auth.registration.enabled' => true]);
EOF

# Register user with script tag
curl -X POST http://127.0.0.1:8000/api/v1/register \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"<script>alert(1)</script>",
    "email":"xss@example.com",
    "password":"Password123!",
    "password_confirmation":"Password123!"
  }' | python3 -c "import sys, json; data=json.load(sys.stdin); print(data['data']['user']['name'])"
```
Expected: Name stored as `<script>alert(1)</script>` (XSS risk)

### Login and Verify XSS in Response
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"email":"xss@example.com","password":"Password123!"}' \
  | python3 -m json.tool | grep -A 2 '"name"'
```
Expected: Name returned with script tags intact (JSON-encoded is safe, but frontends must escape)

---

## 9. Malformed Input Tests

### Missing Required Fields
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com"}' \
  | python3 -m json.tool
```
Expected: `422` with "The password field is required."

### Invalid JSON Syntax
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{email: test}' \
  | python3 -m json.tool
```
Expected: `422` missing fields (JSON parsed as empty)

### Empty Body
```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Accept: application/json" \
  -d '' -w "\nHTTP Status: %{http_code}\n"
```
Expected: `422`

### Email Too Long (>255 chars)
```bash
LONG=$(python3 -c "print('a'*256)")
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$LONG\",\"password\":\"pass\"}" \
  | python3 -m json.tool
```
Expected: `422` - "The email field must not be greater than 255 characters."

---

## 10. Oversized Payload DoS Test

```bash
PAYLOAD=$(python3 -c "print('A' * 100000)")
curl -s -X POST http://127.0.0.1:8000/api/v1/login \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"test@example.com\",\"password\":\"$PAYLOAD\"}" \
  -w "\nHTTP Status: %{http_code}\n" | head -c 200
```
Expected: `401` or `422` (handled gracefully, no crash)

---

## 11. Token Expiration Test

```bash
# Create and expire a token
php artisan tinker <<'EOF'
$user = App\Models\User::first();
$token = $user->createToken('expire-test');
$plain = $token->plainTextToken;
$id = $token->accessToken->id;
echo "Plain: $plain\nID: $id\n";
$tokenModel = \Laravel\Sanctum\PersonalAccessToken::find($id);
$tokenModel->expires_at = now()->subMinute();
$tokenModel->save();
echo "Token expired in DB.\n";
EOF

# Copy the printed token and use it
TOKEN="<expired_token>"
curl -X POST http://127.0.0.1:8000/api/v1/logout \
  -H "X-API-Version: 1" \
  -H "Authorization: Bearer $TOKEN" \
  | python3 -m json.tool
```
Expected: `401 Unauthorized`

---

## 12. Logout Without Authentication

```bash
curl -X POST http://127.0.0.1:8000/api/v1/logout \
  -H "X-API-Version: 1" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{}' \
  | python3 -m json.tool
```
Expected: Redirect to login route (authentication error), but API returns:
```json
{
  "success": false,
  "message": "Route [login] not defined.",
  "errors": { ... }
}
```
This indicates Sanctum's redirectTo() method tried to generate a login URL for API request - should be `401 Unauthorized` instead. **Minor UX issue.**

---

## 13. Information Disclosure Tests

### Test if database errors are exposed (in production)
In production (`APP_DEBUG=false`), all errors should return generic messages:
```json
{
  "success": false,
  "message": "Internal server error"
}
```

---

## Test Environment Reset Commands

```bash
# Clear cache
php artisan cache:clear
php artisan config:clear

# Re-run all migrations (fresh database)
php artisan migrate:fresh --seed

# Start fresh with test database
php artisan test
```

---

## Notes

- All tests performed against Laravel's built-in server on `127.0.0.1:8000`
- Database: PostgreSQL `habis_finance_api` (use `.env` credentials)
- Application in `local` environment with `APP_DEBUG=true`
- Rate limiter cache uses default `file` driver (production should use redis)

---

**End of Test Commands**
