DEMOS := symfony8

.PHONY: help up down test test-coverage release-check up-symfony8 down-symfony8 test-symfony8 test-coverage-symfony8

help:
	@echo "Audit Kit Bundle Demo (Symfony 8)"
	@echo "make up-symfony8 | down-symfony8 | test-symfony8"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

up:
	@$(MAKE) -C symfony8 up

down:
	@$(MAKE) -C symfony8 down

test:
	@$(MAKE) -C symfony8 test

test-coverage:
	@$(MAKE) -C symfony8 test-coverage

release-check:
	@$(MAKE) -C symfony8 release-check

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
