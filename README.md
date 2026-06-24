# Rebuild Connector – Module PrestaShop

Module PrestaShop développé par **Rebuild IT** pour connecter la boutique à l’application mobile **PrestaFlow (Android)**.

## 🚀 Objectif

Fournir une API REST sécurisée (JSON) permettant :
- la gestion des commandes, produits, stocks et clients,
- le suivi des numéros de tracking,
- l’envoi de notifications push (Firebase Cloud Messaging).

Compatible avec **PrestaShop ≥ 1.7**.

---

## 🧩 Stack technique

| Élément | Technologie |
|----------|-------------|
| Langage | PHP 7.4+ |
| Framework | PrestaShop Module Framework |
| Authentification | JWT (HS256 / RS256) |
| Communication | JSON via HTTPS |
| Notifications | Firebase Cloud Messaging (HTTP v1) |
| Base de données | MySQL (accès natif PrestaShop) |
| CI/CD | GitHub Actions / GitLab CI |
| Tests | PHPUnit + Postman |

---

## 📁 Structure du module

```
rebuildconnector/
 ├─ controllers/front/
 │   ├─ ApiController.php
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
 ├─ rebuildconnector.php
 └─ upgrade/
     └─ index.php
```

---

## 🔗 Endpoints REST (JSON)

| Méthode | Endpoint | Description |
|----------|-----------|-------------|
| POST | /connector/login | Authentification (JWT) |
| GET | /orders | Liste des commandes (filtres `status`, `date_from/to`, `search`, `limit/offset`) |
| GET | /orders/statuses | Liste des statuts disponibles (pour les filtres) |
| GET | /orders/{id} | Détail d'une commande |
| GET | /orders/{id}/invoice | Facture PDF officielle |
| GET | /orders/{id}/shipping-label | Bordereau d'expédition PDF |
| PATCH | /orders/{id}/status | Modifier le statut |
| PATCH | /orders/{id}/shipping | Ajouter/modifier un numéro de suivi |
| GET | /products | Liste des produits (filtres `search`, stock ; champ `total`) |
| GET | /products/{id} | Détail produit + images |
| PATCH | /products/{id}/stock | Mettre à jour le stock |
| GET | /customers | Liste des clients |
| GET | /customers/stats | Total clients + nouveaux ce mois |
| GET | /customers/{id} | Détail client |
| GET | /baskets | Liste des paniers |
| GET | /baskets/{id} | Détail panier |
| GET | /dashboard/metrics | Statistiques et KPI (`period` : today/week/month/quarter/year) |
| GET | /reports?resource=bestsellers | Meilleures ventes |
| GET | /reports?resource=bestcustomers | Meilleurs clients |
| POST | /notifications/devices | Enregistrer un appareil (avec ses catégories) |
| DELETE | /notifications/devices/{token} | Désenregistrer un appareil |

---

## 🔒 Sécurité

- HTTPS obligatoire (HSTS activé)
- JWT tokens courts (60–90 min)
- Rate limiting (60 req/min/IP)
- Logs d’audit sur actions sensibles
- Aucune donnée client en clair dans les logs

---

## 🔔 Notifications (FCM)

- Envoi via **Firebase Cloud Messaging HTTP v1**
- Clé de service stockée dans la configuration du module
- Hooks utilisés :
  - `actionValidateOrder` → nouvelle commande (`order.created`)
  - `actionOrderStatusPostUpdate` → changement d’état (`order.status.changed`)
  - mise à jour de suivi → expédition (`order.shipping.updated`)

### Catégories de notifications (depuis v1.4.9)

Chaque appareil s’abonne aux catégories qu’il souhaite recevoir (réglage **par appareil** depuis
l’app PrestaFlow). Le ciblage se fait côté module via le champ `topics` de l’appareil :

| Catégorie (clé `topic`) | Événement |
|---|---|
| `order.created` | Nouvelles ventes |
| `order.status.changed` | Changements de statut |
| `order.shipping.updated` | Expéditions |

Un appareil sans préférence (`topics` vide) reçoit **toutes** les catégories (rétrocompatibilité).

### Mode hub centralisé (depuis v1.5.0)

Au lieu d'embarquer le compte de service FCM, le module peut **relayer l'envoi à un hub centralisé**
(`push.rebuild-it.fr`) qui détient l'unique compte de service Rebuild IT, gère les licences/devices et
envoie réellement à FCM. Indispensable en multi-boutiques (une app = un projet Firebase → un compte de
service tiers donnerait `SENDER_ID_MISMATCH`).

- Activation dans le BO (carte **Hub push centralisé**) : renseigner l'**URL du hub** + la **clé de licence**.
- Une fois actif, le module relaie au hub : l'**enregistrement des devices** (`POST /v1/devices`),
  leur **suppression** (`DELETE /v1/devices/{token}`) et l'**envoi** (`POST /v1/notify`).
- **Fallback** : si le hub est injoignable (réseau / HTTP non 2xx), le module retombe automatiquement
  sur l'envoi **FCM direct** local — aucune notification perdue pendant la transition.
- Champ vide = mode hub désactivé → comportement FCM direct historique inchangé.

---

## 🛠️ Configuration back-office

L’onglet *Rebuild Connector* du back-office expose les réglages suivants :

- **Accès & utilisateurs** : clé Admin (accès complet) traitée comme un **secret one-time** — affichée/QR une seule fois à la (re)génération puis masquée et stockée hachée — et **utilisateurs nommés** multiples avec scopes dédiés (chacun son QR et sa clé révocable).
- **Configuration mobile** : QR code prêt à scanner dans PrestaFlow (payload JSON `{"version":1,"shopUrl":"https://…","apiKey":"…"}`) pour injecter automatiquement l’URL API et la clé.
- **Firebase Cloud Messaging** : compte de service HTTP v1, topics par défaut et jetons fallback pour tester les notifications.
- **Hub push centralisé** *(v1.5.0)* : URL du hub + clé de licence pour relayer l'envoi à `push.rebuild-it.fr` (fallback FCM direct automatique si le hub est injoignable).
- **Webhooks** : URL de callback HTTPS, secret HMAC (aperçu + régénération) et reset possible.
- **Protection d’accès** : liste blanche d’IP/CIDR, limitation de débit configurable (requêtes/minute), activation/désactivation rapide.
- **Overrides d’environnement** : paires `KEY=VALUE` injectées dans le module pour piloter des comportements dynamiques sans redéploiement.

Toutes les entrées sont validées côté module (format JSON, URL HTTPS, IP/CIDR, format des overrides). Les erreurs sont affichées directement dans l’interface.

---

## Mise à jour

Le module vérifie automatiquement la disponibilité d'une nouvelle version depuis le back-office PrestaShop.

**Mécanisme :**
- À chaque ouverture de la page de configuration du module, `UpdateCheckService` interroge l'endpoint `https://updates.rebuild-it.fr/rebuildconnector/version.json` qui expose la dernière release GitHub.
- Le résultat est **mis en cache** 12 heures (clé `REBUILDCONNECTOR_UPDATE_CHECK` en base via `Configuration`). Aucune requête réseau n'est faite entre deux vérifications dans cette fenêtre.
- Si une version plus récente est détectée (`version_compare`), un **bandeau d'alerte** s'affiche en haut de la page avec la version disponible, un bouton « Télécharger » (`.zip` GitHub Releases) et un lien « Voir la release ».
- Le service est **fail-silent** : tout échec réseau, timeout (5 s) ou JSON invalide est absorbé silencieusement — aucun message d'erreur n'est visible, aucun log n'est écrit.
- **Pas d'installation automatique** : la mise à jour reste manuelle (téléchargement + upload ZIP via le gestionnaire de modules PrestaShop).

**Endpoint :**
```
GET https://updates.rebuild-it.fr/rebuildconnector/version.json
```
```json
{
  "module": "rebuildconnector",
  "latest": "1.5.0",
  "tag": "v1.5.0",
  "url": "https://github.com/gmalfray/rebuild-connector/releases/tag/v1.5.0",
  "download_url": "https://github.com/gmalfray/rebuild-connector/releases/download/v1.5.0/rebuildconnector.zip",
  "published_at": "2026-06-24T00:00:00Z"
}
```

---

## ⚙️ Build et packaging

```bash
zip -r rebuildconnector.zip rebuildconnector/
```

Déploiement :
- Upload ZIP via l’interface PrestaShop ou
- Copie directe dans `/modules/rebuildconnector/`

---

## 🧪 Tests

- Tests unitaires : `phpunit`
- Tests d’intégration API : Postman/Newman
- Tests manuels dans PS 1.7.8 & PS 8.x

Installation locale :

```bash
composer install
vendor/bin/phpunit --bootstrap tests/bootstrap.php --testdox
```

👉 La documentation détaillée des endpoints (authentification, schémas, exemples) est disponible dans [`docs/api.md`](docs/api.md).

---

## 📦 CI/CD

- Analyse statique : PHPStan (level 6+)
- Tests unitaires : PHPUnit
- Packaging ZIP automatique en release

---

## 🪪 Licence

**Open Software License 3.0 (OSL-3.0)** — voir [`LICENSE`](LICENSE).  
© 2026 Rebuild IT.

L’OSL-3.0 est une licence *copyleft* compatible avec l’écosystème PrestaShop (dont le cœur est sous OSL-3.0).
Toute version modifiée **distribuée** doit être publiée sous la même licence, avec son code source.
L’application mobile **PrestaFlow** est distribuée séparément sous **GPLv3** ; les deux communiquent uniquement
par API REST (aucune liaison de code).

---
