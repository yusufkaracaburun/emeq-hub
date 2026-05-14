## Packages-conventie

**`packages/` is gitignored.** Lokale dev-workspace voor SDK-packages die elk een eigen GitHub-repo hebben:

- `packages/snelstart-api/` ← `github.com:yusufkaracaburun/emeq-snelstart-api`

Composer require's de SDKs via **path repository** in `composer.json` (`symlink: true`). Live code-edits in `packages/<name>/src/` zijn direct actief — geen composer-update nodig. CI/prod hint: `composer require` zou hetzelfde pad via VCS-fallback kunnen ondersteunen, maar voor nu zijn we lokaal-only.

**SDKs ontwikkel je in hun eigen repo's** (clonen in `packages/`), commit en push je naar hun repo, en je laat de Hub gewoon naar het symlink-pad wijzen.
