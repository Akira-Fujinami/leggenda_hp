.PHONY: setup up down restart logs migrate seed test \
	shell-backend shell-frontend shell-analyzer queue-restart build

# 初回セットアップ。既存の.envは上書きしない。
setup:
	@test -f .env || cp .env.example .env
	@test -f backend/.env || cp backend/.env.example backend/.env
	docker compose build
	docker compose run --rm backend composer install
	docker compose run --rm frontend npm install
	docker compose run --rm analyzer npm install
	docker compose run --rm backend php artisan key:generate
	docker compose up -d postgres redis
	docker compose run --rm backend php artisan migrate --seed
	docker compose run --rm backend php artisan storage:link
	docker compose up -d

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose down
	docker compose up -d

logs:
	docker compose logs -f

migrate:
	docker compose exec backend php artisan migrate

seed:
	docker compose exec backend php artisan db:seed

test:
	docker compose exec backend php artisan test
	docker compose exec analyzer npm test

shell-backend:
	docker compose exec backend sh

shell-frontend:
	docker compose exec frontend sh

shell-analyzer:
	docker compose exec analyzer sh

queue-restart:
	docker compose exec backend php artisan queue:restart
