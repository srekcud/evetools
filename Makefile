.PHONY: help build up down logs shell db-create db-migrate db-diff jwt-keys test install sde-import base-build deploy deploy-full

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker compose build

up: ## Start all containers
	docker compose up -d

down: ## Stop all containers
	docker compose down

logs: ## View container logs
	docker compose logs -f

shell: ## Open a shell in the app container
	docker compose exec app sh

install: ## Install dependencies
	docker compose exec app composer install

db-create: ## Create database
	docker compose exec app php bin/console doctrine:database:create --if-not-exists

db-migrate: ## Run database migrations
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

db-diff: ## Generate a migration by comparing entities with database
	docker compose exec app php bin/console doctrine:migrations:diff

jwt-keys: ## Generate JWT keypair
	docker compose exec app php bin/console lexik:jwt:generate-keypair --overwrite

test: ## Run tests
	docker compose exec app php bin/phpunit

test-unit: ## Run unit tests only
	docker compose exec app php bin/phpunit --testsuite=Unit

test-coverage: ## Run tests with coverage
	docker compose exec app php bin/phpunit --coverage-html var/coverage

cc: ## Clear cache
	docker compose exec app php bin/console cache:clear

messenger: ## Start messenger consumer
	docker compose exec app php bin/console messenger:consume async -vv

scheduler: ## Start scheduler consumer
	docker compose exec app php bin/console messenger:consume scheduler_default -vv

worker-restart: ## Restart worker container
	docker compose restart worker

ps: ## Show running containers
	docker compose ps

reset: ## Reset database (drop and recreate)
	docker compose exec app php bin/console doctrine:database:drop --force --if-exists
	docker compose exec app php bin/console doctrine:database:create
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

sde-import: ## Import EVE Online Static Data Export (SDE)
	docker compose exec app php bin/console app:sde:import --force

ansiblex-sync: ## Sync Ansiblex gates (usage: make ansiblex-sync CHARACTER="name")
	docker compose exec app php bin/console app:ansiblex:sync "$(CHARACTER)"

# ─── Base image ───
base-build: ## Build base image (run when PHP version or extensions change)
	docker build -f Dockerfile.base -t evetools-base:latest .

# ─── Production deployment ───
PROD=docker compose -f docker-compose.yaml -f docker-compose.prod.yml

deploy: ## Deploy to production (build + migrate + restart)
	git pull origin main
	$(PROD) build
	$(PROD) up -d --force-recreate --remove-orphans
	$(PROD) exec app php bin/console doctrine:migrations:migrate --no-interaction
	$(PROD) exec app php bin/console cache:clear
	$(PROD) exec app php bin/console cache:warmup
	$(PROD) restart worker

deploy-full: base-build deploy ## Full deploy (rebuild base image + deploy)
