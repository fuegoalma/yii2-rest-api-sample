#!/bin/bash

if [ ! -f .env ]; then
    echo "❌ .env not found. Run ./init.sh first."
    exit 1
fi

set -a
source .env
set +a

echo "📦 Running docker..."
docker-compose up -d

echo "⏳ Waiting for MySQL to be ready..."
until docker-compose exec db mysqladmin ping -u${DB_USER} -p"${DB_PASSWORD}" --silent 2>/dev/null; do
    echo "MySQL is not ready yet, waiting..."
    sleep 2
done
echo "✅ MySQL is ready!"

echo "📦 Installing composer dependencies..."
docker-compose exec web composer install

echo "🔐 Setting permissions..."
docker-compose exec web chmod -R 777 runtime web/assets

echo "🗄️  Setting up databases..."

echo "Creating main database: ${DB_NAME}..."
docker-compose exec db mysql -u${DB_USER} -p"${DB_PASSWORD}" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"

echo "Creating test database: ${TEST_DB_NAME}..."
docker-compose exec db mysql -u${DB_USER} -p"${DB_PASSWORD}" -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB_NAME}\`;"

echo "🔄 Running migrations for main database..."
docker-compose exec web php yii migrate/up --interactive=0

echo "🔄 Running migrations for test database..."
docker-compose exec web php yii migrate-test/up --interactive=0

echo "✅ Databases ready!"