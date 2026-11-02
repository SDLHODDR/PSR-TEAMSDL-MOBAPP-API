# SDL PSR API — Phase 4C: Refresh Tokens and Logout

## Important before copying

This is a **patch**, not a full project replacement. Copy the included files into your existing working Phase 4B project, keeping the same folder structure. Back up your current project first. The patch targets the actual table name confirmed for this project: `SFM_APPUSER_AUTH_TOKENS`.

The Phase 4B ZIP available to prepare this patch did not contain the user's latest local table-name adjustment. This patch explicitly uses `SFM_APPUSER_AUTH_TOKENS` throughout the token model. It assumes the executed table has the Phase 4A columns `TOKEN_ID`, `EMP_ID`, `LOGIN_ID`, `TOKEN_HASH`, `TOKEN_TYPE`, `FAMILY_ID`, `REPLACED_BY_ID`, `CREATED_AT`, `EXPIRES_AT`, `REVOKED_AT`, `LAST_USED_AT`, and `CREATED_IP`. No DDL needs to be rerun for Phase 4C if those columns already exist.

## Endpoints

- `POST /api/v1/auth/refresh` — public route, but requires a valid refresh token in JSON.
- `POST /api/v1/auth/logout` — protected by a valid access token; revokes every token in the current session family.
- `GET /api/v1/auth/me` — remains protected.

## Refresh request

```json
{"refresh_token":"<refresh token returned by login>"}
```

A successful response contains a new access token and a new refresh token. Replace both saved tokens in the client immediately. The old refresh token becomes unusable. A previously replaced refresh token being presented again triggers revocation of its whole family. Do not run refresh requests concurrently from the mobile app; serialize refresh attempts.

## Logout request

Send the current access token in the Authorization header:

`Authorization: Bearer <access_token>`

No request body is required. Successful logout revokes all access and refresh tokens in that session family. A subsequent `/me` request with the same access token should return 401.

## Local syntax checks (PowerShell)

Run from the project root:

```powershell
composer dump-autoload
php -l app/Models/AuthTokenModel.php
php -l app/Services/AuthService.php
php -l app/Controllers/AuthController.php
php -l app/Middleware/AuthenticationMiddleware.php
php -l app/Routes/api_v1.php
```

These checks do not prove Oracle SQL or transaction behavior. Test against your development/test Oracle schema before accepting the phase.

## PowerShell smoke-test flow

1. Sign in using the existing Phase 4B login flow and keep both tokens in variables. Avoid printing them.
2. Call `/me` with the access token; expect HTTP 200.
3. Refresh using the refresh token; expect HTTP 200 and a new pair.
4. Call `/me` with the old access token; expect HTTP 401 because rotation revokes the previous access token.
5. Call `/me` with the new access token; expect HTTP 200.
6. Attempt refresh with the old refresh token; expect HTTP 401. This is a replay test and should revoke the session family.
7. Sign in again to create a clean session, then call logout with its access token; expect success.
8. Call `/me` with the just-logged-out access token; expect HTTP 401.

Example refresh command (uses variables populated from login; do not paste tokens into chat or logs):

```powershell
$refreshBody = @{ refresh_token = $refreshToken } | ConvertTo-Json
$refreshResponse = Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8080/api/v1/auth/refresh" -ContentType "application/json" -Body $refreshBody
$newAccessToken = $refreshResponse.data.access_token
$newRefreshToken = $refreshResponse.data.refresh_token
```

Example logout command:

```powershell
$logoutHeaders = @{ Authorization = "Bearer $newAccessToken" }
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8080/api/v1/auth/logout" -Headers $logoutHeaders
```

## Security notes and current limitations

- Only token hashes are stored; raw tokens are returned once and must be treated as secrets.
- Refresh rotation uses an Oracle transaction and `SELECT ... FOR UPDATE` to serialize refresh attempts.
- Reuse of a token that has a `REPLACED_BY_ID` link revokes the session family.
- Logout revokes the full family, not just the current access token.
- The middleware rechecks employee active status for each protected access-token request.
- A simultaneous second refresh may be treated as replay and revoke the family. The Flutter client should implement a single-flight refresh operation.
- Rate limiting, HTTPS enforcement, mobile secure token storage, and the legacy password encoder migration remain separate hardening tasks.
