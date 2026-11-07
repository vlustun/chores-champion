# Prefix for running PHP tooling. Default: local php/composer.
# No local intl/pdo_mysql?  make test RUN="docker compose run --rm php"
RUN     ?=
PHP     = $(RUN) php
CONSOLE = $(PHP) bin/console
COMPOSER= $(RUN) composer

.DEFAULT_GOAL := help
.PHONY: help up down logs install migrate fixtures db-test test test-unit test-integration test-functional coverage stan cs cs-fix serve admin check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n",$$1,$$2}'

up: ## Start MySQL (waits until healthy)
	docker compose up -d --wait db

down: ## Stop containers
	docker compose down

logs: ## Tail DB logs
	docker compose logs -f db

install: ## Install composer dependencies
	$(COMPOSER) install

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

check: cs stan test ## Everything CI runs
