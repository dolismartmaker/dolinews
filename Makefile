# DoliNews - development and CI targets
SHELL := /bin/bash

.PHONY: help install ci test pint phpstan seed migrate-fresh build clean

help: ## List available targets
	@grep -E '^[a-zA-Z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies and prepare the environment
	composer install
	php artisan key:generate --force
	touch database/database.sqlite
	php artisan migrate --force
	npm install

ci: pint phpstan test ## Full quality gate: formatting, static analysis, tests

test: ## Run the Pest test suite
	php artisan config:clear --ansi >/dev/null
	vendor/bin/pest

pint: ## Fix code style
	vendor/bin/pint

phpstan: ## Static analysis at the fleet level
	vendor/bin/phpstan analyse --no-progress --memory-limit=1G

seed: ## Seed the super admin account
	php artisan db:seed --force

migrate-fresh: ## Recreate the database from scratch (development only)
	php artisan migrate:fresh --force

build: ## Production asset build (validation; public pages carry no bundle)
	npm run build

clean: ## Remove caches and build artifacts
	rm -rf storage/framework/views/* storage/framework/cache/data/* bootstrap/cache/*.php node_modules/.vite public/build
