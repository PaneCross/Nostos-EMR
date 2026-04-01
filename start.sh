#!/usr/bin/env bash
# NostosEMR startup script
# Run from ~/projects/nostosemr in your WSL2 Ubuntu terminal:
#   chmod +x start.sh && ./start.sh

set -e

echo "Starting NostosEMR services..."
docker compose up -d

echo "Waiting for laravel.test to be healthy..."
until docker compose exec -T laravel.test php -r "echo 'ok';" 2>/dev/null | grep -q ok; do
    sleep 2
done

# Fix permissions: parallel test workers run as root and leave root-owned files
# in storage/ and bootstrap/cache/. Without this, the web server (www-data)
# cannot write to these directories and every page returns HTTP 500.
# This must run BEFORE artisan optimize (which writes to bootstrap/cache/).
echo "Fixing storage permissions..."
docker compose exec -T laravel.test chmod -R 777 storage bootstrap/cache

echo "Clearing stale caches..."
docker compose exec -T laravel.test php artisan view:clear
docker compose exec -T laravel.test php artisan config:clear

echo "Running artisan optimize..."
docker compose exec -T laravel.test php artisan optimize

echo ""
echo "NostosEMR is ready at http://localhost"
echo "Mailpit (OTP emails) at  http://localhost:8025"
echo ""
echo "To stop:  docker compose down"
echo "To run tests:  docker compose exec -T laravel.test php artisan config:clear && docker compose exec -T laravel.test php artisan test --parallel --processes=4"
echo "After tests, run ./start.sh again to restore permissions."
