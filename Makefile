IMAGE      ?= ahceneaiti/electronics-api:1.1
KIND_NAME  ?= electronics
NS         ?= electronics

.PHONY: help install serve seed indexes compose-up compose-down \
        docker-build docker-push kind-up kind-load kind-down ingress deploy undeploy \
        argocd-install argocd-app

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## --- Local (sans conteneur) ---
install: ## composer install
	composer install

indexes: ## Cree les index MongoDB (dont l'unique sur sku)
	php bin/console doctrine:mongodb:schema:create --index --no-interaction

seed: ## Insere les produits de demo
	php bin/console app:products:seed

serve: ## Lance le serveur PHP local sur :8000
	php -S 0.0.0.0:8000 -t public

## --- Docker Compose ---
compose-up: ## Build + up (api sur :8000, mongo sur :27017)
	docker compose up --build -d
	docker compose exec api php bin/console doctrine:mongodb:schema:create --index --no-interaction
	docker compose exec api php bin/console app:products:seed

compose-down: ## Stop + suppression des volumes
	docker compose down -v

## --- Kubernetes / kind ---
docker-build: ## Build de l'image applicative
	docker build -t $(IMAGE) .

docker-push: docker-build ## Push l'image sur le registre (docker login requis)
	docker push $(IMAGE)

kind-up: ## Cree le cluster kind avec mapping ports 80/443
	kind create cluster --name $(KIND_NAME) --config k8s/kind-config.yaml

kind-load: docker-build ## Charge l'image locale dans les noeuds kind (sans registre)
	kind load docker-image $(IMAGE) --name $(KIND_NAME)

ingress: ## Installe ingress-nginx (variante kind)
	kubectl apply -f https://raw.githubusercontent.com/kubernetes/ingress-nginx/main/deploy/static/provider/kind/deploy.yaml
	kubectl -n ingress-nginx wait --for=condition=ready pod \
		--selector=app.kubernetes.io/component=controller --timeout=180s

deploy: ## Applique les manifestes (kustomize)
	kubectl apply -k k8s/
	kubectl -n $(NS) rollout status deploy/mongodb --timeout=180s
	kubectl -n $(NS) rollout status deploy/electronics-api --timeout=180s

undeploy: ## Supprime les manifestes
	kubectl delete -k k8s/ --ignore-not-found

kind-down: ## Detruit le cluster kind
	kind delete cluster --name $(KIND_NAME)

## --- ArgoCD ---
argocd-install: ## Installe ArgoCD dans le namespace argocd
	kubectl create namespace argocd --dry-run=client -o yaml | kubectl apply -f -
	kubectl apply -n argocd -f https://raw.githubusercontent.com/argoproj/argo-cd/stable/manifests/install.yaml
	kubectl -n argocd rollout status deploy/argocd-server --timeout=300s

argocd-app: ## Enregistre l'Application ArgoCD
	kubectl apply -f k8s/argocd-application.yaml
