#!/usr/bin/env sh
# Throws away both sites and the database, then builds everything again.
#   ./reset.sh
set -e
cd "$(dirname "$0")"

docker compose --profile cli --profile tools down -v --remove-orphans
docker compose up -d
./setup.sh
