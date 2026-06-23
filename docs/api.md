# Rebuild Connector — Référence API

Toutes les routes sont exposées sous `https://<boutique>/module/rebuildconnector/api/...`.
L'API accepte et retourne du JSON UTF-8.

## Authentification

### POST `.../api/connector/login`

Scopes requis : aucun (endpoint public)

Corps JSON :

| Champ      | Type   | Requis | Description                            |
|------------|--------|--------|----------------------------------------|
| `api_key`  | string | oui    | Clé API configurée dans le back-office |
| `shop_url` | string | non    | URL de la boutique (optionnel, ajouté au JWT) |

**Réponse 200**

```json
{
  "token_type": "Bearer",
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_in": 3600,
  "issued_at": "2025-06-19T10:00:00+00:00",
  "expires_at": "2025-06-19T11:00:00+00:00",
  "scopes": ["orders.read", "dashboard.read"]
}
```

> Les champs `access_token` et `token` sont identiques (les deux existent pour compatibilité).

| Champ        | Type     | Description                                                          |
|--------------|----------|---------------------------------------------------------------------|
| `token_type` | string   | Toujours `Bearer`.                                                  |
| `access_token` | string | Le JWT. Identique à `token`.                                        |
| `token`      | string   | Le JWT (alias de `access_token`).                                   |
| `expires_in` | number   | Durée de validité du jeton en secondes.                            |
| `issued_at`  | string   | Date d'émission ISO 8601.                                          |
| `expires_at` | string   | Date d'expiration ISO 8601.                                        |
| `scopes`     | string[] | **Scopes réels du jeton émis** (voir ci-dessous).                  |

**Scopes dynamiques (depuis v1.2.0, multi-utilisateur)** — la structure de la réponse est inchangée,
mais `scopes` contient désormais les scopes **réellement portés par le jeton** :

- **Clé globale legacy** : tous les scopes globaux configurés en back-office (rétrocompatibilité totale).
- **Clé d'un utilisateur nommé** : uniquement le sous-ensemble de scopes attribués à cet utilisateur.

> ⚠️ Côté client : ne **jamais** supposer qu'un scope précis est toujours présent. Le tableau peut
> être un sous-ensemble. L'app PrestaFlow stocke `scopes` mais ne gate aucune fonctionnalité dessus
> (aucun risque de désérialisation : `scopes` reste un `List<String>` non-nullable, toujours présent).

Ajouter ensuite l'en-tête `Authorization: Bearer <access_token>` sur chaque requête protégée.

**Payload du JWT (informatif — opaque côté client)**

Le JWT encode les claims suivants. L'app ne décode pas ce payload (le jeton est traité comme une chaîne
opaque) ; ces champs sont documentés à titre de référence serveur. Depuis v1.2.0, le payload contient
des champs additifs (`id_user`, `id_employee`, `jti`).

```json
{
  "sub": "user:3",          // "prestaflow" pour la clé globale legacy
  "id_user": 3,             // null en mode legacy
  "id_employee": 7,         // null en mode legacy
  "scopes": ["orders.read", "dashboard.read"],
  "shop_url": "https://boutique.example.com",
  "jti": "hex32chars",      // identifiant unique du jeton (nouveau v1.2.0)
  "iss": "https://boutique.example.com",
  "iat": 1750320000,
  "nbf": 1750320000,
  "exp": 1750323600
}
```

### Codes QR de connexion

Deux formats de QR coexistent. Tous deux portent les mêmes champs de base (`module`, `version`,
`shopUrl`, `apiKey`, `api_base_url`) ; le QR utilisateur ajoute des champs **additifs**.

**QR global** (clé legacy) :

```json
{
  "module": "rebuildconnector",
  "version": 1,
  "shopUrl": "https://boutique.example.com",
  "apiKey": "<clé_en_clair>",
  "api_base_url": "https://boutique.example.com/module/rebuildconnector/api"
}
```

**QR utilisateur nommé** (depuis v1.2.0) — ajoute `user_id` et `label` :

```json
{
  "module": "rebuildconnector",
  "version": 1,
  "shopUrl": "https://boutique.example.com",
  "apiKey": "<clé_en_clair>",
  "api_base_url": "https://boutique.example.com/module/rebuildconnector/api",
  "user_id": 3,
  "label": "Préparateur warehouse"
}
```

> L'app PrestaFlow ne lit que `shopUrl` et `apiKey` (parsing `JSONObject.optString`) et ignore tout
> autre champ — les champs `module`, `version`, `api_base_url`, `user_id`, `label` sont tolérés sans
> erreur. Aucune mise à jour applicative requise pour accepter le QR utilisateur.

**Erreurs**

| Code | `error`           | Raison                            |
|------|-------------------|-----------------------------------|
| 400  | `invalid_request` | `api_key` absent ou corps non-JSON|
| 401  | `unauthorized`    | Clé API incorrecte                |
| 500  | `server_error`    | Erreur interne inattendue         |

---

## Commandes

### GET `.../api/orders/statuses`

Scope requis : `orders.read`

Retourne la liste de tous les statuts de commande disponibles dans la boutique (depuis `ps_order_state` + `ps_order_state_lang`, langue courante, hors états supprimés).

**Réponse 200**

```json
{
  "statuses": [
    { "id": 1, "name": "En attente du paiement par chèque", "color": "#4169E1" },
    { "id": 2, "name": "Paiement accepté", "color": "#3498db" },
    { "id": 4, "name": "Expédiée", "color": "#01B887" }
  ]
}
```

| Champ   | Type   | Description                                |
|---------|--------|--------------------------------------------|
| `id`    | int    | ID de l'état (`id_order_state`)            |
| `name`  | string | Libellé dans la langue courante            |
| `color` | string | Couleur hexadécimale de l'état en back-office |

---

### GET `.../api/orders`

Scope requis : `orders.read`

Liste paginée des commandes.

| Paramètre     | Type       | Description                                    |
|---------------|------------|------------------------------------------------|
| `limit`       | int        | Nombre max de résultats (défaut 20, min 1)     |
| `offset`      | int        | Décalage de pagination (défaut 0)              |
| `customer_id` | int        | Filtre par ID client                           |
| `status`      | int/string | ID d'état numérique ou libellé partiel (LIKE)  |
| `date_from`   | datetime   | Filtre `date_add >=` (format Y-m-d H:i:s)      |
| `date_to`     | datetime   | Filtre `date_add <=` (format Y-m-d H:i:s)      |
| `search`      | string     | Recherche sur référence, prénom, nom, email    |

**Réponse 200**

```json
{
  "orders": [
    {
      "id": 123,
      "reference": "ABCDEF123",
      "status": "Expédiée",
      "total_paid": 72.90,
      "currency": "EUR",
      "date_add": "2025-05-20 09:15:00",
      "date_upd": "2025-06-01 14:30:00",
      "customer": {
        "id": 50,
        "firstname": "Anna",
        "lastname": "Dupont"
      }
    }
  ]
}
```

> Note : dans la liste (`getOrders`), `status` est une chaîne (le libellé d'état). Ce n'est **pas** `{id, name}` — cette structure enrichie n'existe que sur l'endpoint de détail. La liste est volontairement allégée (pas de `shipping`/`items`/`history`).
> `date_add` (date de création de la commande) et `date_upd` (dernière mise à jour) sont tous deux exposés ; pour l'affichage « date de commande », utiliser **`date_add`**.

---

### GET `.../api/orders/{id}`

Scope requis : `orders.read`

Détail d'une commande avec historique et lignes de produits.

**Réponse 200**

```json
{
  "order": {
    "id": 123,
    "reference": "ABCDEF123",
    "status": {
      "id": 4,
      "name": "Expédiée"
    },
    "totals": {
      "paid_tax_incl": 72.90,
      "paid_tax_excl": 60.75,
      "currency": "EUR"
    },
    "customer": {
      "id": 50,
      "firstname": "Anna",
      "lastname": "Dupont",
      "email": "anna@example.com"
    },
    "shipping": {
      "carrier_id": 3,
      "carrier_name": "Colissimo",
      "tracking_number": "6A12345678901"
    },
    "dates": {
      "created_at": "2025-05-20 09:15:00",
      "updated_at": "2025-06-01 14:30:00"
    },
    "items": [
      {
        "product_id": 88,
        "name": "T-shirt noir",
        "reference": "TSHIRT-BLACK",
        "quantity": 2,
        "price_tax_incl": 38.16,
        "price_tax_excl": 31.80
      }
    ],
    "history": [
      {
        "order_state_id": 1,
        "status": "En attente de paiement",
        "date_add": "2025-05-20 09:15:00"
      },
      {
        "order_state_id": 4,
        "status": "Expédiée",
        "date_add": "2025-06-01 14:00:00"
      }
    ],
    "has_invoice": true,
    "shipping_label": {
      "has_shipping_label": true,
      "carrier_type": "colissimo"
    }
  }
}
```

`carrier_type` vaut `"colissimo"`, `"mondialrelay"` ou `null` si le transporteur n'est pas reconnu.  
`has_shipping_label` est `false` même si `carrier_type` est non nul (ex : Colissimo détecté mais PDF pas encore généré).

**Erreurs** : `404 not_found` si la commande n'existe pas.

---

### GET `.../api/orders/{id}/shipping-label`

Scope requis : `orders.read`

Paramètre URL `action=shipping-label` ou path `/orders/{id}/shipping-label`.

Stream le PDF du bordereau d'expédition.

- **Colissimo** : lit le fichier local `modules/colissimo/documents/labels/{id}-{tracking}.pdf`.
- **Mondial Relay** : proxy cURL vers `label_url` stockée en base (SSL vérifié, timeout 10 s).
- Contrôle IDOR `id_shop` appliqué.

**Réponse 200**

```
Content-Type: application/pdf
Content-Disposition: attachment; filename="bordereau-colissimo-6120-6A05528333890.pdf"

<binaire PDF>
```

**Erreurs** :
- `404 not_found` : commande inexistante, transporteur non géré, fichier absent/supprimé, URL expirée.
- `401 unauthenticated` : token manquant ou invalide.
- `403 forbidden` : scope insuffisant.

---

### PATCH `.../api/orders/{id}`  — Changer le statut

Scope requis : `orders.write`

Paramètre URL `action=status` (ou détecter depuis le corps si `payload.status` présent).

Corps JSON :

| Champ    | Type       | Description                               |
|----------|------------|-------------------------------------------|
| `status` | int/string | ID d'état numérique ou libellé exact/partiel |

```json
{ "status": "4" }
```

ou

```json
{ "status": "Expédiée" }
```

**Réponse 204** (corps vide) en cas de succès.

**Erreurs** :

| Code | `error`           | Raison                                 |
|------|-------------------|----------------------------------------|
| 400  | `invalid_payload` | Champ `status` absent ou état inconnu  |
| 404  | `not_found`       | Commande introuvable                   |

---

### PATCH `.../api/orders/{id}`  — Mettre à jour l'expédition

Scope requis : `orders.write`

Paramètre URL `action=shipping` (ou détecter depuis le corps si `payload.tracking_number` présent).

Corps JSON :

| Champ            | Type | Description                                |
|------------------|------|--------------------------------------------|
| `tracking_number`| string | Numéro de suivi (requis, non vide)       |
| `carrier_id`     | int  | ID transporteur (optionnel, `null` = garder l'existant) |

```json
{ "tracking_number": "6A12345678901", "carrier_id": 3 }
```

**Réponse 204** (corps vide) en cas de succès.
Déclenche une notification FCM si les notifications d'expédition sont activées.

**Erreurs** :

| Code | `error`           | Raison                                 |
|------|-------------------|----------------------------------------|
| 400  | `invalid_payload` | `tracking_number` absent ou `carrier_id` invalide |
| 404  | `not_found`       | Commande introuvable                   |

---

## Produits

### GET `.../api/products`

Scope requis : `products.read`

Liste paginée de produits.

| Paramètre | Type   | Description                                             |
|-----------|--------|---------------------------------------------------------|
| `limit`   | int    | Nombre max de résultats (défaut 20, min 1)              |
| `offset`  | int    | Décalage de pagination (défaut 0)                       |
| `active`  | int    | `1` = actifs uniquement, `0` = inactifs uniquement. **Si absent : aucun filtre, tous les produits sont retournés.** |
| `search`  | string | Recherche sur nom ou référence                          |
| `ids`     | string | Liste d'IDs séparés par virgule (`ids=88,89,90`)        |
| `stock`   | string | Filtre par état de stock : `in_stock`, `out_of_stock`, `low_stock` |

**Valeurs du filtre `stock`**

| Valeur        | Condition                                          |
|---------------|----------------------------------------------------|
| `in_stock`    | `quantity > 0`                                     |
| `out_of_stock`| `quantity <= 0`                                    |
| `low_stock`   | `0 < quantity <= low_stock_threshold` (seuil par produit ou défaut 5) |

**Réponse 200**

```json
{
  "products": [
    {
      "id": 88,
      "name": "T-shirt noir",
      "reference": "TSHIRT-BLACK",
      "price": 19.08,
      "active": true,
      "stock": {
        "quantity": 3,
        "low_stock_threshold": 5,
        "is_low": true,
        "warehouse_id": null,
        "updated_at": "2025-06-01 12:00:00"
      },
      "images": [
        {
          "id": 101,
          "url": "https://example.com/101-88-large_default.jpg"
        }
      ],
      "updated_at": "2025-06-01 12:00:00"
    }
  ],
  "total": 324
}
```

**Champs `stock.*` ajoutés (v1.4.2)**

| Champ                 | Type | Description                                                                   |
|-----------------------|------|-------------------------------------------------------------------------------|
| `stock.low_stock_threshold` | int  | Seuil de stock faible effectif : `product_shop.low_stock_threshold` si > 0, sinon 5 (défaut global). |
| `stock.is_low`        | bool | `true` si `0 < quantity <= low_stock_threshold`.                              |

**Champ `total` ajouté (v1.4.3)**

| Champ   | Type | Description                                                                        |
|---------|------|------------------------------------------------------------------------------------|
| `total` | int  | Nombre total de produits correspondant aux filtres actifs, indépendamment de la pagination (`limit`/`offset` ignorés). Permet à l'app d'afficher un compteur global et de calculer le nombre de pages. |

> `price` est le prix TTC (`Product::getPriceStatic($id, true)`). Le prix HT brut est disponible sur le détail produit (`price_tax_excl` PATCH uniquement).
> Toute valeur du filtre `stock` autre que les trois valeurs listées retourne `400 invalid_payload`.
> **v1.4.3** — Corrige un bug où l'absence du paramètre `active` appliquait un filtre `p.active = 0` non désiré, causant le retour de produits inactifs uniquement et une liste tronquée.

---

### GET `.../api/products/{id}`

Scope requis : `products.read`

Fiche produit détaillée. La réponse est identique à un élément de la liste mais inclut toutes les images.

**Réponse 200**

```json
{
  "product": {
    "id": 88,
    "name": "T-shirt noir",
    "reference": "TSHIRT-BLACK",
    "price": 19.08,
    "active": true,
    "stock": {
      "quantity": 24,
      "low_stock_threshold": 5,
      "is_low": false,
      "warehouse_id": null,
      "updated_at": "2025-06-01 12:00:00"
    },
    "images": [
      {
        "id": 101,
        "is_cover": true,
        "legend": "Face avant",
        "position": 1,
        "url": "https://example.com/101-88-large_default.jpg",
        "urls": {
          "thumbnail": "https://example.com/101-88-home_default.jpg",
          "large": "https://example.com/101-88-large_default.jpg"
        }
      }
    ],
    "updated_at": "2025-06-01 12:00:00"
  }
}
```

**Erreurs** : `404 not_found` si le produit n'existe pas.

---

### GET `.../api/products/{id}/stock`

Scope requis : `products.read`

Alias de `GET .../api/products/{id}` (même controller, paramètre `action=stock` ignoré côté PHP, répond avec la fiche produit complète).

---

### PATCH `.../api/products/{id}`  — Mettre à jour le stock

Scope requis : `products.write`

Paramètre URL `action=stock` (ou auto-détecté si `payload.quantity` présent).

Corps JSON :

| Champ      | Type | Description                              |
|------------|------|------------------------------------------|
| `quantity` | int  | Nouvelle quantité absolue en stock       |

```json
{ "quantity": 15 }
```

**Réponse 200** — retourne la fiche produit mise à jour (même format que `GET /products/{id}`).

**Erreurs** :

| Code | `error`           | Raison                   |
|------|-------------------|--------------------------|
| 400  | `invalid_payload` | `quantity` absent        |
| 404  | `not_found`       | Produit introuvable      |

---

### PATCH `.../api/products/{id}`  — Mettre à jour les attributs

Scope requis : `products.write`

Paramètre URL `action=attributes` (auto-détecté si ni `quantity` ni `action=stock`).

Corps JSON (tous les champs sont optionnels, au moins un requis) :

| Champ           | Type      | Description                               |
|-----------------|-----------|-------------------------------------------|
| `active`        | bool/int  | Activer (`true`/`1`) ou désactiver le produit |
| `price_tax_excl`| float     | Prix HT (le module recalcule le prix TTC) |

```json
{ "active": true, "price_tax_excl": 15.90 }
```

**Réponse 200** — retourne la fiche produit mise à jour.

**Erreurs** :

| Code | `error`           | Raison                                         |
|------|-------------------|------------------------------------------------|
| 400  | `invalid_payload` | Aucun champ modifiable fourni ou valeur invalide |
| 404  | `not_found`       | Produit introuvable                            |

---

## Clients

### GET `.../api/customers`

Scope requis : `customers.read`

Liste paginée de clients avec pagination par offset et filtres avancés.

**Paramètres directs :**

| Paramètre | Type   | Description                                               |
|-----------|--------|-----------------------------------------------------------|
| `limit`   | int    | Résultats par page (défaut 20, max 100)                   |
| `offset`  | int    | Décalage (défaut 0)                                       |
| `search`  | string | Recherche sur prénom, nom, email                          |
| `email`   | string | Filtre email exact (doit être un email valide)            |
| `sort`    | string | `date_asc`, `date_desc`, `orders_desc`, `orders_asc`, `spent_desc`, `spent_asc` (défaut `date_desc`) |
| `ids`     | string | IDs séparés par virgule                                   |

**Paramètres dans `filter[...]` :**

| Paramètre                | Type   | Description                                      |
|--------------------------|--------|--------------------------------------------------|
| `filter[segment]`        | string | `new`, `repeat`, `vip`, `inactive`               |
| `filter[min_orders]`     | int    | Nombre minimum de commandes                      |
| `filter[max_orders]`     | int    | Nombre maximum de commandes                      |
| `filter[min_spent]`      | float  | Montant total minimum dépensé (TTC)              |
| `filter[max_spent]`      | float  | Montant total maximum dépensé (TTC)              |
| `filter[created_from]`   | date   | Date d'inscription >= (format libre, parsé par `strtotime`) |
| `filter[created_to]`     | date   | Date d'inscription <=                            |
| `filter[search]`         | string | Alias de `search`                                |
| `filter[sort]`           | string | Alias de `sort`                                  |
| `filter[ids]`            | array/string | Alias de `ids`                            |

**Réponse 200**

```json
{
  "customers": [
    {
      "id": 50,
      "firstname": "Anna",
      "lastname": "Dupont",
      "email": "anna@example.com",
      "orders_count": 3,
      "total_spent": 240.50,
      "last_order_at": "2025-05-15 10:00:00"
    }
  ],
  "pagination": {
    "limit": 20,
    "offset": 0,
    "count": 1,
    "has_next": false,
    "next_offset": null
  }
}
```

> `last_order_at` est `null` si le client n'a jamais commandé. `next_offset` est `null` quand il n'y a pas de page suivante.

---

### GET `.../api/customers/stats`

Scope requis : `customers.read`

Statistiques globales clients (compteurs exacts, indépendants de la pagination).

**Réponse 200**

```json
{
  "total": 4374,
  "new_this_month": 32
}
```

| Champ            | Type | Description                                                                                          |
|------------------|------|------------------------------------------------------------------------------------------------------|
| `total`          | int  | Nombre total de clients actifs (`deleted = 0`) sur la boutique courante.                            |
| `new_this_month` | int  | Clients inscrits depuis le 1er du mois courant à 00:00:00, calculé en heure boutique (`PS_TIMEZONE`). |

> La route `/customers/stats` est déclarée **avant** `/customers/{id}` dans les friendly URLs pour éviter toute ambiguïté de routage.

---

### GET `.../api/customers/{id}`

Scope requis : `customers.read`

Fiche client détaillée avec les 10 dernières commandes (format liste, voir `GET /orders`).

**Réponse 200**

```json
{
  "customer": {
    "id": 50,
    "firstname": "Anna",
    "lastname": "Dupont",
    "email": "anna@example.com",
    "orders_count": 3,
    "total_spent": 240.50,
    "last_order_at": "2025-05-15 10:00:00",
    "orders": [
      {
        "id": 123,
        "reference": "ABCDEF123",
        "status": "Expédiée",
        "total_paid": 72.90,
        "currency": "EUR",
        "date_upd": "2025-06-01 14:30:00",
        "customer": {
          "id": 50,
          "firstname": "Anna",
          "lastname": "Dupont"
        }
      }
    ]
  }
}
```

**Erreurs** : `404 not_found` si le client n'existe pas.

---

## Dashboard

### GET `.../api/dashboard/metrics`

Scope requis : `dashboard.read`

Métriques agrégées sur une période.

| Paramètre | Type   | Valeurs                              |
|-----------|--------|--------------------------------------|
| `period`  | string | `day`, `week`, `month` (défaut), `year` |

**Réponse 200**

```json
{
  "period": {
    "label": "month",
    "from": "2025-06-01T00:00:00+00:00",
    "to": "2025-06-19T23:59:59+00:00"
  },
  "turnover": 12500.00,
  "orders_count": 145,
  "customers_count": 98,
  "products_count": 312,
  "revenue": 12500.00,
  "revenue_tax_incl": 12500.00,
  "revenue_tax_excl": 10416.67,
  "tax_collected": 2083.33,
  "average_basket": 86.21,
  "average_order_value": 86.21,
  "returns": 3,
  "currency": "EUR",
  "chart": [
    {
      "label": "2025-06-01",
      "turnover": 450.00,
      "orders": 5,
      "customers": 4
    },
    {
      "label": "2025-06-02",
      "turnover": 0.0,
      "orders": 0,
      "customers": 0
    }
  ]
}
```

> `turnover` et `revenue` sont identiques et correspondent au CA TTC. `chart` contient un point par jour sur toute la période, y compris les jours sans ventes (revenue/orders/customers à zéro).

---

## Rapports

### GET `.../api/reports/bestsellers`

Scope requis : `reports.read`

Produits les plus vendus (en quantité).

| Paramètre   | Type     | Description                              |
|-------------|----------|------------------------------------------|
| `limit`     | int      | Nombre de résultats (défaut 10, max 50)  |
| `date_from` | datetime | Filtre date commande >= (Y-m-d H:i:s)    |
| `date_to`   | datetime | Filtre date commande <=                  |

**Réponse 200**

```json
{
  "products": [
    {
      "product_id": 88,
      "product_attribute_id": 0,
      "name": "T-shirt noir",
      "reference": "TSHIRT-BLACK",
      "quantity": 142,
      "total_tax_incl": 2706.36,
      "total_tax_excl": 2254.80
    }
  ]
}
```

---

### GET `.../api/reports/bestcustomers`

Scope requis : `reports.read`

Clients ayant le plus dépensé (par CA TTC décroissant).

| Paramètre   | Type     | Description                              |
|-------------|----------|------------------------------------------|
| `limit`     | int      | Nombre de résultats (défaut 10, max 50)  |
| `date_from` | datetime | Filtre date commande >= (Y-m-d H:i:s)    |
| `date_to`   | datetime | Filtre date commande <=                  |

**Réponse 200**

```json
{
  "customers": [
    {
      "id": 50,
      "firstname": "Anna",
      "lastname": "Dupont",
      "email": "anna@example.com",
      "orders_count": 12,
      "total_spent": 1840.00,
      "last_order_at": "2025-06-10 11:00:00"
    }
  ]
}
```

---

### GET `.../api/customers/top`

Alias vers les meilleurs clients — route friendly identique à `GET .../api/reports/bestcustomers` (même controller `reports`, même paramètre `resource=bestcustomers`). Accepte les mêmes paramètres et retourne la même structure.

---

## Paniers

### GET `.../api/baskets`

Scope requis : `baskets.read`

Liste paginée de paniers.

| Paramètre              | Type   | Description                                     |
|------------------------|--------|-------------------------------------------------|
| `limit`                | int    | Résultats (défaut 20, max 100)                  |
| `offset`               | int    | Décalage (défaut 0)                             |
| `customer_id`          | int    | Filtre par client                               |
| `date_from`            | datetime | Filtre `date_add >=`                          |
| `date_to`              | datetime | Filtre `date_add <=`                          |
| `has_order`            | bool   | `1` = convertis en commande, `0` = abandonnés  |
| `abandoned_since_days` | int    | Paniers non commandés depuis N jours            |

**Réponse 200**

```json
{
  "data": [
    {
      "id": 456,
      "customer": {
        "id": 50,
        "firstname": "Anna",
        "lastname": "Dupont",
        "email": "anna@example.com"
      },
      "currency": {
        "id": 1,
        "iso": "EUR"
      },
      "totals": {
        "tax_excl": 31.80,
        "tax_incl": 38.16
      },
      "items_count": 2,
      "has_order": false,
      "dates": {
        "created_at": "2025-06-18 08:30:00",
        "updated_at": "2025-06-18 09:15:00"
      }
    }
  ]
}
```

---

### GET `.../api/baskets/{id}`

Scope requis : `baskets.read`

Détail d'un panier avec la liste des produits.

**Réponse 200**

```json
{
  "data": {
    "id": 456,
    "customer": {
      "id": 50,
      "firstname": "Anna",
      "lastname": "Dupont",
      "email": "anna@example.com"
    },
    "currency": {
      "id": 1,
      "iso": "EUR"
    },
    "totals": {
      "tax_excl": 31.80,
      "tax_incl": 38.16
    },
    "items_count": 2,
    "has_order": false,
    "dates": {
      "created_at": "2025-06-18 08:30:00",
      "updated_at": "2025-06-18 09:15:00"
    },
    "products": [
      {
        "product_id": 88,
        "product_attribute_id": 5,
        "name": "T-shirt noir",
        "reference": "TSHIRT-BLACK",
        "quantity": 2,
        "total_tax_incl": 38.16,
        "total_tax_excl": 31.80,
        "image": "https://example.com/88-101-home_default.jpg"
      }
    ]
  }
}
```

**Erreurs** : `404 not_found` si le panier n'existe pas.

---

## Notifications / Appareils FCM

### POST `.../api/notifications/devices`

Scope requis : `notifications.send`

Enregistre un appareil mobile pour les notifications push (FCM).

Corps JSON :

| Champ       | Type         | Description                                          |
|-------------|--------------|------------------------------------------------------|
| `token`     | string       | Token FCM de l'appareil (requis, **≥ 50 caractères** ; sinon `400 invalid_payload`) |
| `topics`    | array/string | Catégories d'événements souhaitées (optionnel — voir ci-dessous) |
| `device_id` | string       | Identifiant unique de l'appareil (optionnel)         |
| `platform`  | string       | Plateforme : `android`, `ios`, etc. (optionnel)      |

```json
{
  "token": "fXBKj4...",
  "topics": ["order.created", "order.shipping.updated"],
  "device_id": "device-uuid-1234",
  "platform": "android"
}
```

**Réponse 200**

```json
{
  "status": "registered",
  "token": "fXBKj4...",
  "topics": ["order.created", "order.shipping.updated"]
}
```

**Erreurs** :

| Code | `error`           | Raison               |
|------|-------------------|----------------------|
| 400  | `invalid_payload` | `token` absent/vide  |

#### Catégories de notifications (topics) — depuis v1.4.9

Le champ `topics` détermine quels types d'événements l'appareil souhaite recevoir.
Il s'agit d'un **abonnement par catégorie** côté serveur : le module ne distribue une notification
qu'aux appareils dont la liste `topics` intersecte la catégorie de l'événement déclenché.

**Catégories stables (noms immuables — contrat partagé avec l'app Android) :**

| Valeur                    | Événement correspondant                        |
|---------------------------|------------------------------------------------|
| `order.created`           | Nouvelle commande validée                      |
| `order.status.changed`    | Changement de statut d'une commande            |
| `order.shipping.updated`  | Mise à jour du numéro de suivi / expédition    |

**Règle de ciblage :**

- **`topics` vide (`[]`) ou absent** — l'appareil est considéré « non configuré » et reçoit
  **toutes** les catégories d'événements (comportement identique à l'avant v1.4.9,
  garantissant la rétrocompatibilité des appareils enregistrés avant la mise à jour de l'app).
- **`topics` non vide** — l'appareil ne reçoit **que** les catégories qu'il a déclarées.

Les tokens de secours configurés en back-office (`getFcmDeviceTokens`) restent un filet de
sécurité et reçoivent tous les événements sans condition (comportement inchangé).

---

### DELETE `.../api/notifications/devices/{token}`

Scope requis : `notifications.send`

Désenregistre un appareil. Le token peut aussi être passé dans le corps JSON (`{"token": "..."}`) si la route `/{token}` n'est pas utilisable.

**Réponse 204** (corps vide).

**Erreurs** :

| Code | `error`           | Raison                       |
|------|-------------------|------------------------------|
| 400  | `invalid_payload` | Token absent dans URL et corps |

---

## Codes d'erreur

Toutes les erreurs retournent un corps JSON :

```json
{
  "error": "not_found",
  "message": "Order not found."
}
```

| Code HTTP | `error`             | Raison                                          |
|-----------|---------------------|-------------------------------------------------|
| 400       | `invalid_request`   | Corps JSON invalide ou champ requis manquant    |
| 400       | `invalid_payload`   | Données invalides pour l'action demandée        |
| 401       | `unauthenticated`   | Token JWT absent, expiré ou invalide            |
| 401       | `unauthorized`      | Clé API incorrecte (endpoint login)             |
| 403       | `forbidden`         | Scope insuffisant ou IP non autorisée           |
| 404       | `not_found`         | Ressource introuvable                           |
| 405       | `method_not_allowed`| Méthode HTTP non supportée par cet endpoint     |
| 429       | `too_many_requests` | Limite de débit atteinte (rate limiter activé)  |
| 500       | `server_error`      | Erreur interne (détail en mode dev uniquement)  |

---

## Exemples cURL

### Authentification

```bash
curl -X POST "https://example.com/module/rebuildconnector/api/connector/login" \
  -H "Content-Type: application/json" \
  -d '{"api_key": "mon_api_key"}'
```

### Liste des commandes

```bash
curl -X GET "https://example.com/module/rebuildconnector/api/orders?limit=10&status=4" \
  -H "Authorization: Bearer eyJhbGci..."
```

### Changer le statut d'une commande

```bash
curl -X PATCH "https://example.com/module/rebuildconnector/api/orders/123?action=status" \
  -H "Authorization: Bearer eyJhbGci..." \
  -H "Content-Type: application/json" \
  -d '{"status": "Expédiée"}'
```

### Mettre à jour le stock

```bash
curl -X PATCH "https://example.com/module/rebuildconnector/api/products/88?action=stock" \
  -H "Authorization: Bearer eyJhbGci..." \
  -H "Content-Type: application/json" \
  -d '{"quantity": 50}'
```

### Dashboard du mois

```bash
curl -X GET "https://example.com/module/rebuildconnector/api/dashboard/metrics?period=month" \
  -H "Authorization: Bearer eyJhbGci..."
```

---

## Table des routes

| Méthode | URL friendly                                    | Controller   | Scope requis        |
|---------|-------------------------------------------------|--------------|---------------------|
| POST    | `.../api/connector/login`                       | api          | —                   |
| GET     | `.../api/orders/statuses`                       | orders       | `orders.read`       |
| GET     | `.../api/orders`                                | orders       | `orders.read`       |
| GET     | `.../api/orders/{id}`                           | orders       | `orders.read`       |
| PATCH   | `.../api/orders/{id}`                           | orders       | `orders.write`      |
| PATCH   | `.../api/orders/{id}/{action}`                  | orders       | `orders.write`      |
| GET     | `.../api/products`                              | products     | `products.read`     |
| GET     | `.../api/products/{id}`                         | products     | `products.read`     |
| GET     | `.../api/products/{id}/stock`                   | products     | `products.read`     |
| PATCH   | `.../api/products/{id}`                         | products     | `products.write`    |
| GET     | `.../api/customers`                             | customers    | `customers.read`    |
| GET     | `.../api/customers/stats`                       | customers    | `customers.read`    |
| GET     | `.../api/customers/{id}`                        | customers    | `customers.read`    |
| GET     | `.../api/customers/top`                         | reports      | `reports.read`      |
| GET     | `.../api/dashboard/metrics`                     | dashboard    | `dashboard.read`    |
| GET     | `.../api/reports/bestsellers`                   | reports      | `reports.read`      |
| GET     | `.../api/reports/bestcustomers`                 | reports      | `reports.read`      |
| GET     | `.../api/baskets`                               | baskets      | `baskets.read`      |
| GET     | `.../api/baskets/{id}`                          | baskets      | `baskets.read`      |
| POST    | `.../api/notifications/devices`                 | notifications| `notifications.send`|
| DELETE  | `.../api/notifications/devices/{token}`         | notifications| `notifications.send`|
