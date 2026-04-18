#!/bin/sh
set -eu

db_host="${DB_HOST:-db}"
db_port="${DB_PORT:-5432}"

echo "Waiting for database at ${db_host}:${db_port}..."

until php -r '
$host = getenv("DB_HOST") ?: "db";
$port = (int) (getenv("DB_PORT") ?: 5432);
$connection = @fsockopen($host, $port, $errno, $errstr, 1);
if ($connection === false) {
    exit(1);
}
fclose($connection);
' >/dev/null 2>&1; do
    sleep 1
done

echo "Database is up. Running migrations..."
php artisan migrate --force

if [ "$#" -gt 0 ]; then
    echo "Running command: $*"
    exec "$@"
fi

echo "Starting Laravel development server..."
exec php artisan serve --host=0.0.0.0 --port=8000
