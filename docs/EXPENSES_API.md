# Expenses API

`GET /api/v1/expenses` returns the authenticated employee's monthly expense
summaries for the three most recently completed calendar months. The period is
calculated using the Oracle database date; for November 2026, the response
contains October, September, and August 2026, in that order.

The route uses the standard business authentication and employee-access
middleware. Data comes from the legacy `SFM_MONTHLY_EXPENSE` header and
`SFM_MONTHLY_EXPENSE_DET` detail tables, with expense names resolved from
`SFM_EXPENSE`.

Example response data:

```json
{
  "months": [
    {
      "month": "2026-10",
      "label": "Oct-2026",
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
      "status": "notSubmitted",
      "status_code": null,
      "total": 0,
      "items": []
    }
  ]
}
```

The response always includes all three months, even when no monthly expense
header exists. Legacy status codes `A` (authorised) and `N` (pending
authorisation) map to `submitted`; `X` maps to `draft`; a missing header maps
to `notSubmitted`. The `remark` field is empty because legacy monthly detail
rows do not store per-item remarks.
