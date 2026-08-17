#!/bin/bash

if [ ! -f .env ]; then
    echo "❌ .env not found. Run ./init.sh first."
    exit 1
fi

set -a
source .env
set +a

echo "📦 Running docker..."
docker compose up -d

# Administrative credentials. Everything in this script that creates a schema or
# grants a right uses these; the application itself never does either, which is
# the point of DB_USER being a separate, narrower account.
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-$DB_PASSWORD}"
mysql_root() { docker compose exec -T db mysql -uroot -p"${DB_ROOT_PASSWORD}" "$@"; }

echo "⏳ Waiting for MySQL to be ready..."
until docker compose exec -T db mysqladmin ping -uroot -p"${DB_ROOT_PASSWORD}" --silent 2>/dev/null; do
    echo "MySQL is not ready yet, waiting..."
    sleep 2
done
echo "✅ MySQL is ready!"

echo "📦 Installing composer dependencies..."
docker compose exec web composer install

echo "🔐 Setting permissions..."
docker compose exec web chmod -R 777 runtime web/assets web/uploads

echo "🗄️  Setting up databases..."

echo "Creating main database: ${DB_NAME}..."
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"

echo "Creating test database: ${TEST_DB_NAME}..."
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB_NAME}\`;"

# The application account. It gets data and schema rights on its own two
# databases and nothing anywhere else — no CREATE USER, no GRANT, no access to
# `mysql` or to another tenant's schema. Migrations run as this user, so DDL is
# included; everything above that stays with root.
if [ "${DB_USER}" != "root" ]; then
    echo "Creating least-privilege application user: ${DB_USER}..."
    mysql_root -e "
        CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
        ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
        GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
            ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
        GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
            ON \`${TEST_DB_NAME}\`.* TO '${DB_USER}'@'%';
        FLUSH PRIVILEGES;"
fi

echo "🔄 Running migrations for main database..."
docker compose exec web php yii migrate/up --interactive=0

echo "🔄 Running migrations for test database..."
docker compose exec web php yii migrate-test/up --interactive=0

echo "✅ Databases ready!"

# The RBAC permission catalog and the three system roles (moderator/admin/
# super_admin) are seeded by the migrations above. A fresh account has no role
# (a base user); appoint the first super admin once you have registered one:
echo "👑 To appoint the first super admin, register a user and then run:"
echo "     make rbac-assign role=super_admin email=<their-email>"