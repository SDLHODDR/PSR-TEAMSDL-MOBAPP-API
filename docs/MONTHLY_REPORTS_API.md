# Monthly reports API

The authenticated monthly report flow is exposed through:

- `GET /api/v1/reports/monthly?month=YYYY-MM`
- `GET /api/v1/reports/daily?date=YYYY-MM-DD`

Both routes use the standard authentication and employee-access middleware.
The monthly response lists every past date in the requested month through
yesterday, including route summaries, approved non-field activities, day-plan
open requests, weekends, and the employee's EPP holidays and approved leave.
Future dates and the current date are omitted, matching the legacy history
screen.

Route summaries include the route town and total POB. Selecting a route day
loads its customer details using the daily endpoint. Details include listed
customers from `SFM_DAY_PLANS_DETAILS` and unlisted customers from
`SFM_DAY_PLANS_UNLISTED`; the employee ID is always taken from the authenticated
token, not a request parameter.

Example monthly response data:

```json
{
  "month": "2026-11",
  "label": "Nov-2026",
  "days": [
    {
      "date": "2026-11-02",
      "day": "02",
      "kind": "route",
      "summary": "Ashoka Marg",
      "route_id": "2",
      "pob": 840,
      "sob": 0,
      "has_details": true
    }
  ]
}
```

Example daily response data:

```json
{
  "date": "2026-11-02",
  "details": [
    {"customer": "Aadhar Medical Store", "pob": 0, "route_id": "2", "unlisted": false},
    {"customer": "Pushpaswaroop Medical", "pob": 840, "route_id": "2", "unlisted": false}
  ]
}
```
