# agent.md — PrestaFlow & Rebuild Connector

## 1. Objectifs, périmètre et non-objectifs
- Objectif global : fournir une application Android (PrestaFlow) et un module PrestaShop (Rebuild Connector) permettant la gestion complète d’une boutique ≥ 1.7 via une API REST sécurisée.
- Périmètre MVP : authentification par clé API, commandes, clients, produits, stocks, dashboard temps réel, notifications FCM, mode hors ligne, FR/EN.
- Extensions prévues : compatibilité PrestaShop 9, thèmes personnalisables, analytics avancés.
- Hors périmètre initial : multi-boutiques, relance de paniers, automatisations marketing tant que le module dédié n’est pas disponible.
- Objectifs de l’assistant : conserver la cohérence entre cahier des charges et implémentations, documenter les dépendances, tracer les questions en suspens (section 13 du cahier), garantir que tout contenu (texte, notifications, e-mails, écrans) soit disponible en français et en anglais.
- Version courante : **Rebuild Connector 1.1.2** (avril 2025) — payloads REST plats compatibles avec l’app PrestaFlow `main`.

## 2. Arborescence du dépôt
```
repo-root/
├─ agent.md
├─ rebuildconnector/                 # Module PrestaShop
│  ├─ classes/                       # Services métiers (auth, orders, products…), i18n (TranslationService)
│  ├─ controllers/front/             # Contrôleurs REST JSON
│  ├─ views/templates/admin/         # UI de configuration module
│  ├─ upgrade/                       # Scripts d’upgrade PrestaShop
│  └─ rebuildconnector.php           # Bootstrap module
├─ android/                          # App PrestaFlow (Jetpack Compose) – à créer
│  ├─ app/                           # Module applicatif
│  ├─ build.gradle                   # Build global
│  └─ google-services.json           # Config Firebase (non versionné)
└─ scripts/                          # Outils CI/CD & QA
   └─ test_preprod.sh                # Script fumée curl (login + GET clés)
```
> Noter la présence éventuelle d’un répertoire `docs/` pour stocker diagrammes et spécifications complémentaires.

## 3. Environnements & secrets
- **Boutique PrestaShop** : environnements `preprod` et `prod`, HTTPS obligatoire avec certificat valide et HSTS.
- **Module Rebuild Connector** :
  - Variables config : URL service FCM, JSON du compte de service Firebase (stocké chiffré), clé secrète JWT (HS256) ou paire RSA (RS256), URL webhook + secret HMAC, liste blanche IP/CIDR, limite de requêtes par minute, overrides d’environnement (`KEY=VALUE`).
  - Table `ps_configuration` pour conserver `REBUILDCONNECTOR_FCM_SERVICE_ACCOUNT`, `REBUILDCONNECTOR_API_SCOPES`, `REBUILDCONNECTOR_RATE_LIMIT`, `REBUILDCONNECTOR_ALLOWED_IPS`, `REBUILDCONNECTOR_ENV_OVERRIDES`, etc.
- **Module PrestaShot** :
  - Code situé dans `prestashot/`. Fournit une page BO (Modules > PrestaShot) permettant d’enregistrer la clé API dédiée à l’app mobile et de générer un QR code.
  - Payload QR : `{"version":1,"shopUrl":"<https://.../>","apiKey":"<clé>"}` encodé en Base64 et exposé via l’URL schéma `prestaflow://setup?data=<base64Payload>`.
  - Boutons d’aide : rafraîchir le QR, copier le JSON ou le lien deep-link. Les QR sont servis via `https://api.qrserver.com/v1/create-qr-code/` (dépendance externe sans clé).
  - Après modification de la clé, recommander de rescanner côté mobile; pas de signature pour l’instant, la rotation de clé reste manuelle.
- **Application Android** :
  - `google-services.json` (Firebase), `prestaservice.keystore` pour signature, `gradle.properties` contenant `REBUILDCONNECTOR_BASE_URL`, `CLIENT_ID`, `CLIENT_SECRET`.
  - Utiliser EncryptedSharedPrefs + Android Keystore pour stocker la clé API et le token JWT.
- **CI/CD** :
  - Variables masquées : `FIREBASE_SERVICE_ACCOUNT`, `PRESTASHOP_DEPLOY_SSH_KEY`, `PLAY_STORE_JSON`.
- Politique : aucune clé en clair dans Git, secrets injectés via vault ou CI, traductions maintenues dans `TranslationService` (FR & EN obligatoires).

## 4. Endpoints PrestaShop (REST JSON)
Base URL : `https://<boutique>/module/rebuildconnector/api` — module **1.1.2** renvoie des payloads plats (pas de clé `data`).

| Méthode | Endpoint | Description | Payload |
|---------|----------|-------------|---------|
| POST | `/connector/login` | Auth via clé API → JWT | Réponse `{ "token": "...", "access_token": "...", "expires_in": 3600, "scopes": [...] }`. |
| GET | `/orders` | Liste commandes paginée | `{ "orders": [ { "id", "reference", "status", "total_paid", "currency", "date_upd", "customer": { "firstname", "lastname" } } ] }`. |
| GET | `/orders/{id}` | Détail commande | `{ "order": { ... } }` (structure complète : items, shipping, history). |
| PATCH | `/orders/{id}/status` | Changer état | Corps `{ "status": "<id|name>" }`. |
| PATCH | `/orders/{id}/shipping` | MAJ tracking | Corps `{ "tracking_number": "...", "carrier_id": 3? }`. |
| GET | `/products` | Catalogue produits | `{ "products": [ { "id", "name", "reference", "price", "active", "stock": { "quantity", "warehouse_id", "updated_at" }, "images": [{ "id", "url" }], "updated_at" } ], "pagination": {...} }`. |
| GET | `/products/{id}` | Détail produit | `{ "product": { ... } }`. |
| PATCH | `/products/{id}` | MAJ prix/statut | Champs gérés : `price_tax_excl`, `active`. |
| PATCH | `/products/{id}/stock` | MAJ stock | `{ "quantity": 42, "warehouse_id": null }`. |
| GET | `/customers` | Liste clients | `{ "customers": [ { "id", "firstname", "lastname", "email", "orders_count", "total_spent", "last_order_at" } ], "pagination": {...} }`. |
| GET | `/customers/{id}` | Fiche client | `{ "customer": { ..., "orders": [...] } }`. |
| GET | `/customers/top` | Alias best customers | Identique à `reports?resource=bestcustomers`. |
| GET | `/reports?resource=bestsellers` | Top ventes | `{ "products": [ { "product_id", "quantity", "total_tax_incl", ... } ] }`. |
| GET | `/reports?resource=bestcustomers` | Top clients | `{ "customers": [ { "id", "firstname", "lastname", "total_spent", "last_order_at" } ] }`. |
| GET | `/dashboard/metrics` | KPI | `{ "turnover", "orders_count", "customers_count", "products_count", "currency", "chart": [{ "label", "turnover", "orders", "customers" }] }`. |
| GET | `/baskets` | Paniers (lecture) | `{ "baskets": [...] }` – endpoints prêts, vue app à implémenter. |

Exemple `curl` :
```bash
curl -X PATCH "https://example.com/module/rebuildconnector/api/orders/123/shipping" \
  -H "Authorization: Bearer <JWT>" \
  -H "Content-Type: application/json" \
  -d '{"tracking_number":"6A123456789FR","carrier_id":3}'
```

## 5. DTO / Schémas de données (réponses réelles)
```json
// GET /orders
{
  "orders": [
    {
      "id": 123,
      "reference": "ABCD123",
      "status": "shipped",
      "total_paid": 72.9,
      "currency": "EUR",
      "date_upd": "2025-02-11 09:02:45",
      "customer": { "firstname": "Anna", "lastname": "Dupont" }
    }
  ]
}

// GET /orders/123
{
  "order": {
    "id": 123,
    "reference": "ABCD123",
    "status": { "id": 4, "name": "Shipped" },
    "totals": {
      "paid_tax_incl": 72.9,
      "paid_tax_excl": 60.75,
      "currency": "EUR"
    },
    "customer": {
      "id": 45,
      "firstname": "Anna",
      "lastname": "Dupont",
      "email": "anna@example.com"
    },
    "shipping": {
      "carrier_id": 3,
      "carrier_name": "Colissimo",
      "tracking_number": "6A123456789FR"
    },
    "items": [
      { "product_id": 88, "name": "T-shirt noir", "quantity": 2, "price_tax_incl": 36.45 }
    ],
    "history": [
      { "order_state_id": 4, "status": "Shipped", "date_add": "2025-02-11 09:02:45" }
    ]
  }
}

// GET /products
{
  "products": [
    {
      "id": 88,
      "name": "T-shirt noir",
      "reference": "TSHIRT-BLACK",
      "price": 29.88,
      "active": true,
      "stock": {
        "quantity": 12,
        "warehouse_id": null,
        "updated_at": "2025-02-11T09:00:00Z"
      },
      "images": [
        { "id": 501, "url": "https://example.com/img/88-501-large_default.jpg" }
      ],
      "updated_at": "2025-02-10 08:30:00"
    }
  ],
  "pagination": { "limit": 50, "offset": 0, "count": 1, "has_next": false }
}

// GET /customers
{
  "customers": [
    {
      "id": 45,
      "firstname": "Anna",
      "lastname": "Dupont",
      "email": "anna@example.com",
      "orders_count": 3,
      "total_spent": 240.5,
      "last_order_at": "2025-02-10 14:25:13"
    }
  ],
  "pagination": { "limit": 20, "offset": 0, "count": 1, "has_next": false }
}

// GET /dashboard/metrics
{
  "turnover": 12500.42,
  "orders_count": 182,
  "customers_count": 95,
  "products_count": 340,
  "currency": "EUR",
  "chart": [
    { "label": "2025-02-05", "turnover": 1520.0, "orders": 18, "customers": 12 },
    { "label": "2025-02-06", "turnover": 980.0, "orders": 11, "customers": 9 }
  ]
}
```

## 6. Flux fonctionnels (résumé)
- **Commande → Notification** : création commande → hook `actionValidateOrder` → OrdersService prépare payload → FcmService envoie `order.created` → app reçoit push → deep link vers détail commande.
- **Changement d’état** : utilisateur mobile modifie statut → PATCH `/orders/{id}/status` → PrestaShop met à jour `order_history` et déclenche `actionOrderStatusPostUpdate` → FcmService notifie les profils abonnés.
- **Scan numéro de suivi** : app scanne/encode tracking → PATCH `/orders/{id}/shipping` → mise à jour `order_carrier` (fallback `orders`) → option notification `order.shipped`.
- **MAJ stock hors ligne** : saisie en mode offline → ajout dans file d’attente → synchro automatique dès réseau + confirmation visuelle (badge) → en cas d’échec 409 (conflit), invite à recharger la fiche.
- **Dashboard** : app requête `/dashboard/metrics?period=` → module calcule agrégats (CA TTC/HT, TVA collectée, panier moyen, #retours, top ventes à venir) → cache 5 min pour éviter surcharge.

## 7. Guides build & qualité
- **Android (dossier `android/`)** :
  - `./gradlew assemblePreprod` / `assembleProd`.
  - Flavors : `preprod`, `prod`, minSdk 26, targetSdk dernière stable.
  - Lint : `./gradlew lint` ; tests instrumentés `./gradlew connectedCheck`.
  - Distribution : GitHub Actions → APK (preprod) puis bundle AAB (prod).
- **Module PrestaShop (`rebuildconnector/`)** :
  - Tests : `composer install` (si nécessaire), `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`.
  - Smoke test HTTP : `scripts/test_preprod.sh --base-url https://shop --api-key XXX` (login + GET orders/products/dashboard/clients).
  - Packaging : `zip -r rebuildconnector.zip rebuildconnector/`.
  - Déploiement : upload dans `/modules/`, installation via back-office.
  - Configuration BO : QR code JSON (`{"version":1,"shopUrl":"https://…","apiKey":"…"}`) pour renseigner automatiquement URL API + clé dans PrestaFlow (HTTPS forcé, legacy URL encore exposée en secours).
- **Documentation/diagrammes** : stocker dans `docs/` + exporter version PNG/PDF pour les diagrammes Mermaid.
- **Internationalisation** : tout nouveau texte doit passer par `TranslationService` avec variantes FR/EN ; vérifier la présence des deux langues dans les revues.

## 8. Gestion des erreurs & retries
- HTTP 401/403 : rafraîchir le token via `/connector/login`, notifier l’utilisateur si échec répété.
- HTTP 404 : afficher message contextuel (commande/produit supprimé).
- HTTP 409 (stock) : recharger les données, proposer merge manuel.
- HTTP 422 : afficher validation côté serveur.
- HTTP 429 / 5xx : backoff exponentiel (1s, 2s, 4s, max 30s) + file d’attente offline.
- Perte réseau : lecture depuis cache Room/Realm, file d’attente des PATCH/POST chiffrée ; badge de synchro dans l’UI.
- Logs : Crashlytics + logging local (niveau debug désactivé en prod).

## 9. Sécurité
- HTTPS obligatoire + HSTS ; refuser HTTP dans l’app (Network Security Config).
- Authentification : clé API PrestaFlow → JWT court (60–90 min) avec scopes (`orders.read`, `stock.write`, etc.).
- Rotation des tokens et révocation depuis back-office module.
- Stockage sécurisé : EncryptedSharedPrefs + Keystore (Android), configuration module chiffrée côté PrestaShop.
- Permissions Android minimales (CAMERA pour scan, POST_NOTIFICATIONS).
- Audit trail : journaliser (UserID, action, endpoint, timestamp, IP).
- Audit trail : journaliser (UserID, action, endpoint, timestamp, IP) — aujourd’hui couverts : `orders.status.updated`, `orders.shipping.updated`, `products.stock.updated`, `products.attributes.updated`.
- Rate limiting configurable (par défaut 60 req/min/IP) avec stockage `rebuildconnector_rate_limit` (IP + jeton) et audit des dépassements 429.
- Allowlist IP appliquée côté API (403 si l’adresse ne correspond pas aux plages autorisées).
- Webhooks HTTPS signés HMAC (`WebhookService`) déclenchés sur `order.created`, `order.status.changed`, `order.shipping.updated`, `product.stock.updated`, `product.attributes.updated`.
- Table `rebuildconnector_audit_log` pour tracer les événements (`api.request`, notifications, incidents sécurité).
- Conformité RGPD : minimiser les données, purger logs sensibles, masquage automatique des emails/phones non nécessaires.

## 10. Checklists
- **Pré-release Android** :
  - Vérifier numéros de version (codeName + versionCode).
  - Générer changelog, tester scénarios critiques (auth, commande, stock, notif).
  - Lancer lint + tests unitaires/instrumentés.
  - Valider Crashlytics et FCM (token enregistré).
- **Pré-release module** :
  - PHPStan, PHPUnit, tests Postman sur endpoints critiques.
  - Vérifier configuration hooks, traductions FR/EN, droits d’écriture.
  - Mettre à jour `config.xml` (version, compatibilité 1.7–8.x).
  - `vendor/bin/phpunit --bootstrap tests/bootstrap.php --testdox` (couverture FCM + filtres + endpoints).
- **Post-release** :
  - Monitorer Crashlytics, logs PrestaShop, métriques d’adoption.
  - Collecter feedback utilisateurs (via formulaire in-app).
- **Rollback** :
  - Android : conserver dernière APK stable (internal track).
  - PrestaShop : prévoir zip version N-1, script SQL de rollback si migrations.
  - Restaurer clés API révoquées si retour arrière.

## 11. Règles de développement & qualité continue
- **Lint & analyse systématiques** : exécuter `find rebuildconnector -type f -name "*.php" -print0 | xargs -0 -n1 -P4 php -l` puis `phpstan analyse -l 6 rebuildconnector` avant chaque PR/commit.
- **Stubs PrestaShop** : tout nouvel usage d’une classe/constante PrestaShop doit être stubé immédiatement dans `phpstan-bootstrap.php` (`Db`, `DbQuery`, `_PS_MODE_DEV_`, etc.). Ajoutez systématiquement les méthodes CRUD (`Db::insert`, `Db::update`, `Db::delete`, `Db::execute`) et les constantes comme `_MYSQL_ENGINE_` dès que vous les consommez pour éviter des régressions PHPStan. Pensez aussi à ajouter les méthodes manquantes (`ModuleFrontController::init()`, `Tools::getRemoteAddr()`, etc.) dès qu’elles sont invoquées dans le code.
- **Typage des itérables** : documentez les tableaux passés aux services (`@param array<int, string> $topics`, etc.) pour éviter les erreurs `missingType.iterableValue` et simplifier la lecture des reviewers.
- **Paramètres non typés** : lorsqu’un argument reste volontairement `mixed`, ajoutez un `@param mixed ...` explicite dans la PHPDoc (ex. parseurs de filtres) afin d’éviter les erreurs PHPStan `missingType.parameter`.
- **Contrôle de type redondant** : n’ajoutez pas de `is_string()`/`is_int()` lorsque la variable est déjà typée ou castée ; PHPStan remontera une alerte `function.alreadyNarrowedType`. Exemple : `Tools::getRemoteAddr()` est stubé pour retourner une string, donc un simple test `=== ''` suffit.
- **Contrôle de type redondant** : n’ajoutez pas de `is_string()`/`is_int()` lorsque la variable est déjà typée ou castée ; PHPStan remontera une alerte `function.alreadyNarrowedType`. Exemple : `Tools::getRemoteAddr()` est stubé pour retourner une string, donc un simple test `=== ''` suffit.
- **Stubs d’API** : si l’on consomme des méthodes PrestaShop susceptibles de renvoyer `false` ou des propriétés optionnelles (`Image::getImages()`, `Context::$link`, `Product::$link_rewrite`), mettre à jour `phpstan-bootstrap.php` pour refléter le contrat réel plutôt que d’ajouter des gardes redondants que PHPStan signalera comme impossibles.
- **Typage strict** : typer explicitement les tableaux (`array<string, mixed>`, `array<int, array<string, mixed>>`), ajouter des annotations `/** @var … */` après un cast `(array)` et éviter les `is_array()` redondants.
- **Contrôleurs REST** : centraliser la logique commune (`requireAuth`, `isDevMode`, `jsonError`) via `BaseApiController` et réutiliser les helpers plutôt que re-tester les constantes.
- **Dev mode** : passer systématiquement par `isDevMode()` (ou équivalent) au lieu d’expressions `defined('_PS_MODE_DEV_') && ...` pour éviter les avertissements statiques.
- **Internationalisation** : toute nouvelle chaîne (erreur, succès, notifications) doit être ajoutée en FR et EN dans `TranslationService`.
- **Paramètres BO** : toute nouvelle option de configuration doit passer par `SettingsService` (get/set/export + validations), être exposée dans le template BO, documentée dans `README.md` et `agent.md`, et accompagnée de traductions FR/EN + messages d’erreur cohérents.
- **Documentation API** : maintenir `docs/api.md` à jour (auth, entrées/sorties, exemples). Toute évolution d’endpoint doit y figurer avant la revue.
- **Workflows CI** : conserver `php_ci.yml` comme référence ; ne pousser que lorsque lint et PHPStan sont verts localement.
