# Patchnotes — developer entry points (SPEC.md § 18.2).
# Every target runs inside Docker: no PHP, Composer or Node is required on the host.

DOCKER_COMP := docker compose
PHP_CONT    := $(DOCKER_COMP) exec -T php
PHP_RUN     := $(DOCKER_COMP) run --rm --no-deps php
CONSOLE     := $(PHP_CONT) php bin/console

.DEFAULT_GOAL := help
.PHONY: help up down restart logs sh build install bootstrap sync-bund sync-land demo \
        test test-unit test-integration test-e2e lint lint-fix cs phpstan rector \
        backup restore rebuild-from-git migration migrate messenger-failed worker-status \
        ai-ping ai-worker-token ai-worker-tokens audit

help: ## List available targets
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s\033[0m %s\n", $$1, $$2}'

## —— Stack ————————————————————————————————————————————————————————————————
up: ## Build (if needed) and start the whole stack, waiting until it is healthy
	$(DOCKER_COMP) up --detach --wait --wait-timeout 600

down: ## Stop the stack (volumes are preserved)
	$(DOCKER_COMP) down --remove-orphans

restart: down up ## Restart the stack

build: ## Rebuild the application image
	$(DOCKER_COMP) build --pull

logs: ## Follow the logs of all services (make logs S=worker-ai for one service)
	$(DOCKER_COMP) logs --tail=100 --follow $(S)

sh: ## Open a shell in the php container
	$(DOCKER_COMP) exec php bash

worker-status: ## Show the state of every container
	$(DOCKER_COMP) ps --format 'table {{.Service}}\t{{.Status}}'

## —— Setup ————————————————————————————————————————————————————————————————
install: up ## First-time setup: dependencies, secrets, database, assets
	$(PHP_CONT) composer install --no-interaction --prefer-dist
	$(CONSOLE) patchnotes:secrets:generate
	$(CONSOLE) doctrine:database:create --if-not-exists --no-interaction
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
	$(CONSOLE) patchnotes:config:check
	@echo "Patchnotes is ready: https://$${SERVER_NAME:-localhost}/healthz"

bootstrap: ## Initialise the laws/content repositories and import the enabled jurisdictions
	$(CONSOLE) patchnotes:bootstrap

demo: ## Demo mode: fake AI provider + fixtures, no network and no API keys required
	$(CONSOLE) patchnotes:demo:load

## —— Sources ——————————————————————————————————————————————————————————————
sync-bund: ## Run the federal (gesetze-im-internet) synchronisation now
	$(CONSOLE) patchnotes:sync:bund

sync-land: ## Run the synchronisation of one federal state: make sync-land L=be
	$(CONSOLE) patchnotes:sync:land $(L)

rebuild-from-git: ## Rebuild all derived content tables from the git repositories
	$(CONSOLE) patchnotes:rebuild-from-git

## —— AI ———————————————————————————————————————————————————————————————————
ai-ping: ## Show the model chain of every AI task (make ai-ping SAY=1 also calls the models)
	$(CONSOLE) patchnotes:ai:ping $(if $(SAY),--say,)

ai-worker-token: ## Issue a token for a remote AI worker: make ai-worker-token N=office-imac
	$(CONSOLE) patchnotes:ai:worker-token $(N)

ai-worker-tokens: ## List the tokens of the remote AI workers
	$(CONSOLE) patchnotes:ai:worker-token --list

## —— Database —————————————————————————————————————————————————————————————
migration: ## Generate a migration from the entity mapping
	$(CONSOLE) doctrine:migrations:diff --no-interaction

migrate: ## Apply pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

messenger-failed: ## Show messages in the failed transport
	$(CONSOLE) messenger:failed:show

## —— Tests ————————————————————————————————————————————————————————————————
test: ## Run the whole test suite
	$(PHP_CONT) vendor/bin/phpunit

test-unit: ## Run unit tests only
	$(PHP_CONT) vendor/bin/phpunit --testsuite=unit

test-integration: ## Run integration tests only
	$(PHP_CONT) vendor/bin/phpunit --testsuite=integration

test-e2e: ## Run end-to-end tests only
	$(PHP_CONT) vendor/bin/phpunit --testsuite=e2e

## —— Quality ——————————————————————————————————————————————————————————————
lint: cs phpstan rector ## Run every static check (no changes to the code)
	$(CONSOLE) lint:yaml config src --parse-tags
	$(CONSOLE) lint:container
	@if ls templates/*.twig >/dev/null 2>&1; then $(CONSOLE) lint:twig templates; fi
	@if ls translations/* >/dev/null 2>&1; then $(CONSOLE) lint:translations; fi

cs: ## Check code style (PHP-CS-Fixer, dry run)
	$(PHP_CONT) vendor/bin/php-cs-fixer check --diff

phpstan: ## Run static analysis
	$(PHP_CONT) vendor/bin/phpstan analyse --memory-limit=1G

rector: ## Check automated refactorings (dry run)
	$(PHP_CONT) vendor/bin/rector process --dry-run

lint-fix: ## Apply PHP-CS-Fixer and Rector fixes
	$(PHP_CONT) vendor/bin/php-cs-fixer fix
	$(PHP_CONT) vendor/bin/rector process

audit: ## Check dependencies for known vulnerabilities
	$(PHP_CONT) composer audit

## —— Backups ——————————————————————————————————————————————————————————————
backup: ## Run a database + storage backup right now
	$(DOCKER_COMP) exec -T backup /bin/bash /backup/backup.sh run

restore: ## Restore a database dump: make restore FILE=patchnotes-20260911-043000.sql.gz
	$(DOCKER_COMP) exec -T backup /bin/bash /backup/backup.sh restore $(FILE)
