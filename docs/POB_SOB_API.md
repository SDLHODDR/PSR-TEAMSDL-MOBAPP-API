# POB/SOB orders API

All POB/SOB endpoints below require the authenticated employee bearer token.

## Monthly orders

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
          "order_id": 710,
          "source": "CP",
          "customer": "Aadhar Medical Store",
          "order_to": "Aadhar Medical Store",
          "type": "POB",
          "amount": 1250,
          "status": "A",
          "process_flag": "Y",
          "otp_available": true,
          "verified_at": "2026-10-01 10:30 AM",
          "plan_id": 4501
        }
      ]
    }
  ]
}
```

## Form options

- `GET /api/v1/pob-sob/options` returns today's eligible customers and products.
- `GET /api/v1/pob-sob/options?customer_id=<id>` also returns that customer's
  eligible order-to parties.

## Order details and invoice data

`GET /api/v1/pob-sob/orders/<order_id>?source=PO|CP|CS` returns the employee,
customer, order-to party, date, total, status, and item lines. The mobile app
uses this response for order viewing and generating the invoice-style PDF.

## Save and confirm

`POST /api/v1/pob-sob/orders` creates a draft. Include `order_id` to edit the
authenticated employee's unconfirmed order.

```json
{
  "date": "YYYY-MM-DD",
  "customer_id": 123,
  "order_to_id": 456,
  "items": [
    {"product_id": 789, "quantity": 2, "free_quantity": 1}
  ]
}
```

Only today's database date is accepted for create/edit. Rates are resolved by
the API from the active item/division setup; client rates and amounts are not
accepted.

`POST /api/v1/pob-sob/orders/<order_id>/confirm` confirms the draft and invokes
the legacy `PROCESS_POBSOB` database procedure. Only the authenticated
employee's unconfirmed order for today can be confirmed. Legacy OTP
send/verification actions are not exposed by these endpoints.
