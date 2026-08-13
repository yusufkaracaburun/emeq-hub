#!/usr/bin/env bash
# Exporteert de OpenAPI-spec van de /v1-API naar api.json in de repo-root.
#
#   bin/export-openapi.sh          # of: composer openapi:export
#
# api.json staat in git zodat elke contractwijziging een reviewbare diff is en
# consumers een stabiel artefact hebben om tegen te implementeren. CI draait dit
# script en faalt op `git diff --exit-code -- api.json`.
#
# Daarom moet de output deterministisch zijn, los van de lokale omgeving:
#   - `servers` is vastgepind in config/scramble.php (niet afgeleid van APP_URL).
#   - API_VERSION wordt hier hard gezet; zonder dit bepaalt de .env (of de shell)
#     van de ontwikkelaar het versienummer in de spec. Bewust géén
#     `${API_VERSION:-...}`: een override zou de drift-check waardeloos maken.
#
# Bump de versie hieronder bewust, samen met de API-release.

set -euo pipefail

cd "$(dirname "$0")/.."

API_VERSION=0.2.0-dev php artisan scramble:export
