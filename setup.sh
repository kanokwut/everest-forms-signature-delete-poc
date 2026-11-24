#!/usr/bin/env bash
# Stand up a clean WordPress with Everest Forms, build the target form the way
# the plugin's own AI / MCP builder does, and plant a victim file.
#
# Usage: ./setup.sh [plugin-version]   default 3.6.4 (vulnerable), 3.6.5 is fixed
set -euo pipefail

cd "$(dirname "$0")"
# shellcheck disable=SC1091
source .env

EVF_VERSION="${1:-3.6.4}"

say() { printf '\n== %s\n' "$1"; }

say "starting containers"
docker compose up -d

say "waiting for WordPress"
for _ in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${POC_PORT}/" || true)
  [ "$code" != "000" ] && break
  sleep 3
done
[ "${code:-000}" = "000" ] && { echo "WordPress did not come up on port ${POC_PORT}"; exit 1; }

say "installing WordPress"
docker compose exec -T cli wp core install \
  --url="http://127.0.0.1:${POC_PORT}" --title="PoC" \
  --admin_user="${WP_ADMIN}" --admin_password="${WP_ADMIN_PASS}" \
  --admin_email=poc@example.test --skip-email >/dev/null
docker compose exec -T cli wp core version

say "installing Everest Forms ${EVF_VERSION}"
docker compose exec -T cli wp plugin install everest-forms --version="${EVF_VERSION}" --activate >/dev/null 2>&1
docker compose exec -T cli wp plugin get everest-forms --field=version

say "building the form through the plugin's own ability (no manual meta-key editing)"
docker cp setup/build-form.php "$(docker compose ps -q wp)":/var/www/html/build-form.php >/dev/null
docker compose exec -T cli wp eval-file /var/www/html/build-form.php | tee evidence/setup.out

say "planting a victim file under uploads"
docker compose exec -T wp sh -c '
  mkdir -p /var/www/html/wp-content/uploads/2026/11
  printf "VICTIM-FILE" > /var/www/html/wp-content/uploads/2026/11/victim.png
  chown -R www-data:www-data /var/www/html/wp-content/uploads/2026
  ls -l /var/www/html/wp-content/uploads/2026/11/victim.png'

PAGE_ID=$(grep -oE 'PAGE_ID=[0-9]+' evidence/setup.out | cut -d= -f2)
printf '\nReady. Target page: http://127.0.0.1:%s/?page_id=%s\nNow run: ./exploit.sh\n' "${POC_PORT}" "${PAGE_ID}"
