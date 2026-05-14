## Packages-conventie

**`packages/` is gitignored** en is een **lees-clone** voor referentie/grep. SDK-packages hebben elk een eigen GitHub-repo:

- `packages/snelstart-api/` ← `github.com:yusufkaracaburun/emeq-snelstart-api`

Composer require't de SDKs via een **VCS repository** in `composer.json` — niet meer via een path-symlink. Reden: `packages/` bestaat niet op Laravel Cloud, dus een path-dist in `composer.lock` breekt de deploy.

**Workflow voor SDK-changes:**

1. Edit in de SDK-repo (eigen clone, kan `packages/<name>/` zijn).
2. Commit + push naar de SDK GitHub-repo.
3. In de Hub: `composer update emeq/<name>` — pinst de nieuwe VCS-reference in `composer.lock`.
4. Commit `composer.lock` in de Hub.

Geen live-edit-symlink meer. Voor snelle iteratie in de SDK: werk daar gewoon zelf met `./vendor/bin/pest` in de SDK-repo, en sync pas naar de Hub als de change stabiel is.
