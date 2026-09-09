PHP ?= php
FILTER ?=

.DEFAULT_GOAL := help

.PHONY: help test lint check

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-8s\033[0m %s\n", $$1, $$2}'

test: ## Run the tests (make test FILTER=facepile for one file)
	@$(PHP) tests/run.php $(FILTER)

lint: ## Check every PHP file for syntax errors
	@find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 $(PHP) -l | grep -v '^No syntax errors' || echo "  no syntax errors"

check: lint test ## Lint, then test
