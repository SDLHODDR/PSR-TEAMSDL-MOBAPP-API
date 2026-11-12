# Price list API

`GET /api/v1/price-list` requires the employee bearer token and employee-access
middleware. The API returns active sellable products with their effective rate
for the employee's currently effective divisions. Products are grouped by the
effective `SFM_ITEM_GROUP_MAP` category and ordered by the category sequence,
matching the legacy price-list query.

The response contains `effective_date` and an ordered `categories` array.
Each category has `category_id`, `name`, `sequence`, and `products`; each
product has `product_id`, `name`, and numeric `rate`.
