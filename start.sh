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

echo "Running artisan optimize..."
docker compose exec -T laravel.test php artisan optimize

echo ""
echo "NostosEMR is ready at http://localhost"
echo "Mailpit (OTP emails) at  http://localhost:8025"
echo ""
echo "To stop:  docker compose down"
echo "To clear cache after route/config changes:  docker compose exec laravel.test php artisan optimize:clear"
