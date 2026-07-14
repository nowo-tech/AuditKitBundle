.PHONY: help up down build install shell logs test test-coverage cache-clear link-bundle update-bundle ensure-up restart release-check

help:
	@echo "Symfony 8 Demo - Audit Kit Bundle"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	docker-compose up -d --build
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@$(MAKE) link-bundle
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	docker-compose down

restart: ensure-up
	docker-compose restart

build:
	docker-compose build --no-cache

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then docker-compose up -d; sleep 3; fi

install: ensure-up
	docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up link-bundle
	docker-compose exec -e APP_ENV=test php sh -c "mkdir -p var/data && php bin/console doctrine:schema:update --force"
	docker-compose exec -e APP_ENV=test php composer test

test-coverage: ensure-up link-bundle
	docker-compose exec -e APP_ENV=test php composer test-coverage

cache-clear: ensure-up
	docker-compose exec php php bin/console cache:clear

link-bundle: ensure-up
	docker-compose exec php sh -c "mkdir -p var/data && rm -rf vendor/nowo-tech/audit-kit-bundle && ln -sf /var/audit-kit-bundle vendor/nowo-tech/audit-kit-bundle && php bin/console doctrine:schema:update --force --complete && php bin/console cache:clear"

update-bundle: ensure-up
	docker-compose exec php composer update nowo-tech/audit-kit-bundle --no-interaction
	@$(MAKE) link-bundle

release-check: ensure-up update-bundle test test-coverage

DOCKER_COMPOSE := docker-compose
SERVICE_PHP := php
include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
