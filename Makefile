PHP      ?= php
COMPOSER ?= composer

.DEFAULT_GOAL := help

.PHONY: help install test phar clean

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies (including dev)
	$(COMPOSER) install

test: ## Run the PHPUnit test suite
	$(PHP) vendor/bin/phpunit

phar: ## Build a self-contained sdb.phar (production deps only)
	$(COMPOSER) install --no-dev --optimize-autoloader --quiet
	$(PHP) -d phar.readonly=0 build/build-phar.php
	$(COMPOSER) install --quiet
	@echo "Done. Try: $(PHP) sdb.phar --version"

clean: ## Remove the built phar
	rm -f sdb.phar
