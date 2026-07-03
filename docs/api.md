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

| Paramètre     | Type       | Description                                                                                                                                  |
|---------------|------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `limit`       | int        | Nombre max de résultats (défaut 20, min 1, max 100)                                                                                          |
| `offset`      | int        | Décalage de pagination (défaut 0)                                                                                                            |
| `customer_id` | int        | Filtre par ID client                                                                                                                         |
| `status`      | int/string | ID d'état numérique ou libellé partiel (LIKE) — ignoré si `statuses` est fourni                                                             |
| `statuses`    | string/array | Liste CSV d'IDs d'état : `statuses=2,3,4,5` ou tableau `statuses[]=2&statuses[]=3`. Prime sur `status`. Valeurs non-entières ignorées silencieusement. |
| `sort`        | string     | Ordre de tri. Valeurs acceptées : `date_desc` (défaut), `date_asc`, `total_desc`, `total_asc`, `status`, `reference`. Valeur inconnue → défaut `date_desc`. |
| `date_from`   | datetime   | Filtre `date_add >=` (format Y-m-d ou Y-m-d H:i:s)                                                                                          |
| `date_to`     | datetime   | Filtre `date_add <=` (format Y-m-d ou Y-m-d H:i:s)                                                                                          |
| `search`      | string     | Recherche sur référence, prénom, nom, email                                                                                                  |

**Valeurs de `sort` :**

| Valeur       | Ordre SQL                          |
|--------------|------------------------------------|
| `date_desc`  | `o.date_add DESC` (défaut)         |
| `date_asc`   | `o.date_add ASC`                   |
| `total_desc` | `o.total_paid_tax_incl DESC`       |
| `total_asc`  | `o.total_paid_tax_incl ASC`        |
| `status`     | `o.current_state ASC`              |
| `reference`  | `o.reference ASC`                  |

**Réponse 200**

```json
{
  "orders": [
    {
      "id": 123,
      "reference": "ABCDEF123",
      "status": "Expédiée",
      "status_color": "#3498D8",
      "total_paid": 72.90,
      "currency": "EUR",
      "date_add": "2025-05-20 09:15:00",
      "date_upd": "2025-06-01 14:30:00",
      "has_invoice": false,
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
> `status_color` est la couleur hexadécimale de l'état telle que configurée dans le BO PrestaShop (ex. `#3498D8`). Chaîne vide si non définie.

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
    "customer_id": 50,
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

`customer_id` (nouveau **v1.9.1**) : alias de premier niveau de `customer.id` — l'ID du client (`id_customer` PrestaShop). Permet à l'app d'ouvrir la fiche client directement depuis le détail commande sans désimbriquer `customer.id`.

`shipping` (**v1.9.2**) : peut être `null` pour une commande **virtuelle** (sans transporteur — produit dématérialisé). Critère : `carrier_id <= 0`. En pratique, une commande physique a toujours un transporteur assigné ; l'absence de transporteur signale donc une commande virtuelle plutôt qu'un bloc `shipping` vide (`carrier_id: 0, carrier_name: ""`).

```json
"shipping": null
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

### POST `.../api/orders/{id}/shipping-label`  — Générer une étiquette Colissimo

Scope requis : `orders.write`

Génère une étiquette Colissimo via le webservice La Poste (`ws.colissimo.fr`), la stocke sur disque dans
`modules/colissimo/documents/labels/` et enregistre le numéro de suivi sur la commande.

**Conditions préalables** :
- Le module Colissimo doit être installé et actif.
- Les credentials Colissimo doivent être configurés dans le module (`COLISSIMO_ACCOUNT_LOGIN` / `COLISSIMO_ACCOUNT_PASSWORD` ou clé de connexion).
- L'adresse expéditeur doit être configurée dans le module (`COLISSIMO_SENDER_ADDRESS`).

**Corps JSON** : aucun corps requis. L'action est portée par le path `/orders/{id}/shipping-label`.

**Idempotence** : si une étiquette Colissimo avec fichier PDF existe déjà pour cette commande,
le webservice n'est PAS rappelé — la réponse retourne l'étiquette existante avec `generated: false`
et HTTP 200.

**Réponse 201** (nouvelle étiquette générée)

```json
{
  "generated": true,
  "tracking_number": "8L12345678901",
  "has_label": true,
  "carrier_type": "colissimo"
}
```

**Réponse 200** (étiquette existante, idempotence)

```json
{
  "generated": false,
  "tracking_number": "8L12345678901",
  "has_label": true,
  "carrier_type": "colissimo"
}
```

| Champ             | Type    | Description                                                          |
|-------------------|---------|----------------------------------------------------------------------|
| `generated`       | bool    | `true` si générée maintenant, `false` si étiquette existante renvoyée |
| `tracking_number` | string  | Numéro de suivi Colissimo (`parcelNumber` retourné par le WS)        |
| `has_label`       | bool    | Toujours `true` (le PDF est disponible via GET shipping-label)       |
| `carrier_type`    | string  | Toujours `"colissimo"` pour cet endpoint                             |

**Erreurs** :

| Code | `error`                      | Raison                                                        |
|------|------------------------------|---------------------------------------------------------------|
| 404  | `not_found`                  | Commande introuvable                                          |
| 422  | `carrier_not_supported`      | Transporteur non Colissimo (Mondial Relay = phase 2)          |
| 501  | `generation_not_configured`  | Module Colissimo absent/inactif ou credentials non configurés |
| 502  | `carrier_webservice_error`   | Erreur retournée par le webservice Colissimo (+ message)      |
| 401  | `unauthenticated`            | Token manquant ou invalide                                    |
| 403  | `forbidden`                  | Scope `orders.write` insuffisant                              |

> Après une génération réussie (201), le PDF est immédiatement accessible via
> `GET /orders/{id}/shipping-label`.

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
| `search`  | string | Recherche partielle (LIKE) sur nom ou référence          |
| `barcode` | string | Correspondance **exacte** sur `ean13` OU `reference`, **produit OU combinaison** (scan code-barres). Distinct de `search`. |
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
      "ean13": "3760123456789",
      "price": 19.08,
      "active": true,
      "stock": {
        "quantity": 3,
        "low_stock_threshold": 5,
        "is_low": true,
        "warehouse_id": null,
        "updated_at": "2025-06-01 12:00:00"
      },
      "matched_combination": null,
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

**Champ `matched_combination` ajouté (v1.10.5)**

Certaines boutiques (ex. pensebonheur, pelotes de laine) portent le stock vendable et l'EAN13 sur une
**combinaison/déclinaison** (`product_attribute`, ex. « Coloris - Bleu ») plutôt que sur le produit. Quand
le filtre `barcode` matche une combinaison plutôt que le produit lui-même, l'item retourné porte un objet
`matched_combination` :

```json
{
  "matched_combination": {
    "id": 7,
    "name": "Coloris - Bleu nuit",
    "ean13": "3760123456999",
    "reference": "RICO-035-BLEU",
    "quantity": 12
  }
}
```

| Champ                            | Type        | Description                                                                 |
|-----------------------------------|-------------|-------------------------------------------------------------------------------|
| `matched_combination`             | object/null | `null` si le match porte sur le produit lui-même (ou produit sans déclinaison). |
| `matched_combination.id`          | int         | `id_product_attribute` de la combinaison matchée.                            |
| `matched_combination.name`        | string      | Libellé façon core PrestaShop, ex. `"Coloris - Bleu"` (`attribute_group_lang` + `attribute_lang`, langue courante). |
| `matched_combination.ean13`       | string      | EAN13 propre à la combinaison (`product_attribute.ean13`).                   |
| `matched_combination.reference`   | string      | Référence propre à la combinaison (`product_attribute.reference`).           |
| `matched_combination.quantity`    | int         | Stock actuel de **cette combinaison** (`StockAvailable::getQuantity($id_product, $id_product_attribute)`), distinct de `stock.quantity` qui reste le stock niveau produit (`id_product_attribute = 0`). |

> `price` est le prix TTC (`Product::getPriceStatic($id, true)`). `price_tax_excl` (prix HT brut) est exposé en lecture depuis **v1.10.3** (liste + détail).
> Toute valeur du filtre `stock` autre que les trois valeurs listées retourne `400 invalid_payload`.
> **v1.4.3** — Corrige un bug où l'absence du paramètre `active` appliquait un filtre `p.active = 0` non désiré, causant le retour de produits inactifs uniquement et une liste tronquée.
> **v1.10.0** — Ajoute le champ `ean13` (liste + détail) et le filtre `barcode` pour la mise en stock par scan de code-barres. `barcode` fait une correspondance exacte sur `ean13` OU `reference` ; `search` reste un LIKE partiel sur `name`/`reference`. Si `ean13` n'est pas renseigné en base, la valeur retournée est `""`.
> **v1.10.5** — Le filtre `barcode` matche désormais aussi `product_attribute.ean13`/`.reference` (combinaisons), via un `LEFT JOIN product_attribute` restreint aux lignes qui matchent déjà le code (donc pas de duplication de lignes produit) et `product_attribute_shop` pour restreindre à la boutique courante. Ajoute le champ `matched_combination` (liste + détail) quand le match porte sur une déclinaison.

---

### GET `.../api/products/{id}`

Scope requis : `products.read`

Fiche produit détaillée. La réponse est identique à un élément de la liste mais inclut toutes les images
et, depuis **v1.10.3**, `description` / `description_short` (exposées uniquement sur le détail, pas dans la
liste, car potentiellement volumineuses ; nécessaires au préremplissage de l'écran d'édition de fiche).

**Réponse 200**

```json
{
  "product": {
    "id": 88,
    "name": "T-shirt noir",
    "reference": "TSHIRT-BLACK",
    "ean13": "3760123456789",
    "price": 19.08,
    "price_tax_excl": 15.90,
    "active": true,
    "description": "<p>T-shirt en coton bio, coupe droite.</p>",
    "description_short": "<p>T-shirt en coton bio.</p>",
    "stock": {
      "quantity": 24,
      "low_stock_threshold": 5,
      "is_low": false,
      "warehouse_id": null,
      "updated_at": "2025-06-01 12:00:00"
    },
    "matched_combination": null,
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

> `matched_combination` est toujours `null` sur ce endpoint : le détail est résolu par `id_product` (pas
> par `barcode`), donc jamais issu d'un match sur une combinaison. Ce champ n'a de sens que sur
> `GET /products?barcode=...`.

---

### GET `.../api/products/{id}/stock`

Scope requis : `products.read`

Alias de `GET .../api/products/{id}` (même controller, paramètre `action=stock` ignoré côté PHP, répond avec la fiche produit complète).

---

### PATCH `.../api/products/{id}`  — Mettre à jour le stock

Scope requis : `products.write`

Paramètre URL `action=stock` (ou auto-détecté si `payload.quantity` présent).

Corps JSON :

| Champ            | Type | Description                              |
|-------------------|------|------------------------------------------|
| `quantity`        | int  | Nouvelle quantité absolue en stock       |
| `combination_id`  | int  | **Optionnel (v1.10.5)**. `id_product_attribute` de la déclinaison ciblée. Doit appartenir au produit `{id}` de l'URL. Absent ou `0` = niveau produit (comportement historique, `id_product_attribute = 0`). |
| `warehouse_id`    | int  | Réservé (non traité actuellement — accepté et ignoré sans erreur). |
| `reason`          | string | Réservé (non traité actuellement — accepté et ignoré sans erreur). |

```json
{ "quantity": 15 }
```

```json
{ "quantity": 12, "combination_id": 7 }
```

**Réponse 200** — retourne la fiche produit mise à jour (même format que `GET /products/{id}`).

**Erreurs** :

| Code | `error`           | Raison                                                                 |
|------|-------------------|-------------------------------------------------------------------------|
| 400  | `invalid_payload` | `quantity` absent                                                       |
| 400  | `invalid_payload` | `combination_id` n'est pas numérique                                    |
| 400  | `invalid_payload` | `combination_id` fourni mais n'appartient pas au produit `{id}` (déclinaison d'un autre produit, ou id inexistant) |
| 404  | `not_found`       | Produit introuvable                                                     |

---

### PATCH `.../api/products/{id}`  — Mettre à jour les attributs

Scope requis : `products.write`

Paramètre URL `action=attributes` (auto-détecté si ni `quantity` ni `action=stock`).

Corps JSON (tous les champs sont optionnels, au moins un requis) :

| Champ                | Type      | Description                               |
|-----------------------|-----------|-------------------------------------------|
| `active`              | bool/int  | Activer (`true`/`1`) ou désactiver le produit |
| `price_tax_excl`      | float     | Prix HT (le module recalcule le prix TTC) |
| `ean13`               | string    | Code-barres EAN13 du produit (**v1.10.1**). 1 à 13 chiffres, ou chaîne vide `""` pour effacer un EAN13 existant. |
| `name`                | string    | Nom du produit (**v1.10.2**). Non vide. Champ multilang PrestaShop : la même valeur est appliquée à **toutes les langues installées** de la boutique. |
| `description`         | string    | Description longue, HTML autorisé (**v1.10.2**). Champ multilang (toutes langues). Chaîne vide autorisée pour l'effacer. |
| `description_short`   | string    | Description courte, HTML autorisé (**v1.10.2**). Champ multilang (toutes langues). Chaîne vide autorisée pour l'effacer. |
| `reference`           | string    | Référence produit (**v1.10.2**). ≤ 64 caractères. Chaîne vide autorisée pour l'effacer. |

```json
{ "active": true, "price_tax_excl": 15.90 }
```

```json
{ "ean13": "3760123456789" }
```

```json
{
  "name": "T-shirt noir",
  "description": "<p>Coton bio, coupe droite.</p>",
  "description_short": "<p>T-shirt en coton bio.</p>",
  "reference": "TSHIRT-BLACK"
}
```

**Réponse 200** — retourne la fiche produit mise à jour (même format que `GET /products/{id}`, qui expose depuis **v1.10.3** `price_tax_excl`, `description` et `description_short` en lecture sur le détail).

**Erreurs** :

| Code | `error`           | Raison                                         |
|------|-------------------|------------------------------------------------|
| 400  | `invalid_payload` | Aucun champ modifiable fourni ou valeur invalide |
| 400  | `invalid_payload` | `ean13` n'est pas une chaîne, ou ne respecte pas le format `[0-9]{1,13}` (hors chaîne vide) |
| 400  | `invalid_payload` | `name` n'est pas une chaîne, est vide, ou contient des caractères interdits (`Validate::isCatalogName`) |
| 400  | `invalid_payload` | `description`/`description_short` n'est pas une chaîne ou contient du HTML jugé dangereux (`Validate::isCleanHtml`) |
| 400  | `invalid_payload` | `reference` n'est pas une chaîne, dépasse 64 caractères, ou contient des caractères interdits (`Validate::isReference`) |
| 404  | `not_found`       | Produit introuvable                            |

> **v1.10.1** — Ajoute `ean13` aux champs modifiables via `PATCH /products/{id}` (action `attributes`). Permet à l'app d'associer un code-barres scanné à un produit existant (auto-association lors d'une réception sans EAN13 connu). Usage typique : `GET /products?barcode=<code>` ne retourne rien → l'utilisateur choisit le produit dans la liste → `PATCH /products/{id} { "ean13": "<code>" }`.

> **v1.10.2** — Ajoute `name`, `description`, `description_short` et `reference` aux champs modifiables via `PATCH /products/{id}` (action `attributes`), pour l'édition des champs simples de la fiche produit côté app. `name`, `description` et `description_short` sont des champs multilang PrestaShop : le module applique la même valeur reçue à toutes les langues installées de la boutique (`Language::getLanguages(false)`), l'app n'a pas à gérer le multilang côté client.
> **v1.10.3** — Expose en **lecture** `price_tax_excl` (liste + détail) et `description` / `description_short` (détail uniquement, pour ne pas gonfler la liste paginée). Permet à l'écran d'édition de fiche de préremplir fidèlement le prix HT et les descriptions (avant v1.10.3, le prix HT n'était pas lisible et les descriptions n'étaient pas retournées).

---

### POST `.../api/products/{id}/images`  — Ajouter une image

Scope requis : `products.write`

Corps `multipart/form-data`, un seul champ fichier nommé **`image`**.

| Champ   | Type   | Description                                  |
|---------|--------|-----------------------------------------------|
| `image` | fichier | JPEG, PNG ou WEBP. Taille max **8 Mo**.       |

Le type réel du fichier est vérifié à partir de son **contenu** (`getimagesize()` + sniffing MIME via `finfo`), jamais à partir du nom de fichier ni du `Content-Type` déclaré par le client. Le controller dédié (`ProductImagesController`) gère ce endpoint séparément des autres routes `products/*` car le multipart ne se prête pas au dispatch JSON habituel (`decodeRequestBody`).

Traitement côté module (suit le flux standard du core PrestaShop, `Image::add()` + `ImageManager::resize()`) :
- Position = dernière position du produit + 1.
- **Couverture (`cover`) automatique si c'est la première image du produit** (aucune couverture existante) ; sinon l'image est ajoutée sans toucher à la couverture actuelle.
- Génère le fichier principal puis une déclinaison par taille active (`ImageType::getImagesTypes('products')`).
- Le fichier temporaire d'upload est supprimé après traitement (succès ou échec).

```bash
curl -X POST '.../api/products/42/images' \
  -H "Authorization: Bearer <token>" \
  -F "image=@photo.jpg;type=image/jpeg"
```

**Réponse 201** — retourne la fiche produit mise à jour (même format que `GET /products/{id}`, donc `images[]` à jour).

```json
{
  "product": {
    "id": 42,
    "...": "...",
    "images": [
      { "id": 501, "url": "https://boutique.example/501-large_default/produit.jpg" },
      { "id": 512, "url": "https://boutique.example/512-large_default/produit.jpg" }
    ]
  }
}
```

**Erreurs** :

| Code | `error`           | Raison                                              |
|------|-------------------|------------------------------------------------------|
| 400  | `invalid_payload` | Champ `image` absent, upload échoué (`is_uploaded_file`), taille > 8 Mo, ou type réel non JPEG/PNG/WEBP |
| 404  | `not_found`       | Produit introuvable                                  |
| 500  | `server_error`    | Échec technique (écriture disque, redimensionnement) |

---

### DELETE `.../api/products/{id}/images/{imageId}`  — Supprimer une image

Scope requis : `products.write`

Supprime l'image (`Image::delete()` core, qui nettoie le fichier source **et** toutes ses déclinaisons de taille). Vérifie d'abord que l'image appartient bien au produit `{id}` (sinon `404 not_found`, pas de fuite d'existence d'image d'un autre produit).

Si l'image supprimée était la couverture, la première image restante est automatiquement promue couverture (même logique que l'admin PrestaShop `ajaxProcessDeleteProductImage`).

```bash
curl -X DELETE '.../api/products/42/images/512' \
  -H "Authorization: Bearer <token>"
```

**Réponse 200** — retourne la fiche produit mise à jour (même format que `GET /products/{id}`).

**Erreurs** :

| Code | `error`     | Raison                                                        |
|------|-------------|----------------------------------------------------------------|
| 404  | `not_found` | Produit introuvable, image introuvable, ou image n'appartenant pas à ce produit |

> **v1.10.4** — Ajoute l'upload (`POST /products/{id}/images`) et la suppression (`DELETE /products/{id}/images/{imageId}`) d'images produit, pour l'écran d'édition de fiche côté app (étape « images de la fiche produit »). Nouveau controller dédié `ProductImagesController` (scope `products.write`, réutilise `ProductsService::getProductById` pour la fiche à jour en réponse).

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
      "last_order_at": "2025-05-15 10:00:00",
      "date_add": "2024-11-03 14:22:00"
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

| Champ          | Type          | Description                                                         |
|----------------|---------------|---------------------------------------------------------------------|
| `last_order_at`| string\|null  | Date de la dernière commande (`null` si aucune commande).           |
| `date_add`     | string\|null  | Date d'inscription du client (`ps_customer.date_add`, format `YYYY-MM-DD HH:MM:SS`). Permet de filtrer les nouveaux clients du mois côté app. |

> `last_order_at` et `date_add` sont `null` s'ils sont absents en base. `next_offset` est `null` quand il n'y a pas de page suivante.

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
    "date_add": "2024-11-03 14:22:00",
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

Métriques agrégées sur une période. Deux modes exclusifs :

#### Mode preset (comportement historique, inchangé)

| Paramètre | Type   | Valeurs                                              |
|-----------|--------|------------------------------------------------------|
| `period`  | string | `today`/`day`, `week`, `month` (défaut), `quarter`, `year` |

#### Mode plage libre (depuis v1.7.0)

Fournir **les deux** paramètres suivants à la place de `period` :

| Paramètre | Type   | Description                                                             |
|-----------|--------|-------------------------------------------------------------------------|
| `from`    | string | Début de la plage, format `YYYY-MM-DD` (ex : `2025-01-01`). Inclus, heure 00:00:00. |
| `to`      | string | Fin de la plage, format `YYYY-MM-DD` (ex : `2025-03-31`). Inclus, heure 23:59:59. |

Règles de validation :
- Format strict `YYYY-MM-DD` ; dates invalides (ex : `2025-13-01`) → `400 invalid_payload`.
- `from` doit être ≤ `to` → sinon `400 invalid_payload`.
- Plage maximale : 730 jours (2 ans) → sinon `400 invalid_payload`.
- Fournir un seul des deux paramètres → `400 invalid_payload`.

En mode plage libre, `period.label` vaut `"custom"`. `previous_turnover` compare à la plage précédente de même durée (identique aux presets).

**Granularité du `chart[]` :**
- Plage d'exactement 1 jour → points **horaires** (24 points, labels `YYYY-MM-DD HH:00:00`).
- Toute autre plage (preset ou plage libre) → points **journaliers** (1 point par jour, labels `YYYY-MM-DD`).

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
      "customers": 4,
      "new_customers": 2
    },
    {
      "label": "2025-06-02",
      "turnover": 0.0,
      "orders": 0,
      "customers": 0,
      "new_customers": 0
    }
  ]
}
```

**Champs du `chart[]`**

| Champ           | Type  | Description                                                                                  |
|-----------------|-------|----------------------------------------------------------------------------------------------|
| `label`         | string | Clé temporelle du bucket : date `YYYY-MM-DD` (journalier) ou `YYYY-MM-DD HH:00:00` (horaire). |
| `turnover`      | float | CA TTC sur ce bucket.                                                                        |
| `orders`        | int   | Nombre de commandes créées sur ce bucket.                                                    |
| `customers`     | int   | Nombre de clients **ayant commandé** (COUNT DISTINCT id_customer) sur ce bucket.             |
| `new_customers` | int   | **Nouveau (v1.7.0).** Nombre de clients dont la date d'inscription (`date_add` dans `ps_customer`) tombe dans ce bucket. Distinct de `customers` (qui mesure l'activité achat). Vaut 0 si aucune inscription. |

> `turnover` et `revenue` sont identiques et correspondent au CA TTC. `chart` contient un point par bucket sur toute la période, y compris les buckets sans activité (toutes les valeurs à zéro).

**Exemples cURL**

Preset mensuel (inchangé) :
```bash
curl "https://example.com/module/rebuildconnector/api/dashboard/metrics?period=month" \
  -H "Authorization: Bearer <token>"
```

Plage libre du 1er janvier au 31 mars 2025 :
```bash
curl "https://example.com/module/rebuildconnector/api/dashboard/metrics?from=2025-01-01&to=2025-03-31" \
  -H "Authorization: Bearer <token>"
```

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

> **Architecture hub-only (depuis v1.7.1)** — le module ne détient plus de compte de service FCM.
> Toutes les notifications push transitent par le hub centralisé `push.rebuild-it.fr` (clé de
> licence configurable en back-office). La résilience est gérée côté hub. Aucun fallback FCM
> direct dans le module.

### POST `.../api/notifications/devices`

Scope requis : `notifications.send`

Enregistre un appareil mobile pour les notifications push.

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

#### Canal Android (`channel_id`) — depuis v1.4.10

Chaque notification FCM inclut désormais `message.android.notification.channel_id`, ce qui
permet à l'app Android de router la notification vers le bon canal de notification (son distinct
par type d'événement).

**Mapping catégorie → `channel_id` (contrat immuable — partagé avec l'app Android) :**

| `event` (`$data['event']`) | `channel_id`      | Usage                                         |
|----------------------------|-------------------|-----------------------------------------------|
| `order.created`            | `sales_v2`        | Nouvelle vente — son « caisse enregistreuse » |
| `order.status.changed`     | `order_status`    | Changement de statut commande                 |
| `order.shipping.updated`   | `order_shipping`  | Mise à jour numéro de suivi / expédition      |

Si l'événement est absent ou inconnu, aucun `channel_id` n'est transmis : l'app utilise son
canal par défaut. La clé `message.notification` (top-level) reste inchangée pour la
compatibilité multi-plateforme.

Exemple de payload FCM HTTP v1 pour `order.created` :

```json
{
  "message": {
    "token": "fXBKj4...",
    "notification": {
      "title": "Nouvelle commande",
      "body": "Commande #42 — 29,90 €"
    },
    "data": {
      "event": "order.created",
      "order_id": "42"
    },
    "android": {
      "notification": {
        "channel_id": "sales_v2"
      }
    }
  }
}
```

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

### Dashboard du mois (preset)

```bash
curl -X GET "https://example.com/module/rebuildconnector/api/dashboard/metrics?period=month" \
  -H "Authorization: Bearer eyJhbGci..."
```

### Dashboard sur une plage libre

```bash
curl -X GET "https://example.com/module/rebuildconnector/api/dashboard/metrics?from=2025-01-01&to=2025-03-31" \
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
| POST    | `.../api/products/{id}/images`                  | productimages| `products.write`    |
| DELETE  | `.../api/products/{id}/images/{imageId}`        | productimages| `products.write`    |
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
