export UID ?= $(shell id -u)
export GID ?= $(shell id -g)

# Prefix for running PHP tooling. Default: local php/composer.
# No local intl/pdo_mysql?  make test RUN="docker compose run --rm php"
RUN     ?=
PHP     = $(RUN) php
CONSOLE = $(PHP) bin/console
COMPOSER= $(RUN) composer

.DEFAULT_GOAL := help
.PHONY: stack-up stack-down stack-logs stack-migrate help up down logs install install-app migrate fixtures db-test test test-unit test-integration test-functional coverage stan cs cs-fix serve admin reset-password check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n",$$1,$$2}'

STACK = docker compose $(if $(wildcard .env.docker),--env-file .env.docker)

stack-up: ## Build and start the nginx + php-fpm + MySQL stack
	$(STACK) up -d --build --wait

stack-down: ## Stop the full stack
	$(STACK) down

stack-logs: ## Tail web + app logs
	$(STACK) logs -f web app

stack-migrate: ## Run migrations inside the app container
	$(STACK) exec app php bin/console doctrine:migrations:migrate --no-interaction

up: ## Start MySQL (waits until healthy)
	docker compose up -d --wait db

down: ## Stop containers
	docker compose down

logs: ## Tail DB logs
	docker compose logs -f db

install: ## Install composer dependencies
	$(COMPOSER) install

install-app: ## Composer install inside the running app container, as the host user
	docker compose exec -u $(UID):$(GID) -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp app composer install

migrate: ## Run DB migrations (dev)
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

fixtures: ## Load dev fixtures (purges dev DB!)
	$(CONSOLE) doctrine:fixtures:load --no-interaction

db-test: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --env=test

test: db-test ## Run all test suites
	$(PHP) bin/phpunit

test-unit: ## Unit tests only (no DB)
	$(PHP) bin/phpunit --testsuite unit

test-integration: db-test ## Integration tests (test DB)
	$(PHP) bin/phpunit --testsuite integration

test-functional: db-test ## Functional (WebTestCase) tests
	$(PHP) bin/phpunit --testsuite functional

coverage: db-test ## Coverage report (needs pcov/xdebug)
	$(PHP) -d pcov.enabled=1 bin/phpunit --coverage-text --coverage-html coverage

stan: ## PHPStan static analysis
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Check code style
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style
	$(PHP) vendor/bin/php-cs-fixer fix

serve: ## Run the app on http://127.0.0.1:8000
	$(PHP) -S 0.0.0.0:8000 -t public

admin: ## Create an admin: make admin EMAIL=a@b.c NAME=Me PASSWORD=secret123
	$(CONSOLE) app:create-admin "$(EMAIL)" "$(NAME)" "$(PASSWORD)"

reset-password: ## Set a user's password: make reset-password EMAIL=a@b.c [PASSWORD=secret123] (prompts if omitted)
	$(CONSOLE) app:reset-password "$(EMAIL)" $(PASSWORD)

check: cs stan test ## Everything CI runs
