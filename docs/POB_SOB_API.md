# POB/SOB orders API

The authenticated monthly POB/SOB order flow is exposed through:

- `GET /api/v1/pob-sob?month=YYYY-MM`

The employee identity, active division, and geography are resolved from the
authenticated session. The response includes each day in the requested month,
daily POB/SOB totals, and order records from `SFM_DAY_PLANS_CUSTPOB`,
`SFM_DAY_PLANS_CUSTSOB`, and `SFM_CUST_POB_ORDER`. POB or SOB classification
uses the legacy `GET_CUST_OBTYPE` function.

Example response data:

```json
{
  "month": "2026-10",
  "label": "October 2026",
  "days": [
    {
      "date": "2026-10-01",
      "day": "01",
      "is_future": false,
      "customer_count": 1,
      "total": 1250,
      "pob_total": 1250,
      "sob_total": 0,
      "orders": [
        {
          "customer_id": 120,
          "customer": "Aadhar Medical Store",
          "order_to": "Aadhar Medical Store",
          "type": "POB",
          "amount": 1250,
          "status": "A",
          "process_flag": "Y",
          "otp_available": true,
          "verified_at": "2026-10-01 10:30 AM",
          "source": "CP",
          "plan_id": 4501
        }
      ]
    }
  ]
}
```

The API is read-only. It does not expose the legacy OTP send/verification
actions.
