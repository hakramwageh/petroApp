.PHONY: docker-run docker-test docker-stop local-run

docker-run:
	docker compose up --build -d

docker-test:
	docker compose run --rm app php artisan test

docker-stop:
	docker compose down -v



local-run:
	composer install
	php artisan migrate
	php artisan serve


local-test:
	php artisan test