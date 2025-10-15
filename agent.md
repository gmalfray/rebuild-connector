# agent.md — Rebuild Connector (PrestaShop)
speak french
## PROJECT CONTEXT
Custom PrestaShop module exposing a secure REST API (JSON) for the **PrestaFlow** Android app.
Provides access to orders, products, customers, and dashboard metrics.

## REQUIREMENTS
- PrestaShop >= 1.7
- PHP >= 7.4
- HTTPS enabled
- MySQL access

## MODULE STRUCTURE
```
rebuildconnector/
 ├─ controllers/front/
 │   ├─ ApiController.php        # main REST router
 │   ├─ OrdersController.php
 │   ├─ ProductsController.php
 │   ├─ CustomersController.php
 │   └─ DashboardController.php
 ├─ classes/
 │   ├─ AuthService.php
 │   ├─ JwtService.php
 │   ├─ OrdersService.php
 │   ├─ ProductsService.php
 │   ├─ DashboardService.php
 │   └─ FcmService.php
 ├─ config/
 │   └─ config.xml
 ├─ rebuildconnector.php         # module bootstrap
 └─ upgrade/                     # upgrade scripts
```

## ÉTAT ACTUEL
- Structure des dossiers créée avec contrôleurs front retournant des réponses JSON de placeholder.
- Services métier (AuthService, OrdersService, etc.) présents avec méthodes TODO à implémenter.
- `rebuildconnector.php` installe les hooks actionValidateOrder et actionOrderStatusPostUpdate et affiche un gabarit d’administration minimal.
- Vue d’administration `views/templates/admin/configure.tpl` présente avec message informatif.

## ENDPOINTS (JSON)
Base path: `/module/rebuildconnector/api/`

| Method | Endpoint | Description |
|--------|-----------|-------------|
| POST | /connector/login | Auth → JWT |
| GET | /orders | List orders |
| GET | /orders/{id} | Order detail |
| PATCH | /orders/{id}/status | Change status |
| PATCH | /orders/{id}/shipping | Update tracking |
| GET | /products | List products |
| PATCH | /products/{id} | Edit product (price, active) |
| PATCH | /products/{id}/stock | Update stock |
| GET | /customers | List customers |
| GET | /dashboard/metrics | KPIs & chart data |

## SECURITY
- HTTPS required (enforce HSTS)
- JWT auth (HS256 / RS256)
- Token TTL: 60–90 min
- Role-based scopes (orders.read, stock.write, etc.)
- Rate limiting (default 60 req/min/IP)
- Audit logs (action, user, IP, timestamp)

## HOOKS
- `actionValidateOrder` → trigger FCM notification (order.created)
- `actionOrderStatusPostUpdate` → trigger (order.status.changed)
- `actionCarrierUpdate` → optional tracking updates

## NOTIFICATIONS
Firebase Cloud Messaging (HTTP v1)
- FCM service account JSON stored in module config
- Function: `FcmService::sendPush($tokens, $payload)`
- Example payload:
```json
{
  "title": "Nouvelle commande",
  "body": "Commande #456 - 72,90 €",
  "data": { "type": "order", "order_id": 456 }
}
```

## BUILD & PACKAGE
```bash
zip -r rebuildconnector.zip rebuildconnector/
```

## TESTS
- PHPUnit (services, controllers)
- Postman collection for endpoint tests
- Integration tests on PrestaShop 1.7.8 & 8.x

## CI/CD
- PHPStan level 6+, PHPUnit
- GitHub Actions / GitLab CI for packaging and release
- Auto-version & changelog

## LICENCE
- Proprietary code (© Rebuild IT 2025)
- No GPL code reused (inspired only from legacy connector)
