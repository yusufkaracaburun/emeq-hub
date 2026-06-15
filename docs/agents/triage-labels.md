# Triage labels

Label-taxonomie aangemaakt op de GitHub-repo door `setup-gh-workflow.sh`
(ai-kit GH-hygiene). Exact de labels die nu live staan:

## Prioriteit

| Label | Betekenis |
| ----- | --------- |
| `P0-critical` | Blocker — productie kapot of security-incident |
| `P1-high` | Hoog — deze cyclus oppakken |
| `P2-medium` | Normaal — gepland |
| `P3-low` | Laag — nice-to-have / backlog |

## Status

| Label | Betekenis |
| ----- | --------- |
| `status:in-progress` | Actief in behandeling |

## Categorie

| Label | Betekenis |
| ----- | --------- |
| `epic/core` | Kern-werkstroom (placeholder — hernoem naar echte epic) |
| `epic/ux` | UX-werkstroom (placeholder) |
| `area/backend` | Hub-domeinlaag, SDK-calls, OAuth, webhooks |
| `area/frontend` | Filament admin-paneel |
| `area/infra` | Docker, Horizon, deploy, config |
| `area/docs` | `.docs/`, `docs/agents/`, CLAUDE.md |

## Mapping

Bestaande issues krijgen één `P*`, optioneel een `area/*`, en `status:in-progress`
zodra opgepakt. `epic/*` alleen voor issues die een meerdaagse werkstroom bundelen;
hernoem de placeholder-epics zodra er echte werkstromen zijn.
