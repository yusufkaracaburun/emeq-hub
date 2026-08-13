#!/usr/bin/env bash
# Exporteert de OpenAPI-spec van de /v1-API naar api.json in de repo-root.
#
#   bin/export-openapi.sh          # of: composer openapi:export
#
# api.json staat in git zodat elke contractwijziging een reviewbare diff is en
# consumers een stabiel artefact hebben om tegen te implementeren. CI draait dit
# script en faalt op `git diff --exit-code -- api.json`.
#
# Daarom moet de output deterministisch zijn, los van de omgeving waarin het
# draait. Drie dingen zouden hem anders laten variëren:
#
#   - `servers` — was afgeleid van APP_URL; staat nu vastgepind in
#     config/scramble.php.
#   - `API_VERSION` — wordt hier hard gezet. Bewust géén `${API_VERSION:-...}`:
#     een override zou de drift-check waardeloos maken.
#   - De database — Scramble leest de kolomtypes uit het live schema om
#     model-attributen te typeren. Tegen een leeg schema wordt élk attribuut
#     `string` in plaats van `integer` of `["string","null"]`, dus het schema
#     bepaalt de output en moet overal gelijk zijn. Zonder pin draait de export
#     tegen de lokale .env: op een dev-machine een pgsql met wíllekeurig welk
#     schema (ook een niet-gemigreerde), in CI helemaal niets. Daarom draait de
#     export tegen een wegwerp-sqlite die hier vers gemigreerd wordt.
#
# De cache staat op `array` omdat de spatie/permission-migratie `Cache::forget()`
# aanroept; met de default zou de export een draaiende Redis nodig hebben.
#
# Bump de versie hieronder bewust, samen met de API-release.

set -euo pipefail

cd "$(dirname "$0")/.."

DB_FILE="$(mktemp -t emeq-openapi.XXXXXX)"
trap 'rm -f "$DB_FILE"' EXIT

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_FILE"
export CACHE_STORE=array

php artisan migrate --force --no-interaction

API_VERSION=0.2.0-dev php artisan scramble:export
