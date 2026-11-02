# SDL PSR Custom PHP MVC Framework — Phase 2

Adds a small OCI8 database layer to the Phase 1 framework. It is intended for PHP 8.5, XAMPP/Apache, Oracle Database 12c, and later Flutter API integration.

## 1. Install/update

Extract the project, then from its root:

```powershell
composer dump-autoload
```

No additional third-party runtime packages are required.

## 2. Configure Oracle

Copy `.env.example` to `.env`, then replace the placeholder settings:

```text
ORACLE_USER=your_schema_username
ORACLE_PASSWORD=your_real_password
ORACLE_CONNECTION_STRING=//dbhost.example:1521/service_name
ORACLE_CHARSET=AL32UTF8
```

Use the exact hostname, port, and service name supplied by your Oracle DBA. Do not assume `ORCLPDB1` is your service name. For Oracle 12c, the connection string is commonly `hostname:1521/service_name` or `//hostname:1521/service_name`. A local TNS alias may also be used if Oracle Client/network configuration is correctly set up.

The minimal `.env` loader supports basic `KEY=value` entries and single/double-quoted values. Existing process environment variables take precedence. Avoid complex values containing shell-style escapes or embedded comment syntax. Protect `.env` with filesystem permissions and keep it out of source control.

## 3. Confirm OCI8 in the same runtime

In PowerShell:

```powershell
php --ri oci8
php -r "var_dump(function_exists('oci_connect'));"
```

For Apache, PHP may load a different `php.ini` than CLI. Use a temporary, access-restricted diagnostic page only if needed to verify Apache's `php.ini`; remove it immediately afterward.

## 4. Run local API

```powershell
php -S 127.0.0.1:8080 -t public public/index.php
```

In another terminal:

```powershell
curl.exe -i http://127.0.0.1:8080/api/v1/health
curl.exe -i http://127.0.0.1:8080/api/v1/health/db
```

- `/api/v1/health` checks the PHP API process only.
- `/api/v1/health/db` opens an OCI8 connection and executes `SELECT 1 AS HEALTH_CHECK FROM DUAL`.
- A successful DB health check returns HTTP 200.
- Missing configuration, missing OCI8, or an unreachable database returns HTTP 503 without exposing credentials or raw Oracle error details to the client.

## 5. Database layer examples

```php
use SDL\Framework\Database;

$db = Database::fromEnvironment();

// SELECT: bind values rather than concatenating user input.
$row = $db->fetchOne(
    'SELECT EMP_CODE FROM EMPLOYEE_TABLE WHERE EMP_CODE = :emp_code',
    ['emp_code' => 'E001']
);

// Multiple rows.
$rows = $db->fetchAll(
    'SELECT EMP_CODE FROM EMPLOYEE_TABLE WHERE STATUS = :status',
    ['status' => 'A']
);

// Transaction: changes are committed only if the callback completes.
$db->transaction(function (Database $db): void {
    $db->execute(
        'UPDATE SOME_TABLE SET STATUS = :status WHERE ID = :id',
        ['status' => 'A', 'id' => 10]
    );
});
```

Replace table and column names with actual schema objects approved for the API. The examples are illustrative and do not assume that `EMPLOYEE_TABLE` or `SOME_TABLE` exist.

## 6. Important OCI8 notes

- All SQL values should use bind variables. SQL identifiers such as table names and column names cannot be bound; keep them fixed or validate them against an allowlist.
- The database layer deliberately does not auto-commit each statement. Use `transaction()` for a unit of work. For a standalone DML statement, add an explicit transaction/commit pattern in your service/model rather than relying on implicit behavior.
- `fetchAll()` and `fetchOne()` execute in no-auto-commit mode. They are intended for queries. The connection manager keeps one non-persistent connection per PHP request.
- Use a least-privilege Oracle schema for the API; do not connect as `SYS`, `SYSTEM`, or another DBA account.
- The application logs Oracle diagnostics server-side. Restrict and rotate logs, and do not log passwords or bearer tokens.
- The database health endpoint should be protected or disabled in production if revealing service health is not appropriate for your environment.
- Oracle stored procedures with OUT parameters and LOBs need dedicated helpers; they are not implemented in this phase.
- This phase does not implement authentication, refresh-token storage, authorization, rate limiting, or production deployment hardening.

## 7. Test transaction behavior safely

Use a dedicated test table/schema approved by your DBA. Do not test DML against production business tables. Verify both successful commit and exception-triggered rollback before using transaction helpers in business flows.

\n## Phase 3 — Employee credential verification

Endpoint: `POST /api/v1/auth/login`

JSON body:
```json
{"username":"YOUR_TEST_LOGIN","password":"YOUR_TEST_PASSWORD"}
```

The model uses `BUSERS` joined to `SFM_EMP_INFO` and binds both credentials with OCI8. It returns only `login_id`, `emp_id`, `geo_id`, and `geo_level`.

PowerShell test:
```powershell
$body = @{ username = "YOUR_TEST_LOGIN"; password = "YOUR_TEST_PASSWORD" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8080/api/v1/auth/login" -ContentType "application/json" -Body $body
```

Use a test account. Never put real passwords in source files, tickets, screenshots, or logs.

Expected: 200 for matching active credentials; 401 for invalid/unknown/inactive credentials; 422 for missing fields; 503 for database/service failure.

Security limitation: this endpoint verifies credentials but does not issue access or refresh tokens. Do not expose it as a complete production login until opaque access tokens, refresh-token rotation/revocation, rate limiting, and HTTPS are implemented. The legacy five-round Base64-and-reversal `encodel()` is reversible obfuscation, not secure password hashing. It is retained for compatibility only; plan a migration to `password_hash()`/`password_verify()` or an approved verification service.

## Phase 4A — Secure token foundations (incremental)

This milestone adds token-generation primitives and proposed Oracle DDL only. Login still performs credential verification and does **not** issue tokens until Phase 4B is implemented and tested.

### Token design defaults

- Access token: 32 cryptographically random bytes, base64url encoded; 15-minute lifetime.
- Refresh token: 48 cryptographically random bytes, base64url encoded; 30-day lifetime.
- Token storage: SHA-256 hex digest only (64 characters); never persist or log the raw token.
- Session family ID: 16 random bytes encoded as 32 hexadecimal characters.
- Timestamp strings are normalized to UTC when prepared for Oracle `TIMESTAMP WITH TIME ZONE` values.

### Review the development schema DDL

File: `database/phase4/001_create_sdl_auth_tokens.sql`

Review and execute this DDL only in the approved development/test schema. It creates a new table and indexes; it does not change existing employee tables. Do not execute in production as part of this milestone. Oracle DDL performs implicit commits, so review it before execution.

### Smoke-check token primitives

After Composer autoload files are generated:

```powershell
composer dump-autoload
php -l app/Services/TokenService.php
php -l tests/token_service_smoke.php
php -d zend.assertions=1 -d assert.exception=1 tests/token_service_smoke.php
```

The smoke check deliberately prints no token values. It does not require Oracle and does not prove database persistence, token validation, login issuance, refresh rotation, or logout. Those are later Phase 4 milestones.

### Important security notes

The legacy password encoder remains unchanged for compatibility. Repeated Base64 encoding and string reversal is reversible obfuscation, not secure password hashing. Keep HTTPS mandatory outside local development, use generic login errors, rate-limit login attempts, and never log passwords or raw tokens. The token table should use a least-privilege application schema and have a reviewed retention/cleanup policy.

## Phase 4B — login token issuance and protected profile

Phase 4B connects the Phase 4A token primitives to the existing employee login flow.

### What changed

- Successful `POST /api/v1/auth/login` now creates an opaque access token (15 minutes) and refresh token (30 days).
- Only SHA-256 hashes of the tokens are stored in `SDL_AUTH_TOKENS`; raw tokens are returned once in the login response and must not be logged.
- Both token rows are inserted in one Oracle transaction; a failure inserting either row rolls back the pair.
- `GET /api/v1/auth/me` is protected by `AuthenticationMiddleware` and requires `Authorization: Bearer <access_token>`.
- The middleware validates token hash, token type, expiry and revocation, then checks that the employee remains active in `BUSERS` and `SFM_EMP_INFO`.
- Existing `/api/v1/health`, `/api/v1/health/db`, and example routes remain unchanged.

### Test login (PowerShell)

Use a development/test employee account only. Do not paste credentials into source files or logs.

```powershell
$body = @{ username = "YOUR_TEST_LOGIN"; password = "YOUR_TEST_PASSWORD" } | ConvertTo-Json
$response = Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8080/api/v1/auth/login" -ContentType "application/json" -Body $body
$response.data.employee
$response.data.expires_in
$accessToken = $response.data.access_token
$refreshToken = $response.data.refresh_token
```

The response includes `token_type`, `access_token`, `expires_in`, `access_expires_at`, `refresh_token`, `refresh_expires_in`, and `refresh_expires_at`. Treat both token values as secrets; do not print or screenshot them.

### Test protected profile

```powershell
$headers = @{ Authorization = "Bearer $accessToken" }
Invoke-RestMethod -Method Get -Uri "http://127.0.0.1:8080/api/v1/auth/me" -Headers $headers
```

Expected response shape (employee values are illustrative):

```json
{
  "success": true,
  "message": "Authenticated employee profile.",
  "data": {
    "employee": {
      "login_id": "TEST_LOGIN",
      "emp_id": 123,
      "geo_id": 10,
      "geo_level": 2
    }
  },
  "meta": { "version": "v1" }
}
```

Do not include a real token in screenshots or logs. To test rejection without sending a real token, use a dummy value:

```powershell
$badHeaders = @{ Authorization = "Bearer invalid-test-token" }
try { Invoke-RestMethod -Method Get -Uri "http://127.0.0.1:8080/api/v1/auth/me" -Headers $badHeaders } catch { $_.Exception.Response.StatusCode }
```

Expected: HTTP 401 for missing, malformed, unknown, expired, revoked, or otherwise invalid access tokens. A database failure during validation returns HTTP 503 without raw Oracle diagnostics.

### Phase 4B limitations

Refresh-token rotation, refresh-token reuse detection, logout/session revocation, persistent login rate limiting, and production HTTPS/CORS configuration are **not implemented in Phase 4B**. Do not consider authentication production-ready until those later milestones and deployment hardening are completed. The legacy employee password encoder remains reversible obfuscation for compatibility and must be migrated when a safe legacy credential strategy is approved.
