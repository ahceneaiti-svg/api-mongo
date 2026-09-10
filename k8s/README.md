# Déploiement Kubernetes — kind + ArgoCD

Déploiement de l'API `electronics-api` (Symfony + MongoDB) sur un cluster
[kind](https://kind.sigs.k8s.io/), d'abord **manuellement** (`kubectl` / `kustomize`),
puis en **GitOps** avec **ArgoCD**.

---

## Sommaire

1. [Prérequis](#1-prérequis)
2. [Contenu du dossier](#2-contenu-du-dossier)
3. [Création du cluster kind](#3-création-du-cluster-kind)
4. [Installation de l'Ingress NGINX](#4-installation-de-lingress-nginx)
5. [Build de l'image](#5-build-de-limage)
6. [Déploiement manuel (kustomize)](#6-déploiement-manuel-kustomize)
7. [Vérifications](#7-vérifications)
8. [Tester l'API](#8-tester-lapi)
9. [Données de démonstration (seed)](#9-données-de-démonstration-seed)
10. [Déploiement GitOps avec ArgoCD](#10-déploiement-gitops-avec-argocd)
11. [Mise à jour d'une nouvelle version d'image](#11-mise-à-jour-dune-nouvelle-version-dimage)
12. [Dépannage](#12-dépannage)
13. [Nettoyage](#13-nettoyage)

---

## 1. Prérequis

| Outil      | Version testée | Rôle                          |
|------------|----------------|-------------------------------|
| Docker     | ≥ 24           | Build image + runtime kind    |
| kind       | ≥ 0.23         | Cluster Kubernetes local      |
| kubectl    | ≥ 1.29         | Client Kubernetes             |
| ArgoCD CLI | ≥ 2.11 (opt.)  | Sync GitOps en ligne de cmd   |

Ces manifestes n'utilisent **que** des ressources standard : `Namespace`, `Secret`,
`ConfigMap`, `PersistentVolumeClaim`, `Deployment`, `Service`, `Ingress`, `Job`.

---

## 2. Contenu du dossier

| Fichier                     | Ressource                                              |
|-----------------------------|-------------------------------------------------------|
| `kind-config.yaml`          | Config cluster kind (mapping des ports 80/443)        |
| `namespace.yaml`            | Namespace `electronics`                               |
| `mongodb-secret.yaml`       | Identifiants root MongoDB (**DEMO**)                  |
| `mongodb-pvc.yaml`          | Volume persistant 2 Gi pour `/data/db`               |
| `mongodb-deployment.yaml`   | MongoDB 7, 1 réplica, stratégie `Recreate`           |
| `mongodb-service.yaml`      | Service ClusterIP `mongodb:27017`                     |
| `api-configmap.yaml`        | Variables non sensibles (`APP_ENV`, `MONGODB_DB`…)   |
| `api-secret.yaml`           | `APP_SECRET` + `MONGODB_URL` (**DEMO**)              |
| `api-deployment.yaml`       | API Symfony, 2 réplicas, probes `/health*`           |
| `api-service.yaml`          | Service ClusterIP `electronics-api:80 → 8000`        |
| `api-ingress.yaml`          | Ingress `electronics.local` → service API            |
| `api-indexes-job.yaml`      | Job de création des index Mongo (hook PostSync)      |
| `kustomization.yaml`        | Agrège toutes les ressources + tag d'image           |
| `argocd-application.yaml`   | `Application` ArgoCD pointant sur `k8s/`             |

> ⚠️ `mongodb-secret.yaml` et `api-secret.yaml` contiennent des valeurs en clair
> **pour la démo uniquement**. En production : SealedSecrets, External Secrets
> Operator ou SOPS.

---

## 3. Création du cluster kind

```bash
kind create cluster --name electronics --config k8s/kind-config.yaml
kubectl cluster-info --context kind-electronics
```

`kind-config.yaml` :
- ajoute le label `ingress-ready=true` sur le control-plane ;
- mappe `hostPort 80/443 → containerPort 80/443` pour joindre l'Ingress depuis
  la machine hôte.

---

## 4. Installation de l'Ingress NGINX

```bash
kubectl apply -f https://raw.githubusercontent.com/kubernetes/ingress-nginx/main/deploy/static/provider/kind/deploy.yaml

kubectl -n ingress-nginx wait --for=condition=ready pod \
  --selector=app.kubernetes.io/component=controller --timeout=180s
```

---

## 5. Build de l'image

Image de référence : **`ahceneaiti/electronics-api:1.0`** (référencée dans
`api-deployment.yaml`, `api-indexes-job.yaml`, `kustomization.yaml`).

```bash
# depuis la racine du projet (où se trouve le Dockerfile)
docker build -t ahceneaiti/electronics-api:1.0 .
```

Deux façons de la rendre disponible aux nœuds kind :

```bash
# a) Registre (recommandé, requis pour ArgoCD)
docker login
docker push ahceneaiti/electronics-api:1.0

# b) Chargement direct dans les nœuds kind (démo, sans registre)
kind load docker-image ahceneaiti/electronics-api:1.0 --name electronics
```

Le `Deployment` utilise `imagePullPolicy: IfNotPresent` → une image déjà présente
sur le nœud (cas b) n'est pas re-tirée.

---

## 6. Déploiement manuel (kustomize)

```bash
kubectl apply -k k8s/
```

Ordre d'arrivée à surveiller :

```bash
kubectl -n electronics rollout status deploy/mongodb        --timeout=180s
kubectl -n electronics rollout status deploy/electronics-api --timeout=180s
kubectl -n electronics get job api-create-indexes -w
```

Le `Job api-create-indexes` exécute
`bin/console doctrine:mongodb:schema:create --index` et crée notamment l'index
**unique** sur `sku`.

> En déploiement manuel, les annotations de hook ArgoCD sont ignorées : le Job
> s'exécute une fois à l'`apply`. Pour le relancer :
> `kubectl -n electronics delete job api-create-indexes && kubectl apply -k k8s/`

---

## 7. Vérifications

```bash
kubectl -n electronics get pods,svc,ingress,pvc

# logs API
kubectl -n electronics logs deploy/electronics-api -f

# readiness (ping Mongo) depuis un pod
kubectl -n electronics exec deploy/electronics-api -- \
  php -r 'echo file_get_contents("http://127.0.0.1:8000/health/ready");'
```

---

## 8. Tester l'API

### Via l'Ingress (recommandé)

Ajouter au fichier `hosts` de la machine :

```bash
echo "127.0.0.1 electronics.local" | sudo tee -a /etc/hosts
```

```bash
curl http://electronics.local/health
curl http://electronics.local/api/products
```

### Via port-forward (sans Ingress)

```bash
kubectl -n electronics port-forward svc/electronics-api 8000:80
curl http://localhost:8000/api/products
```

---

## 9. Données de démonstration (seed)

```bash
kubectl -n electronics exec deploy/electronics-api -- \
  php bin/console app:products:seed
```

Puis :

```bash
curl -s http://electronics.local/api/products | jq
```

---

## 10. Déploiement GitOps avec ArgoCD

### 10.1 Installer ArgoCD

```bash
kubectl create namespace argocd
kubectl apply -n argocd -f https://raw.githubusercontent.com/argoproj/argo-cd/stable/manifests/install.yaml
kubectl -n argocd rollout status deploy/argocd-server --timeout=300s
```

Mot de passe admin initial :

```bash
kubectl -n argocd get secret argocd-initial-admin-secret \
  -o jsonpath='{.data.password}' | base64 -d; echo
```

Accès à l'UI :

```bash
kubectl -n argocd port-forward svc/argocd-server 8080:443
# https://localhost:8080  (user: admin)
```

### 10.2 Publier `k8s/` sur un dépôt Git

ArgoCD synchronise depuis Git, pas depuis le disque local. Pousser le projet
(au moins le dossier `k8s/`) sur un dépôt accessible par le cluster, puis éditer
`k8s/argocd-application.yaml` :

```yaml
spec:
  source:
    repoURL: https://github.com/<votre-org>/<votre-repo>.git
    targetRevision: main
    path: k8s
```

Dépôt privé : `argocd repo add <repoURL> --username <u> --password <token>`.

### 10.3 Enregistrer l'Application

```bash
kubectl apply -f k8s/argocd-application.yaml

# ou avec la CLI :
argocd app create electronics-api \
  --repo https://github.com/<votre-org>/<votre-repo>.git \
  --path k8s --revision main \
  --dest-server https://kubernetes.default.svc \
  --dest-namespace electronics \
  --sync-policy automated --auto-prune --self-heal \
  --sync-option CreateNamespace=true
```

### 10.4 Synchroniser

`syncPolicy.automated` déclenche la synchro dès détection du commit. Manuellement :

```bash
argocd app sync electronics-api
argocd app wait electronics-api --health
argocd app get  electronics-api
```

Le `Job api-create-indexes` s'exécute en **hook `PostSync`** et est supprimé
en cas de succès (`hook-delete-policy: HookSucceeded`).

> **Image :** l'`Application` déploie des manifestes qui référencent
> `ahceneaiti/electronics-api:1.0`. Cette image doit être accessible aux nœuds :
> - registre (recommandé pour ArgoCD) : `docker push ahceneaiti/electronics-api:1.0` ;
> - démo sans registre : `kind load docker-image ahceneaiti/electronics-api:1.0 --name electronics` ;
> - changement de version : mettre à jour le tag dans le bloc `images:` de
>   `k8s/kustomization.yaml`, committer → ArgoCD redéploie.

---

## 11. Mise à jour d'une nouvelle version d'image

**Manuel :**

```bash
docker build -t ahceneaiti/electronics-api:1.1 .
docker push ahceneaiti/electronics-api:1.1
# ou sans registre : kind load docker-image ahceneaiti/electronics-api:1.1 --name electronics
cd k8s && kustomize edit set image ahceneaiti/electronics-api=ahceneaiti/electronics-api:1.1 && cd ..
kubectl apply -k k8s/
kubectl -n electronics rollout status deploy/electronics-api
```

**GitOps :** modifier le tag dans `k8s/kustomization.yaml`, committer, pousser.
ArgoCD applique le `RollingUpdate` (`maxUnavailable: 0`).

---

## 12. Dépannage

| Symptôme                                   | Piste                                                                 |
|--------------------------------------------|----------------------------------------------------------------------|
| `ErrImageNeverPull` / `ImagePullBackOff`   | `docker push ahceneaiti/electronics-api:1.0` ou `kind load docker-image ahceneaiti/electronics-api:1.0 --name electronics` |
| API `CrashLoopBackOff`                     | `kubectl -n electronics logs deploy/electronics-api` ; vérifier `MONGODB_URL` |
| Readiness KO, `mongo: down`                | MongoDB pas `Ready` ; `kubectl -n electronics logs deploy/mongodb`   |
| `curl electronics.local` → connexion refusée | Ingress non prêt, ou ligne `/etc/hosts` absente, ou ports 80/443 déjà pris |
| `E11000 duplicate key` à la création       | Comportement normal : `sku` unique déjà utilisé (index créé par le Job) |
| ArgoCD `OutOfSync` permanent               | `argocd app diff electronics-api` ; vérifier le tag d'image dans `kustomization.yaml` |

---

## 13. Nettoyage

```bash
# Application ArgoCD
argocd app delete electronics-api --yes        # ou: kubectl -n argocd delete -f k8s/argocd-application.yaml

# Ressources applicatives
kubectl delete -k k8s/

# Cluster complet
kind delete cluster --name electronics
```
