# Rebuild Connector API Reference

Toutes les routes sont exposées sous `https://<boutique>/module/rebuildconnector`. L’API accepte et retourne du JSON UTF‑8.

## Authentication

```http
POST /module/rebuildconnector/api
Content-Type: application/json

{
  "api_key": "<clé fournie dans le back-office>",
  "shop_url": "https://example.com" // optionnel
}
```

**Réponse 200**

```json
{
  "token_type": "Bearer",
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI...",
  "expires_in": 3600,
  "issued_at": "2025-02-01T10:00:00Z",
  "expires_at": "2025-02-01T11:00:00Z",
  "scopes": ["orders.read", "products.write"]
}
```

Ensuite ajouter l’en-tête `Authorization: Bearer <access_token>` sur chaque requête.

## Endpoints

### GET `/orders`

Liste paginée des commandes.

| Paramètre     | Type   | Description                          |
|---------------|--------|--------------------------------------|
| `limit`       | int    | 1–100 (défaut 20)                    |
| `offset`      | int    | Décalage (défaut 0)                  |
| `status`      | int/string | ID état ou libellé partiel    |
| `customer_id` | int    | Filtre client                        |
| `date_from`   | date   | ISO 8601                             |
| `date_to`     | date   | ISO 8601                             |
| `search`      | string | Référence / email / nom / prénom     |

**Réponse 200**

```json
{
  "data": [
    {
      "id": 123,
      "reference": "XZY001",
      "status": { "id": 4, "name": "Shipped" },
      "totals": { "paid_tax_incl": 72.9, "currency": "EUR" },
      "customer": { "id": 50, "firstname": "Anna", "lastname": "Dupont" },
      "shipping": { "carrier_id": 3, "tracking_number": "6A..." },
      "items": [ { "product_id": 88, "name": "T-shirt", "quantity": 2 } ],
      "dates": { "created_at": "2025-02-01T09:15:00", "updated_at": "..." }
    }
  ]
}
```

### GET `/orders/{id}`

Retourne la commande avec historique et lignes. `404 not_found` si absente.

### PATCH `/orders/{id}`

| Action                | Corps attendu                            |
|-----------------------|------------------------------------------|
| `action=status`       | `{ "status": "shipped" }`                |
| `action=shipping`     | `{ "tracking_number": "6A12345..." }`    |

Réponse `204` en cas de succès. Erreurs : `400 invalid_payload`, `404 not_found`.

### GET `/products`

Liste ou recherche produit.

| Paramètre          | Type   | Description                     |
|--------------------|--------|---------------------------------|
| `limit` / `offset` | int    | Pagination                      |
| `filter[active]`   | bool   | `1` actifs / `0` inactifs       |
| `search`           | string | Nom, référence                  |

**Réponse 200 (extrait)**

```json
{
  "data": [
    {
      "id": 88,
      "name": "T-shirt noir",
      "reference": "TSHIRT-BLACK",
      "price_tax_excl": 15.9,
      "price_tax_incl": 19.08,
      "quantity": 24,
      "cover_image": {
        "id": 101,
        "is_cover": true,
        "url": "https://example.com/img/88-101-large_default.jpg",
        "urls": {
          "thumbnail": "https://example.com/img/88-101-home_default.jpg",
          "large": "https://example.com/img/88-101-large_default.jpg"
        }
      }
    }
  ]
}
```

### GET `/products/{id}`

Renvoie la fiche produit détaillée (équivalent d’un `GET /products` ciblé) avec la liste complète des images.

**Réponse 200 (extrait)**

```json
{
  "data": {
    "id": 88,
    "name": "T-shirt noir",
    "cover_image": {
      "id": 101,
      "is_cover": true,
      "url": "https://example.com/img/88-101-large_default.jpg",
      "urls": {
        "thumbnail": "https://example.com/img/88-101-home_default.jpg",
        "large": "https://example.com/img/88-101-large_default.jpg"
      }
    },
    "images": [
      {
        "id": 101,
        "is_cover": true,
        "legend": "Face avant",
        "position": 1,
        "url": "https://example.com/img/88-101-large_default.jpg",
        "urls": {
          "thumbnail": "https://example.com/img/88-101-home_default.jpg",
          "large": "https://example.com/img/88-101-large_default.jpg"
        }
      }
    ]
  }
}
```

### PATCH `/products/{id}/stock`

```http
PATCH /module/rebuildconnector/products/88/stock
{
  "quantity": 15
}
```

Réponse `204`.

### GET `/customers`

Filtres avancés (segment, montants, dates) et pagination offset.

```http
GET /module/rebuildconnector/customers?limit=20&filter[segment]=vip
Authorization: Bearer <JWT>
```

**Réponse 200**

```json
{
  "data": [
    {
      "id": 10,
      "firstname": "Léa",
      "lastname": "Martin",
      "email": "lea@example.com",
      "orders_count": 3,
      "total_spent": 240.5,
      "last_order_date": "2024-12-01 09:15:00"
    }
  ],
  "meta": {
    "pagination": { "limit": 20, "offset": 0, "count": 1, "has_next": false },
    "filters": { "segment": "vip" }
  }
}
```

### GET `/customers/{id}`

Renvoie la fiche client + 10 dernières commandes. `404` sinon.

### GET `/dashboard/metrics`

| Paramètre | Type | Description                             |
|-----------|------|-----------------------------------------|
| `period`  | enum | `day`, `week`, `month`, `year`          |
| `from/to` | date | ISO 8601 facultatives                   |

**Réponse :**

```json
{
  "data": {
    "revenue": { "total": 1250.9, "currency": "EUR" },
    "orders": { "count": 12 },
    "top_products": [ { "id_product": 88, "name": "T-shirt noir", "quantity": 24 } ]
  }
}
```

## Errors

| Code | Raison                 |
|------|------------------------|
| 400  | `invalid_request`      |
| 401  | `unauthenticated`      |
| 403  | `forbidden`            |
| 404  | `not_found`            |
| 405  | `method_not_allowed`   |
| 429  | `too_many_requests`    |
| 500  | `server_error`         |

## cURL Example

```bash
curl -X GET "https://example.com/module/rebuildconnector/orders?limit=5" \
  -H "Authorization: Bearer <JWT>" \
  -H "Accept: application/json"
```
