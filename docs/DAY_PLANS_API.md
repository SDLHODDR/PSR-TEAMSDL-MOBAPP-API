# Day Plans API

All endpoints require an authenticated employee with business access.

## Load a day plan

`GET /api/v1/day-plans?date=YYYY-MM-DD`

Returns the day-plan header (if one exists), its selected listed and unlisted customers, the tour-plan route for that date, available routes, start towns, customer types, qualifications, and previous unlisted-customer names.

## Load options

`GET /api/v1/day-plans/options?date=YYYY-MM-DD`

Returns employee-scoped start towns and customer-entry options.

## Save a draft

`POST /api/v1/day-plans/draft`

```json
{
  "date": "2025-06-18",
  "start_town_id": 5827,
  "remarks": "Customer visits"
}
```

The API creates or updates the employee's plan for the date. Submitted (`T`) and confirmed (`A`) plans are read-only.

## Select listed customers

`GET /api/v1/day-plans/{dayPlanId}/routes/{routeId}/customers`

Returns active customers on the employee's route for the plan date and whether each has already been selected.

`POST /api/v1/day-plans/visits`

```json
{
  "day_plan_id": 123,
  "route_id": 17,
  "customer_ids": [501, 502]
}
```

`customer_ids` is the complete selection for that route; omitted prior visits are removed along with their day-plan activity records.

## Add an unlisted customer

`POST /api/v1/day-plans/unlisted-customers`

```json
{
  "day_plan_id": 123,
  "customer_type_id": 2,
  "name": "Example Customer",
  "qualification": "Other",
  "phone": "5550100",
  "place": "Example Place"
}
```

## Load and save customer activities

`GET /api/v1/day-plans/{dayPlanId}/activities?customer_id=501&activity=pob`

Use exactly one of `customer_id` or `unlisted_customer_id`. Supported activities are `detailing`, `sample`, and `pob`; listed customers also support `availability` when eligible. The response includes the date-effective product catalogue and previously saved selections.

`POST /api/v1/day-plans/activities`

```json
{
  "day_plan_id": 123,
  "customer_id": 501,
  "activity": "pob",
  "products": [
    {"product_id": 77, "quantity": 2, "free_quantity": 1}
  ],
  "remarks": "Order remarks",
  "order_to": "Stockist"
}
```

For an unlisted customer, use `unlisted_customer_id` instead of `customer_id`. The API validates product and customer ownership, gets rates from the effective employee division, and persists the activity to the corresponding legacy day-plan table.

## Submit

`POST /api/v1/day-plans/submit`

```json
{"day_plan_id": 123}
```

At least one listed or unlisted customer is required. The API marks the plan submitted and creates a `DayPlanAuth` task for the active manager.

The dashboard's existing `non_reporting_days` and `open_requests` fields are consumed by the mobile app to show reporting warnings and gate Field Work/Non Field Work navigation.
