# Expenses API

`GET /api/v1/expenses` returns the authenticated employee's current calendar
month and the three most recently completed calendar months. The period is
calculated using the Oracle database date; for November 2026, the response
contains November, October, September, and August 2026, in that order. The
current month is marked with `is_current`; the app shows it by default and
uses the other three periods as month-selection buttons.

The route uses the standard business authentication and employee-access
middleware. Allowance rows come from `SFM_MONTHLY_EXPENSE` and
`SFM_MONTHLY_EXPENSE_DET`; daily expense rows come from `SFM_DAILY_EXPENSE`.
Expense names are resolved from `SFM_EXPENSE`. Monthly allowance details with
the same name are combined, and the total is the sum of the returned allowance
and daily expense rows.

Example response data:

```json
{
  "months": [
    {
      "month": "2026-11",
      "label": "Nov-2026",
      "is_current": true,
      "status": "notSubmitted",
      "status_code": null,
      "total": 250,
      "items": [
        {"type": "Telephone Expense", "amount": 250, "remark": "Mobile Recharge"}
      ]
    },
    {
      "month": "2026-10",
      "label": "Oct-2026",
      "is_current": false,
      "status": "submitted",
      "status_code": "A",
      "total": 10705,
      "items": [
        {"type": "HQ Allowance", "amount": 4500, "remark": ""}
      ]
    },
    {
      "month": "2026-09",
      "label": "Sep-2026",
      "is_current": false,
      "status": "notSubmitted",
      "status_code": null,
      "total": 0,
      "items": []
    },
    {
      "month": "2026-08",
      "label": "Aug-2026",
      "is_current": false,
      "status": "notSubmitted",
      "status_code": null,
      "total": 0,
      "items": []
    }
  ]
}
```

The response always includes all four months, even when no monthly expense
header exists. A month without a header has a null status code and maps to
`notSubmitted`. Legacy status codes `A` (authorised) and `N` (pending
authorisation) map to `submitted`; `X` maps to `draft`; a missing header maps
to `notSubmitted`. The `remark` field is empty for monthly allowance details;
daily expense rows carry their stored remark.
