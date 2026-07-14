# Makefile for Twig Inspector Bundle
# Simplifies Docker commands for development

.PHONY: help up down shell install test test-coverage cs-check cs-fix qa clean setup-hooks test-up test-down test-shell build-assets build-assets-dev watch-assets clean-assets

# Default target
help:
	@echo "Twig Inspector Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up            Start Docker container"
	@echo "  down          Stop Docker container"
	@echo "  shell         Open shell in container"
	@echo "  install       Install Composer dependencies"
	@echo "  test          Run PHPUnit tests"
	@echo "  test-coverage Run tests with code coverage"
	@echo "  test-up       Start test container"
	@echo "  test-down     Stop test container"
	@echo "  test-shell    Open shell in test container"
	@echo "  cs-check      Check code style"
	@echo "  cs-fix        Fix code style"
	@echo "  qa            Run all QA checks (cs-check + test)"
	@echo "  clean         Remove vendor and cache"
	@echo "  setup-hooks   Install git pre-commit hooks"
	@echo "  build-assets  Build TypeScript and SCSS assets"
	@echo "  build-assets-dev Build assets in development mode"
	@echo "  watch-assets  Watch assets for changes"
	@echo "  clean-assets  Clean built assets"
	@echo ""

# Build and start container
up:
	docker-compose build
	docker-compose up -d
	@echo "Installing dependencies..."
	docker-compose exec php composer install --no-interaction
	@echo "✅ Container ready!"

# Stop container
down:
	docker-compose down

# Open shell in container
shell:
	docker-compose exec php sh

# Install dependencies
install:
	docker-compose exec php composer install

# Run tests
test:
	docker-compose exec php composer test

# Run tests with coverage
test-coverage:
	docker-compose exec php composer test-coverage

# Start test container
test-up:
	docker-compose -f docker-compose.test.yml build
	docker-compose -f docker-compose.test.yml up -d
	@echo "Installing dependencies..."
	docker-compose -f docker-compose.test.yml exec test composer install --no-interaction
	@echo "✅ Test container ready!"

# Stop test container
test-down:
	docker-compose -f docker-compose.test.yml down

# Open shell in test container
test-shell:
	docker-compose -f docker-compose.test.yml exec test sh

# Check code style
cs-check:
	docker-compose exec php composer cs-check

# Fix code style
cs-fix:
	docker-compose exec php composer cs-fix

# Run all QA
qa:
	docker-compose exec php composer qa

# Clean vendor and cache
clean:
	rm -rf vendor
	rm -rf .phpunit.cache
	rm -rf coverage
	rm -f coverage.xml
	rm -f .php-cs-fixer.cache

# Setup git hooks for pre-commit checks
setup-hooks:
	chmod +x .githooks/pre-commit
	git config core.hooksPath .githooks
	@echo "✅ Git hooks installed! CS-check and tests will run before each commit."

# Build assets (TypeScript and SCSS)
build-assets:
	@echo "Building assets..."
	npm install
	npm run build
	@echo "✅ Assets built!"

# Build assets in development mode
build-assets-dev:
	@echo "Building assets in development mode..."
	npm install
	npm run build:dev
	@echo "✅ Assets built!"

# Watch assets for changes
watch-assets:
	@echo "Watching assets for changes..."
	npm install
	npm run watch

# Clean built assets
clean-assets:
	@echo "Cleaning built assets..."
	npm run clean
	@echo "✅ Assets cleaned!"

