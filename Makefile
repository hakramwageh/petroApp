run:
	docker compose up --build -d

test:
	docker compose run --rm app php artisan test

stop:
	docker compose down -v
