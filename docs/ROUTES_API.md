# Route and customer consent API

All endpoints require the employee bearer token and employee-access middleware.
Route IDs and customer IDs are numeric database identifiers.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/routes` | List active employee routes with customer and verification counts |
| GET | `/api/v1/routes/{routeId}/customers` | List active customers on the route, including verification status |
| GET | `/api/v1/routes/{routeId}/customers/{customerId}` | Read the route customer profile |
| PUT | `/api/v1/routes/{routeId}/customers/{customerId}` | Save an unverified customer profile |
| POST | `/api/v1/routes/{routeId}/customers/{customerId}/consent/otp` | Queue a consent OTP |
| POST | `/api/v1/routes/{routeId}/customers/{customerId}/consent/verify` | Verify a consent OTP |

The profile update body uses `address`, `state`, `city`, `pincode`,
`landmark`, `location_url`, `personal_email`, `business_email`,
`primary_phone`, `additional_phone`, and `owner_name`.

Requesting an OTP requires `{"consent": true}`. The API writes a four-digit OTP
to `SFM_OUTBOX_OTP`, following the legacy consent flow, and permits another
request after the existing two-minute cooldown. **This API currently queues the
OTP but does not send an SMS.** SMS gateway delivery must be configured
separately before production use. OTP values are never returned by the API.
