# electronics-api — API REST CRUD produits électroniques

API REST JSON construite avec **Symfony 7** et **MongoDB** (Doctrine MongoDB ODM).
Elle expose un CRUD complet sur des **produits électroniques** (smartphones,
ordinateurs portables, TV, audio, wearables…), avec pagination, filtres,
validation et endpoints de santé.

Conteneurisée avec **FrankenPHP**, elle se déploie sur un cluster **kind**,
d'abord manuellement puis en GitOps via **ArgoCD** — voir
[`k8s/README.md`](k8s/README.md).

---

## Sommaire

- [Stack technique](#stack-technique)
- [Architecture](#architecture)
- [Structure du projet](#structure-du-projet)
- [Modèle de données `Product`](#modèle-de-données-product)
- [Connexion à MongoDB](#connexion-à-mongodb)
- [Endpoints](#endpoints)
- [Exemples de requêtes](#exemples-de-requêtes)
- [Installation locale](#installation-locale)
- [Docker Compose](#docker-compose)
- [Déploiement Kubernetes](#déploiement-kubernetes)
- [Commandes utiles](#commandes-utiles)
- [Choix techniques](#choix-techniques)

---

## Stack technique

| Composant        | Détail                                                         |
|------------------|--------------------------------------------------------------|
| Langage          | PHP 8.3 (`ext-mongodb`)                                      |
| Framework        | Symfony 7.1 — micro-kernel, sans Twig, sans session         |
| Persistance      | MongoDB 7 via `doctrine/mongodb-odm-bundle` ^5              |
| Validation       | `symfony/validator` (contraintes par attributs)             |
| Serveur applicatif | FrankenPHP (Caddy + PHP), écoute sur `:8000`              |
| Conteneur        | Image multi-stage `Dockerfile` (base → build → runtime)     |
| Orchestration    | Kubernetes (kind), Ingress NGINX, ArgoCD                    |

---

## Architecture

### Vue infrastructure (cluster kind)

```
                       ┌───────────────────────────────────────────────┐
   client HTTP  ─────▶  │  Ingress NGINX   host: electronics.local  :80  │
                       └───────────────────────┬───────────────────────┘
                                               │
                                 ┌─────────────▼──────────────┐
                                 │ Service electronics-api     │  ClusterIP :80
                                 └─────────────┬──────────────┘
                                               │  → :8000
                          ┌────────────────────▼─────────────────────┐
                          │ Deployment electronics-api  (2 réplicas)  │
                          │   FrankenPHP + Symfony                    │
                          │   Controller → ODM DocumentManager       │
                          │   probes: /health (live) /health/ready   │
                          └────────────────────┬─────────────────────┘
                                               │ mongodb://  TCP 27017
                                               │ authSource=admin
                                 ┌─────────────▼──────────────┐
                                 │ Service mongodb             │  ClusterIP :27017
                                 └─────────────┬──────────────┘
                          ┌────────────────────▼─────────────────────┐
                          │ Deployment mongodb  (1 réplica, Recreate) │
                          │   PVC mongodb-data  2Gi → /data/db        │
                          └──────────────────────────────────────────┘

   Config : ConfigMap api-config (non sensible) + Secret api-secret (APP_SECRET, MONGODB_URL)
   Secret mongodb-credentials : MONGO_INITDB_ROOT_USERNAME / _PASSWORD
   Job api-create-indexes : doctrine:mongodb:schema:create --index  (hook ArgoCD PostSync)
```

### Vue applicative (cycle d'une requête)

```
HTTP Request
  │
  ▼
public/index.php ──▶ Kernel (Symfony, MicroKernelTrait)
  │
  ▼
Routing  (attributs #[Route] scannés dans src/Controller/)
  │
  ├─▶ HealthController      GET /  /health  /health/ready
  │
  └─▶ ProductController     /api/products*
        │  1. decode()    : parse le corps JSON (JSON_THROW_ON_ERROR)
        │  2. hydrate()   : mappe les champs présents sur le document Product
        │  3. validate()  : symfony/validator ; 422 + liste des violations sinon
        │  4. DocumentManager::persist/flush/remove
        │       └─ Doctrine ODM : unit of work, hydration, lifecycle callbacks
        │            (PrePersist/PreUpdate → createdAt / updatedAt)
        ▼
     MongoDB — base "electronics", collection "products"
        │
        ▼
   JsonResponse  (Product::toArray())

  Toute exception sous /api ou /health
     └─▶ ApiExceptionListener  ⇒  { "error": { "status": …, "message": … } }
```

### Couches

| Couche        | Fichier(s)                              | Responsabilité                                  |
|---------------|-----------------------------------------|-----------------------------------------------|
| HTTP / routing | `src/Controller/*`                     | Parsing requête, codes HTTP, sérialisation JSON |
| Domaine       | `src/Document/Product.php`              | Structure, contraintes, mapping ODM, `toArray()` |
| Accès données | `src/Repository/ProductRepository.php`  | Query Builder ODM : filtres, tri, pagination, count |
| Transverse    | `src/EventListener/ApiExceptionListener.php` | Réponses d'erreur JSON homogènes           |
| Outillage     | `src/Command/SeedProductsCommand.php`   | Jeu de données de démonstration                |

---

## Structure du projet

```
.
├── README.md                    ← ce fichier
├── composer.json
├── Dockerfile                   ← image multi-stage FrankenPHP
├── docker-compose.yml           ← pile locale api + mongodb
├── Makefile                     ← raccourcis (make help)
├── .env                         ← valeurs par défaut (non secrètes)
├── bin/console
├── public/index.php
├── config/
│   ├── bundles.php
│   ├── services.yaml
│   ├── routes.yaml
│   └── packages/
│       ├── framework.yaml
│       └── doctrine_mongodb.yaml   ← connexion + mapping ODM
├── src/
│   ├── Kernel.php
│   ├── Controller/
│   │   ├── HealthController.php
│   │   └── ProductController.php
│   ├── Document/Product.php
│   ├── Repository/ProductRepository.php
│   ├── EventListener/ApiExceptionListener.php
│   └── Command/SeedProductsCommand.php
└── k8s/                          ← manifestes + guide de déploiement
    ├── README.md
    ├── kind-config.yaml
    ├── namespace.yaml
    ├── mongodb-*.yaml
    ├── api-*.yaml
    ├── kustomization.yaml
    └── argocd-application.yaml
```

---

## Modèle de données `Product`

Collection MongoDB : **`products`** (base `electronics`).

| Champ            | Type BSON      | Contraintes                                             | Défaut  |
|------------------|----------------|--------------------------------------------------------|---------|
| `id`             | ObjectId       | généré par MongoDB, exposé en hexadécimal              | —       |
| `sku`            | string         | requis, 3–64, `[A-Za-z0-9._-]`, **unique** (index)     | —       |
| `name`           | string         | requis, 2–180                                          | —       |
| `brand`          | string         | requis, 2–120                                          | —       |
| `category`       | string         | requis, ∈ `smartphone, laptop, tablet, tv, audio, wearable, accessory, console, camera, other` | `other` |
| `description`    | string \| null | ≤ 2000                                                 | `null`  |
| `price`          | double         | requis, ≥ 0                                            | `0.0`   |
| `currency`       | string         | code ISO 4217                                          | `EUR`   |
| `stock`          | int            | ≥ 0                                                    | `0`     |
| `warrantyMonths` | int            | 0–120                                                  | `24`    |
| `specifications` | object (hash)  | paires clé/valeur libres (caractéristiques techniques) | `{}`    |
| `active`         | bool           | —                                                     | `true`  |
| `createdAt`      | date           | défini en `PrePersist`                                 | auto    |
| `updatedAt`      | date           | défini en `PrePersist` / `PreUpdate`                   | auto    |

Index déclarés sur le document (`src/Document/Product.php`) :
- `uniq_sku` : `{ sku: 1 }`, `unique: true` ;
- `{ category: 1, brand: 1 }` : accélère le filtre le plus fréquent.

Création effective des index :
`php bin/console doctrine:mongodb:schema:create --index`.

---

## Connexion à MongoDB

### Variables d'environnement

| Variable      | Rôle                                  | Exemple local                          |
|---------------|---------------------------------------|----------------------------------------|
| `MONGODB_URL` | chaîne de connexion du driver         | `mongodb://localhost:27017`            |
| `MONGODB_DB`  | base de données par défaut            | `electronics`                          |

### Format de la chaîne de connexion

```
mongodb://[utilisateur:motdepasse@]hote1[:port][,hote2[:port]...]/[?option=valeur&...]
```

| Contexte          | `MONGODB_URL`                                                                              |
|-------------------|------------------------------------------------------------------------------------------|
| Local sans auth   | `mongodb://localhost:27017`                                                              |
| Docker Compose    | `mongodb://root:root@mongodb:27017/?authSource=admin`                                    |
| Kubernetes (kind) | `mongodb://root:S3cureChangeMe@mongodb.electronics.svc.cluster.local:27017/?authSource=admin` |

- `authSource=admin` : l'utilisateur `root` est créé par l'image `mongo` dans la
  base `admin` (variables `MONGO_INITDB_ROOT_*`), pas dans `electronics`.
- Mot de passe contenant `@ : / ? #` → l'encoder en pourcentage (`%40`, `%3A`…).
  Les valeurs par défaut de ce projet en sont volontairement dépourvues.
- Réplica set : `...&replicaSet=rs0` ; TLS : `...&tls=true`.

### Configuration Symfony / ODM

`config/packages/doctrine_mongodb.yaml` :

```yaml
doctrine_mongodb:
    connections:
        default:
            server: '%env(MONGODB_URL)%'
    default_database: '%env(MONGODB_DB)%'
    document_managers:
        default:
            mappings:
                App:
                    type: attribute
                    dir: '%kernel.project_dir%/src/Document'
                    prefix: 'App\Document'
```

- Le driver `ext-mongodb` gère un **pool de connexions** : les sockets vers le
  serveur sont réutilisés entre requêtes (pas de handshake TCP/auth par appel).
- `DocumentManager` (service autowiré) : point d'entrée unique pour `persist`,
  `flush`, `remove`, `getRepository()`.

### Vérifier la connexion

```bash
# via l'API (ping de la commande admin)
curl -s localhost:8000/health/ready
# {"status":"ready","mongo":"up"}

# via mongosh
mongosh "mongodb://localhost:27017/electronics" --eval "db.products.countDocuments()"
```

---

## Endpoints

| Méthode        | Chemin                    | Description                                  | Codes                       |
|----------------|---------------------------|--------------------------------------------|-----------------------------|
| `GET`          | `/`                       | Métadonnées de l'API                        | 200                         |
| `GET`          | `/health`                 | Liveness (process vivant)                   | 200                         |
| `GET`          | `/health/ready`           | Readiness (ping MongoDB)                    | 200 / 503                   |
| `GET`          | `/api/products`           | Liste paginée + filtres                     | 200                         |
| `POST`         | `/api/products`           | Création                                    | 201 / 400 / 409 / 422       |
| `GET`          | `/api/products/{id}`      | Détail d'un produit                         | 200 / 404                   |
| `PUT` / `PATCH`| `/api/products/{id}`      | Mise à jour (champs fournis uniquement)     | 200 / 400 / 404 / 422       |
| `DELETE`       | `/api/products/{id}`      | Suppression                                 | 204 / 404                   |

### Paramètres de `GET /api/products`

| Param      | Type   | Défaut | Effet                                                        |
|------------|--------|--------|------------------------------------------------------------|
| `q`        | string | —      | recherche insensible à la casse sur `name`, `sku`, `brand`, `description` |
| `category` | string | —      | filtre exact sur la catégorie                              |
| `brand`    | string | —      | filtre exact sur la marque                                 |
| `active`   | bool   | —      | `true` / `false`                                           |
| `page`     | int    | `1`    | numéro de page (≥ 1)                                       |
| `limit`    | int    | `20`   | taille de page (1–100)                                     |

Réponse :

```json
{
  "data": [ { "id": "…", "sku": "…", "…": "…" } ],
  "meta": { "page": 1, "limit": 20, "total": 6, "pages": 1 }
}
```

---

## Exemples de requêtes

> Base URL locale : `http://localhost:8000` — via kind/Ingress : `http://electronics.local`.

### Créer un produit — `POST /api/products`

```bash
curl -X POST localhost:8000/api/products \
  -H 'Content-Type: application/json' \
  -d '{
    "sku": "APL-IPH15-128",
    "name": "iPhone 15 128 Go Noir",
    "brand": "Apple",
    "category": "smartphone",
    "description": "Ecran 6,1\" OLED Super Retina XDR, USB-C, puce A16 Bionic.",
    "price": 869.00,
    "currency": "EUR",
    "stock": 42,
    "warrantyMonths": 24,
    "specifications": { "ram_gb": 6, "storage_gb": 128, "screen_in": 6.1, "5g": true }
  }'
```

`201 Created` :

```json
{
  "id": "6640f1a2b3c4d5e6f7089abc",
  "sku": "APL-IPH15-128",
  "name": "iPhone 15 128 Go Noir",
  "brand": "Apple",
  "category": "smartphone",
  "description": "Ecran 6,1\" OLED Super Retina XDR, USB-C, puce A16 Bionic.",
  "price": 869,
  "currency": "EUR",
  "stock": 42,
  "warrantyMonths": 24,
  "specifications": { "ram_gb": 6, "storage_gb": 128, "screen_in": 6.1, "5g": true },
  "active": true,
  "createdAt": "2026-09-10T09:12:04+00:00",
  "updatedAt": "2026-09-10T09:12:04+00:00"
}
```

### Lister avec filtres — `GET /api/products`

```bash
curl 'localhost:8000/api/products?category=smartphone&brand=Apple&q=iphone&page=1&limit=20'
```

```json
{
  "data": [
    { "id": "6640f1a2b3c4d5e6f7089abc", "sku": "APL-IPH15-128", "name": "iPhone 15 128 Go Noir", "price": 869, "stock": 42, "active": true }
  ],
  "meta": { "page": 1, "limit": 20, "total": 1, "pages": 1 }
}
```

### Détail — `GET /api/products/{id}`

```bash
curl localhost:8000/api/products/6640f1a2b3c4d5e6f7089abc
```

### Mise à jour partielle — `PATCH /api/products/{id}`

```bash
curl -X PATCH localhost:8000/api/products/6640f1a2b3c4d5e6f7089abc \
  -H 'Content-Type: application/json' \
  -d '{ "price": 799.00, "stock": 30 }'
```

`200 OK` — seuls les champs fournis sont modifiés ; `updatedAt` est rafraîchi.

### Remplacement — `PUT /api/products/{id}`

```bash
curl -X PUT localhost:8000/api/products/6640f1a2b3c4d5e6f7089abc \
  -H 'Content-Type: application/json' \
  -d '{
    "sku": "APL-IPH15-128",
    "name": "iPhone 15 128 Go Bleu",
    "brand": "Apple",
    "category": "smartphone",
    "price": 849.00,
    "stock": 25,
    "warrantyMonths": 24,
    "specifications": { "ram_gb": 6, "storage_gb": 128, "color": "bleu" }
  }'
```

### Supprimer — `DELETE /api/products/{id}`

```bash
curl -i -X DELETE localhost:8000/api/products/6640f1a2b3c4d5e6f7089abc
# HTTP/1.1 204 No Content
```

### Réponses d'erreur

`404` produit introuvable :

```json
{ "error": { "status": 404, "message": "Produit \"6640…\" introuvable." } }
```

`409` SKU déjà utilisé (index unique) :

```json
{ "error": { "status": 409, "message": "SKU \"APL-IPH15-128\" deja utilise." } }
```

`422` validation :

```json
{
  "error": { "status": 422, "message": "Validation echouee." },
  "violations": [
    { "field": "price", "message": "Cette valeur doit être supérieure ou égale à 0." },
    { "field": "category", "message": "Categorie invalide." }
  ]
}
```

`400` JSON malformé :

```json
{ "error": { "status": 400, "message": "Corps JSON invalide: Syntax error" } }
```

---

## Installation locale

Prérequis : PHP 8.2+ avec `ext-mongodb`, Composer, une instance MongoDB.

```bash
# 1. dépendances
composer install

# 2. MongoDB (exemple via Docker)
docker run -d --name mongo -p 27017:27017 mongo:7

# 3. configuration : créer .env.local si besoin
#    MONGODB_URL=mongodb://localhost:27017
#    MONGODB_DB=electronics

# 4. index (unique sur sku)
php bin/console doctrine:mongodb:schema:create --index

# 5. jeu de données de démo (6 produits)
php bin/console app:products:seed

# 6. serveur
php -S 0.0.0.0:8000 -t public
#   ou, si Symfony CLI est installé : symfony serve
```

Test : `curl localhost:8000/api/products`.

---

## Docker Compose

Pile complète (API + MongoDB) :

```bash
docker compose up --build -d

# index + seed dans le conteneur api
docker compose exec api php bin/console doctrine:mongodb:schema:create --index --no-interaction
docker compose exec api php bin/console app:products:seed

curl localhost:8000/api/products
docker compose down -v      # arrêt + suppression du volume Mongo
```

| Service   | Port hôte | Notes                                             |
|-----------|-----------|--------------------------------------------------|
| `api`     | `8000`    | image applicative (stage `runtime` du Dockerfile) |
| `mongodb` | `27017`   | `root` / `root`, base `electronics`, volume nommé |

---

## Déploiement Kubernetes

Guide complet dans **[`k8s/README.md`](k8s/README.md)**. Résumé :

```bash
kind create cluster --name electronics --config k8s/kind-config.yaml
kubectl apply -f https://raw.githubusercontent.com/kubernetes/ingress-nginx/main/deploy/static/provider/kind/deploy.yaml
kubectl -n ingress-nginx wait --for=condition=ready pod \
  --selector=app.kubernetes.io/component=controller --timeout=180s

docker build -t electronics-api:local .
kind load docker-image electronics-api:local --name electronics

kubectl apply -k k8s/
kubectl -n electronics rollout status deploy/electronics-api

echo "127.0.0.1 electronics.local" | sudo tee -a /etc/hosts
curl http://electronics.local/api/products
```

GitOps ArgoCD : pousser `k8s/` sur Git, adapter `k8s/argocd-application.yaml`,
`kubectl apply -f k8s/argocd-application.yaml` — détails en section 10 du guide k8s.

---

## Commandes utiles

```bash
make help                                   # liste des cibles

php bin/console debug:router                 # routes
php bin/console doctrine:mongodb:schema:create --index
php bin/console doctrine:mongodb:schema:update --index
php bin/console app:products:seed
php bin/console cache:clear

# inspection Mongo
mongosh "mongodb://localhost:27017/electronics"
> db.products.getIndexes()
> db.products.find({ category: "smartphone" }).pretty()
```

---

## Choix techniques

- **Sérialisation manuelle** (`Product::toArray()`) plutôt que le composant
  Serializer : sortie JSON explicite et stable, zéro configuration de groupes.
- **`hydrate()` par champ présent** : `PATCH` et `PUT` partagent le même code ;
  seuls les champs fournis dans le corps sont écrits.
- **Validation après hydratation**, avant `flush` : `422` + liste
  `{ field, message }` renvoyée directement par le contrôleur.
- **`ApiExceptionListener`** : uniformise toute exception non gérée sous
  `/api` ou `/health` en enveloppe `{ "error": { "status", "message" } }`.
- **Unicité du `sku`** garantie côté base (index `unique`) ; la violation
  `E11000` est traduite en `409 Conflict`.
- **FrankenPHP** : un seul processus sert `public/` en HTTP sur `:8000`,
  probes Kubernetes branchées sur `/health` (liveness) et `/health/ready`
  (readiness, avec ping MongoDB).
- **MongoDB en `Deployment` + PVC** avec stratégie `Recreate` : suffisant pour
  une démo mono-nœud ; un `StatefulSet` (voire l'opérateur MongoDB) est
  recommandé en production.
