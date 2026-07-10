#!/bin/sh
# Entrypoint for the `cron` compose service: prepare the environment cron jobs
# need, install the schedule, then run the daemon in the foreground.
set -e

# cron executes jobs with a near-empty environment, so snapshot the container's
# runtime config (DB creds, JWT secret, …) into a file each job sources before
# running. Values are single-quoted so spaces/specials survive.
printenv \
  | grep -E '^(DB_|TEST_DB_|JWT_|COOKIE_|LOGIN_RATE_|BASE_URL|DEFAULT_PASSWORD|YII_)' \
  | sed -E "s/^([^=]+)=(.*)$/export \1='\2'/" > /etc/container-env

# install the versioned schedule from the repo
cp /var/www/html/docker/cron/crontab /etc/cron.d/app-cron
chmod 0644 /etc/cron.d/app-cron

echo "[cron] environment prepared; scheduled jobs:"
grep -vE '^[[:space:]]*(#|$)' /etc/cron.d/app-cron

# foreground so the container stays alive and job output streams to docker logs
exec cron -f
