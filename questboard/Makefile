.PHONY: setup up down logs test lint analyse fresh rebuild-leaderboard

setup: ## Create .env with a fresh APP_KEY
	@test -f .env || (cp .env.example .env && sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak && echo ".env created")

up: setup
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app horizon scheduler

fresh:
	docker compose exec app php artisan migrate:fresh --seed --force

rebuild-leaderboard:
	docker compose exec app php artisan leaderboard:rebuild

test:
	php artisan test

lint:
	vendor/bin/pint --test

analyse:
	vendor/bin/phpstan analyse --memory-limit=1G
