# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Bitrix CMS module** (`imarket.catalog_app`) that integrates with the external [Catalog.app](https://catalog.app) service. It synchronizes product catalog data (categories, products, prices, properties, vendors) from Catalog.app into a Bitrix e-commerce site.

## Architecture

### Core Classes (`classes/`)
- **catalogAppAPI** - REST API client for catalog.app (authentication, endpoints for catalogs, categories, models, vendors, pricing profiles)
- **RestAPI** - Business logic layer that wraps catalogAppAPI with Bitrix database operations
- **ImarketLogger** - Custom logging utility; logs stored in `/upload/log/{WorkerName}/`
- **ImarketTriggers** - Telegram notification service for error alerts
- **ImarketSectionsConnections** - Manages mapping between Catalog.app sections and Bitrix catalog sections
- **CatalogAppAgents** - Bitrix agent definitions (scheduled tasks registered with Bitrix)
- **DeliveryEventHandler** - Event handler for delivery time calculations in cart

### Services (`services/`)
- **DeliveryTimeService** - Calculates and formats delivery times based on city and delivery type
- **RedirectService** - Manages product redirect links

### Workers (`workers/`)
Background jobs executed via cron. Each worker checks its status in `catalog_app_workers` table before running:

| Worker | Schedule | Purpose |
|--------|----------|---------|
| CreateWorker.php | Hourly | Creates new products from Catalog.app |
| UpdatePriceWorker.php | Every 3 min | Updates product prices |
| UpdatePropertiesWorker.php | Hourly | Updates product properties |
| DeleteGoodsWorker.php | Daily | Removes deleted products |
| MoveGoodsInSectionWorker.php | Hourly | Moves products between catalog sections |
| checkWorkers.php | Every 5 min | Health monitoring, sends Telegram alerts |
| ClearLogFolders.php | Daily | Cleans up log files older than 1 week |

### Admin Interface (`admin/`)
- **app.php** - Main settings page at `/bitrix/admin/imarket.catalog_app_app.php`
- **menu.php** - Bitrix admin menu registration

### Database Tables
- `catalog_app_settings` - Module configuration
- `catalog_app_rules` - Pricing profile mapping rules
- `catalog_app_tasks` - Task queue for Catalog.app operations
- `catalog_app_workers` - Worker status tracking (busy flag)
- `catalog_app_section_connections` - Section mapping (Catalog.app ID ↔ Bitrix section ID)
- `catalog_app_data` - Cached catalog data from Catalog.app

### Module Settings
Settings stored via `Bitrix\Main\Config\Option` with module ID `imarket.catalog_app`:
- `CATALOG_APP_USER`, `CATALOG_APP_PASSWORD` - API credentials
- `CATALOG_APP_CATALOG_ID` - Catalog ID in Catalog.app
- `CATALOG_IBLOCK_ID` - Target Bitrix infoblock
- `CREATE_WORKER`, `UPDATE_WORKER`, `UPDATE_PROPERTY_WORKER` - Worker enable flags
- `TELEGRAM_BOT_TOKEN`, `TELEGRAM_USERS_ID` - Notification settings

## Cron Setup

Workers run from `/local/modules/imarket.catalog_app/` (installed location):
```bash
0 */1 * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/CreateWorker.php
0 */1 * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/UpdatePropertiesWorker.php
*/3 * * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/UpdatePriceWorker.php
0 0 * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/DeleteGoodsWorker.php
0 */1 * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/MoveGoodsInSectionWorker.php
*/5 * * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/checkWorkers.php
0 1 * * * /usr/bin/php /path/to/site/local/modules/imarket.catalog_app/ClearLogFolders.php
```

## Development Notes

### External API
All Catalog.app API calls go through `catalogAppAPI` class:
- Base URL: `https://catalog.app/api/`
- Authentication: JWT token via `/api/authorization`
- Endpoints: catalogs, categories, models, vendors, pricing-profiles, suppliers

### Worker Pattern
Each worker follows this pattern:
1. Check status via `WorkersChecker` (skip if already running)
2. Set busy status
3. Fetch data from Catalog.app API
4. Process/sync with Bitrix database
5. Clear busy status
6. Log operations to `/upload/log/{WorkerName}/`

### Localization
Russian language files in `lang/ru/`. Use `Bitrix\Main\Localization\Loc::getMessage()` for translations.

### Installation
Module installs to `/bitrix/modules/imarket.catalog_app/` and creates worker stubs in `/local/modules/imarket.catalog_app/`.

### Delivery Time Calculation
The module provides delivery time calculation via `DeliveryTimeService`:

**Rules by city and delivery type:**
| City | Courier | Pickup |
|------|---------|--------|
| Almaty | catalog.app + 1 day, range format | catalog.app days (no change) |
| Astana | catalog.app + 1 day, range format | catalog.app + 1-2 days, range format |

**Usage:**
```php
$service = new DeliveryTimeService();
$time = $service->getDeliveryTime($productId, 'almaty', 'courier'); // "2-3 дня"
```

**Event Integration:**
`DeliveryEventHandler` hooks into `onSaleDeliveryServiceCalculate` event and modifies `CalculationResult` directly via `setPeriodFrom()` / `setPeriodTo()` / `setPeriodDescription()`. The handler:
1. Gets products from shipment item collection (`getShipmentItemCollection()`)
2. Determines city from order's delivery location (`getDeliveryLocation()`)
3. Determines delivery type (courier/pickup) by delivery service ID
4. Calculates max delivery time across shipment products
5. Sets period and description on `CalculationResult`

**Registration:** via `init.php` (recommended) or `include.php` module. See [INTEGRATION.md](INTEGRATION.md).

Data source: `catalog_app_data.DELIVERY_TIME` field (synced from Catalog.app).

**Configuration required:**
- City location codes mapping in `DeliveryEventHandler::getCityFromLocation()`
- Pickup service IDs in `DeliveryEventHandler::getDeliveryType()`
