# JWT-only authentication: initial release

The initial SDL PSR APK release uses stateless RS256 access JWTs. Login still validates the employee credentials against Oracle, but it does not issue refresh tokens and does not insert access-token hashes into `SFM_APPUSER_AUTH_TOKENS`.

## Configuration

Set these environment variables in the server environment or `.env` file:

```text
JWT_PRIVATE_KEY_PATH=C:/secure/sdl-psr/jwt-private.pem
JWT_PUBLIC_KEY_PATH=C:/secure/sdl-psr/jwt-public.pem
JWT_ISSUER=sdl-psr-api
JWT_AUDIENCE=sdl-psr-mobile
JWT_CLOCK_SKEW_SECONDS=30
```

The private key must remain outside `public/` and must not be committed. The public key is used only for local verification. The configured algorithm is strictly RS256.

## Flow

1. `POST /api/v1/auth/login` validates the username and password through the existing Oracle employee-login query.
2. The server signs one JWT with a lifetime of exactly 1,800 seconds (30 minutes).
3. Protected requests send `Authorization: Bearer <JWT>`.
4. `AuthenticationMiddleware` verifies the signature and claims locally without Oracle token-table lookup.
5. The middleware supplies the employee ID from `sub` to downstream controllers.
6. `GET /api/v1/auth/me` uses that employee ID to retrieve the current EmployeeContext from Oracle.

Required JWT claims are `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`, and `jti`. The existing `login_id` is included for backend audit fields such as tour-plan changes; passwords and EmployeeContext are never included.

## Responses

Successful login retains the existing response envelope and returns no refresh token:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token_type": "Bearer",
    "access_token": "<JWT>",
    "expires_in": 1800,
    "expires_at": "<ISO-8601 timestamp>",
    "access_expires_at": "<ISO-8601 timestamp>"
  }
}
```

`auth/me` retains the existing EmployeeContext shape:

```json
{
  "success": true,
  "message": "Authenticated employee profile.",
  "data": { "employee": { "emp_id": 123 } },
  "meta": { "version": "v1" }
}
```

Missing, malformed, expired, incorrectly signed, wrong-issuer, wrong-audience, and unsupported-algorithm JWTs return HTTP 401. A small 30-second clock-skew allowance is configurable and capped at five minutes.

## Logout and future refresh support

`POST /api/v1/auth/logout` returns success for API consistency, but logout is client-side: an issued JWT remains valid until its expiry, up to 30 minutes after logout. The refresh endpoint is not registered for the initial release. The legacy token model/table code is retained but inactive. Refresh-token issuance, rotation, revocation, and token-table migration are planned for a later release.
