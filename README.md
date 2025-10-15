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
| GET | /orders | Liste des commandes |
| GET | /orders/{id} | Détail d'une commande |
| PATCH | /orders/{id}/status | Modifier le statut |
| PATCH | /orders/{id}/shipping | Ajouter/modifier un numéro de suivi |
| GET | /products | Liste des produits |
| PATCH | /products/{id}/stock | Mettre à jour le stock |
| GET | /dashboard/metrics | Statistiques et KPI |

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
  - `actionValidateOrder` → nouvelle commande
  - `actionOrderStatusPostUpdate` → changement d’état

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

---

## 📦 CI/CD

- Analyse statique : PHPStan (level 6+)
- Tests unitaires : PHPUnit
- Packaging ZIP automatique en release

---

## 🪪 Licence

Apache License 2.0  
© 2025 Rebuild IT — Tous droits réservés.  
Vous êtes libre de redistribuer, modifier et utiliser ce code sous réserve de conserver les mentions de licence et d’auteur.

---
