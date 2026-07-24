.PHONY: help install dev test clean seed db-fresh build publish serve watch stop

# Colors for output
CYAN := \033[36m
GREEN := \033[32m
YELLOW := \033[33m
RESET := \033[0m

help: ## Show this help message
	@echo "$(CYAN)Waterline Development Commands$(RESET)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-15s$(RESET) %s\n", $$1, $$2}'
	@echo ""

install: ## Install PHP and Node dependencies
	@echo "$(CYAN)Installing Composer dependencies...$(RESET)"
	composer install
	@echo "$(CYAN)Installing NPM dependencies...$(RESET)"
	npm ci
	@echo "$(GREEN)✓ Dependencies installed$(RESET)"

build: ## Build production assets
	@echo "$(CYAN)Building production assets...$(RESET)"
	npm run production
	@echo "$(GREEN)✓ Assets built$(RESET)"

watch: ## Watch and rebuild assets on change
	@echo "$(CYAN)Watching assets for changes...$(RESET)"
	npm run watch

publish: ## Publish assets to testbench public directory
	@echo "$(CYAN)Publishing assets to testbench...$(RESET)"
	./vendor/bin/testbench waterline:publish
	@echo "$(GREEN)✓ Assets published$(RESET)"

db-fresh: ## Create fresh SQLite database with migrations
	@echo "$(CYAN)Creating SQLite database...$(RESET)"
	./vendor/bin/testbench workbench:create-sqlite-db --force
	@echo "$(CYAN)Running migrations...$(RESET)"
	./vendor/bin/testbench migrate:fresh --database=sqlite
	@echo "$(GREEN)✓ Database created and migrated$(RESET)"

seed: db-fresh ## Create database and seed with test data
	@echo "$(CYAN)Seeding workbench with fixture data...$(RESET)"
	./vendor/bin/testbench db:seed --database=sqlite
	@echo "$(GREEN)✓ Workbench seeded with workflow data$(RESET)"
	@echo "$(GREEN)  - Completed workflows$(RESET)"
	@echo "$(GREEN)  - Running workflows$(RESET)"
	@echo "$(GREEN)  - Failed workflows$(RESET)"
	@echo "$(GREEN)  - Workflows with timers and children$(RESET)"
	@echo "$(GREEN)  - Worker registrations$(RESET)"
	@echo "$(GREEN)  - Workflow schedules$(RESET)"

serve: ## Start the workbench server (blocking)
	@echo "$(CYAN)Starting Waterline workbench...$(RESET)"
	@echo "$(GREEN)➜ http://localhost:18280/waterline$(RESET)"
	@echo ""
	./vendor/bin/testbench serve

dev: ## Start development environment (asset watch + server)
	@echo "$(CYAN)════════════════════════════════════════════════════════════$(RESET)"
	@echo "$(CYAN)  Waterline Development Environment$(RESET)"
	@echo "$(CYAN)════════════════════════════════════════════════════════════$(RESET)"
	@echo ""
	@if [ ! -d "vendor" ] || [ ! -d "node_modules" ]; then \
		echo "$(YELLOW)⚠ Dependencies not installed$(RESET)"; \
		echo "$(YELLOW)  Run: make install$(RESET)"; \
		exit 1; \
	fi
	@if [ ! -f "workbench/database/workbench.sqlite" ]; then \
		echo "$(CYAN)Setting up database and seed data...$(RESET)"; \
		$(MAKE) seed; \
		echo ""; \
	fi
	@if [ ! -f "workbench/public/vendor/waterline/app.js" ]; then \
		echo "$(CYAN)Building assets (first time)...$(RESET)"; \
		npm run dev; \
		$(MAKE) publish; \
		echo ""; \
	fi
	@echo "$(CYAN)Starting development servers...$(RESET)"
	@echo "$(GREEN)➜ Dashboard: http://localhost:18280/waterline$(RESET)"
	@echo "$(YELLOW)  Press Ctrl+C to stop$(RESET)"
	@echo ""
	@trap 'echo "\n$(YELLOW)Stopping servers...$(RESET)"; pkill -P $$$$; exit 0' INT TERM; \
	npm run watch & \
	WATCH_PID=$$!; \
	sleep 3; \
	./vendor/bin/testbench serve; \
	kill $$WATCH_PID 2>/dev/null || true

test: ## Run PHPUnit test suite
	@echo "$(CYAN)Running PHPUnit tests...$(RESET)"
	./vendor/bin/phpunit

test-sqlite: ## Run tests with SQLite
	@echo "$(CYAN)Running tests (SQLite)...$(RESET)"
	./vendor/bin/phpunit --configuration phpunit-sqlite.xml

test-mysql: ## Run tests with MySQL
	@echo "$(CYAN)Running tests (MySQL)...$(RESET)"
	./vendor/bin/phpunit --configuration phpunit-mysql.xml

test-pgsql: ## Run tests with PostgreSQL
	@echo "$(CYAN)Running tests (PostgreSQL)...$(RESET)"
	./vendor/bin/testbench --configuration phpunit-pgsql.xml

test-mssql: ## Run tests with SQL Server
	@echo "$(CYAN)Running tests (SQL Server)...$(RESET)"
	./vendor/bin/phpunit --configuration phpunit-mssql.xml

clean: ## Clean build artifacts and caches
	@echo "$(CYAN)Cleaning build artifacts...$(RESET)"
	rm -rf workbench/public/vendor/waterline
	rm -rf workbench/database/workbench.sqlite
	rm -rf node_modules/.cache
	rm -rf public/hot
	@echo "$(GREEN)✓ Cleaned$(RESET)"

stop: ## Stop any running background processes
	@echo "$(CYAN)Stopping background processes...$(RESET)"
	@pkill -f "npm run watch" || true
	@pkill -f "testbench serve" || true
	@echo "$(GREEN)✓ Stopped$(RESET)"
