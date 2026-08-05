# Magento2 MCP - Report Tools

This is a sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module)

----

Report-domain MCP tools for `Magebit_Mcp`. Exposes the admin **Reports** menu
(sales, tax, customer, product, review, search-term, cart, newsletter), the
admin **Dashboard** summary, a **Customers Online** live view, and the
**Statistics** refresh machinery — all as MCP tools.

Each read tool is a thin wrapper over the same Magento collection or service
the admin grid uses, so row counts and totals match the admin UI exactly.
Statistics refresh tools dispatch the same aggregation models as
`Magento\Reports\Controller\Adminhtml\Report\Statistics\RefreshRecent` /
`RefreshLifetime`.

## Install

```bash
composer require magebitcom/magento2-mcp-report-tools
bin/magento module:enable Magebit_McpReportTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Tool catalog

### Shopping cart (live)

| Tool | What it does |
|---|---|
| `reports.cart.products` | Products currently sitting in open carts with quantities and carts count. |
| `reports.cart.abandoned` | Quotes that started checkout but were not converted. Optional `from`/`to` (YYYY-MM-DD) narrow the report by last-updated date. |

### Marketing (live)

| Tool | What it does |
|---|---|
| `reports.marketing.search_terms` | Storefront search queries with popularity and number of matching products. |
| `reports.marketing.newsletter_problems` | Newsletter queue problem reports (bounces, send failures). |

### Reviews (live)

| Tool | What it does |
|---|---|
| `reports.reviews.by_product` | Products ranked by review count with average rating. |
| `reports.reviews.by_customer` | Customers ranked by review count. |

### Sales (aggregated)

All sales reports read from the `*_aggregated_created` / `*_aggregated_updated`
tables populated by `reports.statistics.refresh_*`. Arguments are shared:
`from`, `to`, `period` (day/month/year), `store_id?`, `show_empty?`,
`order_statuses?`.

| Tool | What it does |
|---|---|
| `reports.sales.orders` | Revenue / profit / quantity / tax / shipping / discount per period. |
| `reports.sales.tax` | Tax collected per rate, per period. |
| `reports.sales.invoiced` | Invoiced vs. paid vs. outstanding per period. |
| `reports.sales.shipping` | Shipping revenue per carrier / method / period. |
| `reports.sales.refunds` | Online and offline refunds per period. |
| `reports.sales.coupons` | Coupon usage and discount amount per rule / period. |

### Customers

| Tool | What it does |
|---|---|
| `reports.customers.orders` | Order count per customer, date-ranged. |
| `reports.customers.totals` | Lifetime / period spend per customer. |
| `reports.customers.new` | Newly registered customer accounts per period. |
| `reports.customers.online` | Live snapshot of visitors (logged in or guest), with cart contents and last activity. |

### Products

| Tool | What it does |
|---|---|
| `reports.products.viewed` | Most-viewed products, aggregated (daily/monthly/yearly). |
| `reports.products.bestsellers` | Bestselling products by quantity sold, aggregated. |
| `reports.products.low_stock` | Products at or below their notify-stock threshold. |
| `reports.products.ordered` | Quantity ordered per product within a date range. |
| `reports.products.downloads` | Downloadable-product link purchases and downloads. |

### Dashboard

| Tool | What it does |
|---|---|
| `reports.dashboard.summary` | One-call roll-up that mirrors the admin Dashboard — lifetime sales, average order, revenue for a period, recent orders, top search terms, top bestsellers. |

### Statistics refresh (write)

Write tools require the global `magebit_mcp/general/allow_writes` flag **and**
the token's own `allow_writes` flag to be `1`. Both refresh tools require
explicit confirmation so MCP clients prompt before firing.

| Tool | Confirm? | What it does |
|---|---|---|
| `reports.statistics.status` | no (read) | Last-refresh timestamp and flag state per aggregation code. |
| `reports.statistics.refresh_recent` | yes | Aggregates the last 25 hours (default) for the selected report codes. Mirrors admin *Refresh Statistics for Last Day*. |
| `reports.statistics.refresh_lifetime` | yes | Aggregates all time for the selected report codes. Mirrors admin *Refresh Lifetime Statistics*. **Heavy** — on stores with 500k+ orders this can take several minutes and will elevate DB load. Run off-peak. |

Both refresh tools also implement `Magebit\Mcp\Api\UnderlyingAclAwareInterface`
with `Magento_Reports::statistics` as the underlying Magento admin resource,
so they block calls from admins who wouldn't be allowed to refresh stats in
the admin UI.

## Upgrade notes

- **Date arguments are now strict ISO.** Every `from` / `to` (and similar)
  date argument across all report tools must be `YYYY-MM-DD`; anything else
  — including US-style `07/27/2026` or other locale formats that some tools
  previously accepted — is now rejected with a validation error instead of
  being silently mis-parsed into the wrong range.
- **`reports.cart.abandoned` changes:**
  - New optional `from` / `to` arguments filter by `updated_at`, evaluated
    as store-timezone calendar days.
  - `remote_ip` is no longer part of the response.
  - `page_size` (default 100, capped at 500) is now actually enforced;
    previously the tool could return the entire unbounded result set.

## Extending

See `docs/EXTENDING.md` for:
- adding a new report tool that reuses the shared search-builder pattern;
- registering a new aggregation code with `AggregationRegistry` (e.g. a
  payment-module-specific settlement report);
- adding a field resolver to enrich an existing report row.

## License

Released under the [MIT License](LICENSE).

---

![magebit (1)](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)

*Have questions or need help? Contact us at info@magebit.com*

