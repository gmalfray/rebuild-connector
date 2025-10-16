# agent.md — PrestaFlow & Rebuild Connector

## 1. Objectifs, périmètre et non-objectifs
- Objectif global : fournir une application Android (PrestaFlow) et un module PrestaShop (Rebuild Connector) permettant la gestion complète d’une boutique ≥ 1.7 via une API REST sécurisée.
- Périmètre MVP : authentification par clé API, commandes, clients, produits, stocks, dashboard temps réel, notifications FCM, mode hors ligne, FR/EN.
- Extensions prévues : compatibilité PrestaShop 9, thèmes personnalisables, analytics avancés.
- Hors périmètre initial : multi-boutiques, relance de paniers, automatisations marketing tant que le module dédié n’est pas disponible.
- Objectifs de l’assistant : conserver la cohérence entre cahier des charges et implémentations, documenter les dépendances, tracer les questions en suspens (section 13 du cahier), garantir que tout contenu (texte, notifications, e-mails, écrans) soit disponible en français et en anglais.

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
└─ scripts/                          # Outils CI/CD, packaging, migrations – à créer
```
> Noter la présence éventuelle d’un répertoire `docs/` pour stocker diagrammes et spécifications complémentaires.

## 3. Environnements & secrets
- **Boutique PrestaShop** : environnements `preprod` et `prod`, HTTPS obligatoire avec certificat valide et HSTS.
- **Module Rebuild Connector** :
  - Variables config : URL service FCM, JSON du compte de service Firebase (stocké chiffré), clé secrète JWT (HS256) ou paire RSA (RS256).
  - Table `ps_configuration` pour conserver `REBUILDCONNECTOR_FCM_SERVICE_ACCOUNT`, `REBUILDCONNECTOR_API_SCOPES`.
- **Application Android** :
  - `google-services.json` (Firebase), `prestaservice.keystore` pour signature, `gradle.properties` contenant `REBUILDCONNECTOR_BASE_URL`, `CLIENT_ID`, `CLIENT_SECRET`.
  - Utiliser EncryptedSharedPrefs + Android Keystore pour stocker la clé API et le token JWT.
- **CI/CD** :
  - Variables masquées : `FIREBASE_SERVICE_ACCOUNT`, `PRESTASHOP_DEPLOY_SSH_KEY`, `PLAY_STORE_JSON`.
- Politique : aucune clé en clair dans Git, secrets injectés via vault ou CI, traductions maintenues dans `TranslationService` (FR & EN obligatoires).

## 4. Endpoints PrestaShop (REST JSON)
Base URL : `https://<boutique>/module/rebuildconnector/api`

| Méthode | Endpoint | Description | Paramètres majeurs |
|---------|----------|-------------|--------------------|
| POST | `/connector/login` | Authentifie l’utilisateur via clé API → JWT court | `{ api_key, shop_url }` |
| GET | `/orders` | Liste des commandes avec filtres | `filter[state]`, `limit`, `offset`, `sort` |
| GET | `/orders/{id}` | Détail commande, items, suivi | — |
| PATCH | `/orders/{id}/status` | Changement d’état | `{ status, comment? }` |
| PATCH | `/orders/{id}/shipping` | Ajout/édition numéro de suivi + transporteur | `{ tracking_number, carrier_id? }` |
| GET | `/products` | Catalogue avec pagination | `filter[active]`, `search`, `limit` |
| GET | `/products/{id}` | Détail produit, images, stock | — |
| PATCH | `/products/{id}` | Mise à jour prix, statut actif | `{ price, active }` |
| PATCH | `/products/{id}/stock` | MAJ stock disponible | `{ quantity, warehouse_id? }` |
| GET | `/customers` | Liste clients + stats commandes | `filter[segment]`, `limit` |
| GET | `/customers/{id}` | Fiche client, historique commandes | — |
| GET | `/dashboard/metrics` | KPI CA, commandes, top ventes | `period=day|week|month|year`, `from`, `to` |

Exemple `curl` (commande avec tracking) :
```bash
curl -X PATCH "https://example.com/module/rebuildconnector/api/orders/123/shipping" \
  -H "Authorization: Bearer <JWT>" \
  -H "Content-Type: application/json" \
  -d '{"tracking_number":"6A123456789FR","carrier_id":3}'
```

## 5. DTO / Schémas de données
```json
// OrderDTO
{
  "id": 123,
  "reference": "ABCD123",
  "status": "shipped",
  "total_paid": 72.90,
  "currency": "EUR",
  "created_at": "2025-02-10T14:25:13Z",
  "updated_at": "2025-02-11T09:02:45Z",
  "customer": { "id": 45, "firstname": "Anna", "lastname": "Dupont" },
  "items": [
    { "product_id": 88, "name": "T-shirt noir", "quantity": 2, "price": 18.00 }
  ],
  "shipping": { "carrier_id": 3, "carrier_name": "Colissimo", "tracking_number": "6A123456789FR" },
  "payments": [{ "method": "CB", "amount": 72.90, "state": "paid" }]
}

// ProductDTO
{
  "id": 88,
  "name": "T-shirt noir",
  "reference": "TSHIRT-BLACK",
  "price": 24.90,
  "active": true,
  "stock": { "quantity": 12, "stock_available_id": 120 },
  "images": [{ "id": 501, "url": "https://..." }]
}

// StockAvailableDTO
{
  "id": 120,
  "product_id": 88,
  "attribute_id": 0,
  "physical_quantity": 14,
  "available_quantity": 12,
  "warehouse_id": null,
  "updated_at": "2025-02-11T09:00:00Z"
}

// OrderCarrierDTO
{
  "order_id": 123,
  "carrier_id": 3,
  "tracking_number": "6A123456789FR",
  "shipping_number": "6A123456789FR",   // fallback orders.shipping_number
  "date_add": "2025-02-10T15:00:00Z"
}
```

## 6. Flux fonctionnels (résumé)
- **Commande → Notification** : création commande → hook `actionValidateOrder` → OrdersService prépare payload → FcmService envoie `order.created` → app reçoit push → deep link vers détail commande.
- **Changement d’état** : utilisateur mobile modifie statut → PATCH `/orders/{id}/status` → PrestaShop met à jour `order_history` et déclenche `actionOrderStatusPostUpdate` → FcmService notifie les profils abonnés.
- **Scan numéro de suivi** : app scanne/encode tracking → PATCH `/orders/{id}/shipping` → mise à jour `order_carrier` (fallback `orders`) → option notification `order.shipped`.
- **MAJ stock hors ligne** : saisie en mode offline → ajout dans file d’attente → synchro automatique dès réseau + confirmation visuelle (badge) → en cas d’échec 409 (conflit), invite à recharger la fiche.
- **Dashboard** : app requête `/dashboard/metrics?period=` → module calcule agrégats (CA, top ventes, meilleurs clients) → cache 5 min pour éviter surcharge.

## 7. Guides build & qualité
- **Android (dossier `android/`)** :
  - `./gradlew assemblePreprod` / `assembleProd`.
  - Flavors : `preprod`, `prod`, minSdk 26, targetSdk dernière stable.
  - Lint : `./gradlew lint` ; tests instrumentés `./gradlew connectedCheck`.
  - Distribution : GitHub Actions → APK (preprod) puis bundle AAB (prod).
- **Module PrestaShop (`rebuildconnector/`)** :
  - Tests : `composer install` (si nécessaire), `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`.
  - Packaging : `zip -r rebuildconnector.zip rebuildconnector/`.
  - Déploiement : upload dans `/modules/`, installation via back-office.
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
- Rate limiting configurable (par défaut 60 req/min/IP).
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
- **Post-release** :
  - Monitorer Crashlytics, logs PrestaShop, métriques d’adoption.
  - Collecter feedback utilisateurs (via formulaire in-app).
- **Rollback** :
  - Android : conserver dernière APK stable (internal track).
  - PrestaShop : prévoir zip version N-1, script SQL de rollback si migrations.
  - Restaurer clés API révoquées si retour arrière.

## 11. Règles de développement & qualité continue
- **Lint & analyse systématiques** : exécuter `find rebuildconnector -type f -name "*.php" -print0 | xargs -0 -n1 -P4 php -l` puis `phpstan analyse -l 6 rebuildconnector` avant chaque PR/commit.
- **Stubs PrestaShop** : tout nouvel usage d’une classe/constante PrestaShop doit être stubé immédiatement dans `phpstan-bootstrap.php` (`Db`, `DbQuery`, `_PS_MODE_DEV_`, etc.).
- **Typage strict** : typer explicitement les tableaux (`array<string, mixed>`, `array<int, array<string, mixed>>`), ajouter des annotations `/** @var … */` après un cast `(array)` et éviter les `is_array()` redondants.
- **Contrôleurs REST** : centraliser la logique commune (`requireAuth`, `isDevMode`, `jsonError`) via `BaseApiController` et réutiliser les helpers plutôt que re-tester les constantes.
- **Internationalisation** : toute nouvelle chaîne (erreur, succès, notifications) doit être ajoutée en FR et EN dans `TranslationService`.
- **Workflows CI** : conserver `php_ci.yml` comme référence ; ne pousser que lorsque lint et PHPStan sont verts localement.
