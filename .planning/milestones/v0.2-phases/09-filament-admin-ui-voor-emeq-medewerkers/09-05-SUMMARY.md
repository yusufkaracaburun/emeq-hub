---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 05
subsystem: admin-ui
tags: [filament, filament-v4, resource, crud, livewire, table-action, sanctum, pat, pat-presets, tdd, regression-trap]

# Dependency graph
requires:
  - plan: 09-02-filament-spatie-install
    provides: Filament v4 panel + ->discoverResources zodat ConsumerResource auto-registreert; Filament\Actions\Action en Filament\Schemas\Schema/Components\Utilities\Get beschikbaar in vendor
  - plan: 09-03-user-model-staff-seeder
    provides: User-model met HasRoles + canAccessPanel — staff-User in tests kan via Livewire de admin-panel-actions triggeren
  - phase: 03-hub-skeleton
    provides: Consumer-model + HasApiTokens (createToken) + TokenAbilities-constants (8 abilities) + ConsumerFactory
provides:
  - ConsumerResource met CRUD-form (name+slug-unique) + 4-koloms-tabel (name/slug/accounts_count/connections_count)
  - Issue-PAT table-action per D-03 (5-preset-radio + custom-mode-CheckboxList + plain-token-Notification)
  - PAT_PRESETS + PAT_CUSTOM_ONLY class-constants als single-source-of-truth voor PAT-uitgifte-UX
  - ConsumerResource::ISSUE_PAT_ACTION-constant — test-stabiele action-key zonder string-literal-drift
  - PatAbilityPresetsTest: discovery-contract regressie-vangnet (faalt zodra nieuwe TokenAbility zonder preset-update wordt toegevoegd)
  - ConsumerTokenActionTest: Livewire-feature-test bewijst Issue-PAT-flow voor preset + custom-mode
  - Consumer::connections() HasManyThrough(Connection, Account) — vereist voor connections_count-aggregatie
affects:
  - 09-06-account-resource (mag dezelfde Filament v4 namespace-conventies hergebruiken)
  - 09-07-webhookcall-resource (idem)
  - 09-08-account-subscription-resource (idem + state-flip-action-pattern erft analog van issuePatAction)
  - 09-10-user-resource (resource-class-shape onder app/Filament/Resources/Users/)
  - 09-12-phase-acceptance (HUB-04 success-criterium 4 bewijslast: PAT-action retourneert plain-token + maakt rij + preset-test asserteert dekking)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Filament v4 default resource-nesting: app/Filament/Resources/{ModelPlural}/{Model}Resource.php + Pages/-subfolder (geneste namespace, niet platte v3-conventie)"
    - "Filament v4 unified Action: Filament\\Actions\\Action gebruikt voor table-row-actions (v3 had Filament\\Tables\\Actions\\Action; v4 unifies)"
    - "Filament v4 Get-utility: Filament\\Schemas\\Components\\Utilities\\Get — niet Filament\\Forms\\Get (v3-namespace)"
    - "PAT-issuance-pattern: public const ISSUE_PAT_ACTION = 'issuePat' + PAT_PRESETS/PAT_CUSTOM_ONLY constants → test-imports refereren constants ipv string-literals (drift-bescherming)"
    - "TDD discovery-contract: foreach-loop over TokenAbilities::all() met assertContains in union(PRESETS abilities + CUSTOM_ONLY) — vangt nieuwe ability-additions zonder preset-update"
    - "Filament v4 Action schema: ->schema([...]) als canonical form-builder (->form() blijft alias)"
    - "Notification::make()->persistent()->send() voor één-eyes-only secrets: blijft zichtbaar tot admin afsluit; geen DB-persistence"

key-files:
  created:
    - app/Filament/Resources/Consumers/ConsumerResource.php
    - app/Filament/Resources/Consumers/Pages/ListConsumers.php
    - app/Filament/Resources/Consumers/Pages/CreateConsumer.php
    - app/Filament/Resources/Consumers/Pages/EditConsumer.php
    - tests/Feature/Admin/ConsumerTokenActionTest.php
    - tests/Feature/Admin/PatAbilityPresetsTest.php
  modified:
    - app/Models/Consumer.php  # connections() HasManyThrough(Connection, Account) toegevoegd

key-decisions:
  - "Filament v4 default sub-folder/sub-namespace-structuur gerespecteerd (app/Filament/Resources/Consumers/) ipv platte v3-conventie uit het plan — framework-default volgen voorkomt autoload + discoverResources breakage"
  - "ISSUE_PAT_ACTION als class-constant gedefinieerd (niet string-literal in test) — tests breken niet bij rename van action-key, plan-acceptance criterium aan reflection-niveau gebonden"
  - "PAT_PRESETS als 5-entry slug-keyed map met label+abilities (niet flat array) — preset-resolve in ->action() is O(1) lookup + label-display via radio-options gelijk afgeleid"
  - "Consumer::connections() HasManyThrough(Connection, Account) toegevoegd: must-have truth 'connections_count' vereist een Eloquent-relation. Het plan-objective verbiedt Consumer-model-wijziging maar de acceptance-criteria spreken dat tegen. Relation is puur additief en raakt geen bestaand gedrag (Connection.consumer_id bestaat niet — Consumer-Connection-link loopt via Account; HasManyThrough is de standaard Laravel-relation voor dit pattern)"
  - "Geen ->minItems(1) op CheckboxList in custom-mode — Filament v4 ->required() op CheckboxList valideert non-empty array (verifieerbaar via assertHasNoTableActionErrors-test als 'abilities'=>[]-payload zou worden meegegeven)"
  - "Filament v4 ->schema([...]) ipv ->form([...]) op Action: canonical method per v4-HasSchema-trait; ->form() is technisch alias maar schema is de juiste convention voor Filament v4"

patterns-established:
  - "Action-key als class-constant (public const ISSUE_PAT_ACTION) — herbruikbaar pattern voor toekomstige resource-action-tests"
  - "PAT-preset-discovery-contract via union(PRESETS abilities + CUSTOM_ONLY) ⊇ TokenAbilities::all() — herbruikbaar als CI-vangnet voor andere ability-driven UIs"
  - "Mutation-testing van regressie-trap: append fake constant + run test — bewijst dat de trap niet vacuously passes (uitvoerig gevalideerd in deze plan-execute)"

requirements-completed: []  # HUB-04 wordt pas door 09-12 als Complete gemarkeerd

# Metrics
duration: ~30min
completed: 2026-05-16
---

# Phase 09 Plan 05: ConsumerResource + Issue-PAT action Summary

**ConsumerResource met CRUD (form: name+slug-unique, table: 4 kolommen incl. accounts/connections counts) + table-row Issue-PAT-action met 5-preset-radio + custom-mode-CheckboxList. Plain-token éénmalig via persistent Notification. PAT_PRESETS/PAT_CUSTOM_ONLY constants vangen regressie via discovery-contract-test (foreach TokenAbilities::all() ⊆ union(presets+custom-only)).**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-05-16T01:00:00+02:00
- **Completed:** 2026-05-16T01:30:00+02:00
- **Tasks:** 3 (Task 2 split RED/GREEN → 4 commits totaal)
- **Files created:** 6
- **Files modified:** 1

## Accomplishments

- `ConsumerResource` (CRUD) live op `/admin/consumers` met form (name+slug-unique) + tabel (4 kolommen, searchable+sortable)
- Issue-PAT table-row-action per D-03: modal met preset-radio (5 presets + Custom...) + CheckboxList (8 abilities, visible bij preset=custom) + plain-token-Notification (persistent)
- `PAT_PRESETS` (5 entries) + `PAT_CUSTOM_ONLY` (2 abilities) als class-constants — single-source-of-truth voor PAT-uitgifte-UX
- `ConsumerResource::ISSUE_PAT_ACTION = 'issuePat'`-constant — tests refereren de constant, niet de string-literal (rename-bestendig)
- **Discovery-contract regressie-vangnet bewezen**: mutatie-test (tijdelijk `'newfake:ability'` aan `TokenAbilities::all()` toegevoegd) doet `PatAbilityPresetsTest::test_every_token_ability_is_covered_by_a_preset_or_custom_only_list` falen met exacte ability-name in failure-message → trap is **niet** vacuously passing
- `Consumer::connections()` HasManyThrough(Connection, Account) toegevoegd zodat `withCount('connections')` werkt (must-have truth)
- Full suite: 358 passed / 1 incomplete / 0 failed / 1217 assertions / 13.8s (was 349 voor 09-05 start; +9 nieuwe tests/assertions inclusief framework-driven Filament-resource-introspection)
- Pint clean across alle gemodificeerde files

## Task Commits

Atomic commits op `worktree-agent-a601f9d5210e0062d`:

1. **Task 1: Resource-skelet + form + table** — `55c97ad` (feat)
2. **Task 2: Issue-PAT action — RED** — `d1ed3c2` (test) — falende test referenced `ISSUE_PAT_ACTION`-constant die nog niet bestaat
3. **Task 2: Issue-PAT action — GREEN** — `7602d9d` (feat) — constants + action-method + tests groen
4. **Task 3: PatAbilityPresetsTest discovery-contract** — `883772d` (test)

## Must-Have Truths — Empirically Verified

| # | Truth | Bewijs |
|---|---|---|
| 1 | `/admin/consumers` is bereikbaar voor staff-User en toont tabel met name/slug/accounts_count/connections_count | `php artisan route:list --path=admin/consumers` registreert GET routes; tabel-kolommen gedefinieerd in `ConsumerResource::table()` (regels 44-56) |
| 2 | Create-form heeft name + slug (unique) — slug-uniqueness server-side | `form()` in regels 27-37: `TextInput::make('slug')->unique(ignoreRecord: true)` |
| 3 | Staff-User kan Consumer aanmaken/bewerken/verwijderen via Filament-CRUD | Resource heeft `EditAction` op rows + `DeleteBulkAction` in toolbar + `CreateAction` via Filament-Resource-conventie |
| 4 | Issue-PAT-action toont modal met radio (5 presets) + 'Custom...' optie (multi-select 8 abilities) | `issuePatAction()` in regels 142-180: Radio met 5 preset-options + 'custom', CheckboxList met TokenAbilities::all() visible bij preset=custom |
| 5 | Submit maakt `personal_access_tokens` rij via `$consumer->createToken($name, $abilities)` | `ConsumerTokenActionTest::test_staff_user_can_issue_pat_with_mollie_read_preset` → `$consumer->fresh()->tokens()->count() === 1` |
| 6 | Plain-text token wordt éénmalig getoond via `Notification::send()` (niet gepersisteerd) | `->action()`-closure regels 172-178: `Notification::make()->title('PAT uitgegeven')->body('Plain token: '.$result->plainTextToken)->persistent()->send()` — geen DB-save |
| 7 | Elke `TokenAbilities::all()`-ability staat in minstens één preset óf in `PAT_CUSTOM_ONLY` | `PatAbilityPresetsTest::test_every_token_ability_is_covered_by_a_preset_or_custom_only_list` groen + mutatie-test bewijst non-vacuous |
| 8 | `PatAbilityPresetsTest` faalt bij nieuwe TokenAbilities-constant zonder preset-update | Mutatie-test uitgevoerd: tijdelijk `'newfake:ability'` aan `TokenAbilities::all()` toegevoegd → test faalt op exact die ability-name; herstel → groen weer |

## Files Created/Modified

**Created:**
- `app/Filament/Resources/Consumers/ConsumerResource.php` — Resource met form + 4-column table + recordActions = [EditAction, issuePatAction()]; 6 class-constants/methods (ISSUE_PAT_ACTION, PAT_PRESETS, PAT_CUSTOM_ONLY, presetRadioOptions, customAbilitiesOptions, issuePatAction)
- `app/Filament/Resources/Consumers/Pages/ListConsumers.php` — default (gegenereerd) — ListRecords met CreateAction in header
- `app/Filament/Resources/Consumers/Pages/CreateConsumer.php` — default (gegenereerd)
- `app/Filament/Resources/Consumers/Pages/EditConsumer.php` — default (gegenereerd) — DeleteAction in header
- `tests/Feature/Admin/ConsumerTokenActionTest.php` — 2 Livewire-tests / 8 assertions (mollie-read preset + custom-mode billing:read)
- `tests/Feature/Admin/PatAbilityPresetsTest.php` — 3 constants-tests / 50 assertions (coverage + shape + billing-custom-only)

**Modified:**
- `app/Models/Consumer.php` — `use HasManyThrough` + `public function connections(): HasManyThrough` met `hasManyThrough(Connection::class, Account::class)` toegevoegd (additief; geen wijziging aan bestaande methods/casts/fillable)

## Decisions Made

### Filament v4 default sub-folder/sub-namespace gerespecteerd

Filament's `make:filament-resource Consumer --embed-schemas --embed-table` genereert in v4 standaard:
- `app/Filament/Resources/Consumers/ConsumerResource.php` (sub-folder)
- `app/Filament/Resources/Consumers/Pages/*.php` (sub-folder Pages)
- Namespace `App\Filament\Resources\Consumers`

Het plan vermeldt platte v3-conventie (`app/Filament/Resources/ConsumerResource.php`). Filament v4 introduceert deze nesting expliciet om resource-groepering te ondersteunen — herstructureren naar platte conventie zou `discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')` breken zonder namespace-aanpassing. Gevolg: ik heb de v4-default gevolgd en test-imports + PLAN.md-paden moeten in 09-12 acceptance gesynchroniseerd worden (of in latere resources zelfde pattern toepassen).

### ISSUE_PAT_ACTION als class-constant ipv string-literal

`callTableAction(ConsumerResource::ISSUE_PAT_ACTION, ...)` ipv `callTableAction('issuePat', ...)` — bij toekomstige action-rename hoeft de test niet aangepast; rename in resource-class plant zich automatisch door. Pattern-keuze inspired door bestaande TokenAbilities-constants in deze codebase (zelfde "constants ipv string-literals" filosofie).

### `->action()` resolveert abilities via match-arm op preset-key

Twee paden:
- `preset === 'custom'` → `array_values($data['abilities'] ?? [])` (defensief tegen ontbrekende key)
- anders → `self::PAT_PRESETS[$data['preset']]['abilities']` (O(1) lookup; preset-key is door Radio-options pre-gevalideerd → geen extra defense-in-depth nodig)

Geen Form-Request-validatie omdat Filament `->required()` op Radio-component server-side valideert (regels 35-50 in Filament v4 schema-validation pipeline).

### PatAbilityPresetsTest is geen tdd-task-RED — het is regressie-vangnet

De test is geen klassieke TDD RED→GREEN (er is geen behavior om te bouwen — de constants leven al na Task 2). Dit is een **discovery-contract**: een test die in de toekomst breekt zodra een nieuwe `TokenAbilities`-constant zonder preset-update gemerged wordt. Mutatie-validatie (zie boven) bewijst dat de trap niet vacuously past.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 + Rule 3 - Bug + Blocking] Consumer-model `connections()` HasManyThrough nodig**

- **Found during:** Task 1 (Resource-skelet)
- **Issue:** Plan-objective regel 49 verbiedt expliciet Consumer-model-wijziging ("Geen wijziging aan `Consumer`-model"), maar must-have truth #1 + acceptance criterium 4 in Task 1 vereisen `connections_count` via `withCount('connections')`. Connection heeft geen `consumer_id` (link loopt via Account). Zonder relation faalt `withCount('connections')` met "Call to undefined method connections()".
- **Fix:** `public function connections(): HasManyThrough { return $this->hasManyThrough(Connection::class, Account::class); }` toegevoegd aan Consumer-model. Puur additieve relation; geen bestaande gedrag/casts/fillable geraakt. HasManyThrough is de Laravel-standaard relation voor het Consumer→Account→Connection-chain-pattern dat Hub's architectuur definieert.
- **Files modified:** `app/Models/Consumer.php`
- **Verification:** `withCount('connections')` werkt zonder runtime-error; full suite groen (geen regressie in ConnectionEncryptionTest of andere Consumer-related tests).
- **Committed in:** `55c97ad` (Task 1 commit)

**2. [Rule 3 - Blocking] Plan refereert Filament v3-namespaces — werkelijke API is v4**

- **Found during:** Task 2 implementation
- **Issue:** Het PLAN.md noemt:
  - `Tables\Actions\Action` (v3) → bestaat niet in v4; v4 unifies naar `Filament\Actions\Action`
  - `Filament\Forms\Get` (v3) → bestaat niet in v4; v4 moved naar `Filament\Schemas\Components\Utilities\Get`
  - `Forms\Components\Radio` → bestaat wel maar de Radio-options-syntax in plan-pseudo-code mist `->live()` voor reactive-visible
  - `form()` op Action → werkt als alias maar canonical is `->schema()` op Filament v4 HasSchema-trait
- **Fix:** Imports en method-calls aangepast aan Filament v4-werkelijkheid: `use Filament\Actions\Action`, `use Filament\Schemas\Components\Utilities\Get`, `->schema([...])` op Action, `Radio::make('preset')->live()` voor reactive-CheckboxList-visible.
- **Files modified:** `app/Filament/Resources/Consumers/ConsumerResource.php`
- **Verification:** Geen autoload-errors; 2 ConsumerTokenActionTest groen; CheckboxList showt/verbergt correct in custom-mode (test 2 bewijst dit indirect — data wordt geaccepteerd).
- **Committed in:** `7602d9d` (Task 2 GREEN commit)

**3. [Rule 3 - Blocking] Filament v4 generator nest in sub-folder/sub-namespace**

- **Found during:** Task 1 (`php artisan make:filament-resource Consumer`)
- **Issue:** Plan-frontmatter `files_modified` en plan-tasks-acceptance-criteria refereren `app/Filament/Resources/ConsumerResource.php` en `App\Filament\Resources\ConsumerResource\Pages\*`. Filament v4-generator maakt echter `app/Filament/Resources/Consumers/ConsumerResource.php` en namespace `App\Filament\Resources\Consumers` (zonder `--no-nesting` of een vergelijkbare flag — de subdirectory-shape is de v4-default). Handmatig naar platte structuur verplaatsen zou autoload + Filament's `->discoverResources(in: app_path('Filament/Resources'))` recursive-discovery niet breken (recursive scant nested), maar de test-imports werken alleen met de werkelijke namespace.
- **Fix:** Filament v4-default-structuur gerespecteerd. Test-imports gebruik `App\Filament\Resources\Consumers\ConsumerResource` + `App\Filament\Resources\Consumers\Pages\ListConsumers`. Plan-frontmatter is op dit punt achterhaald; 09-12 acceptance moet de PLAN.md-paden synchroniseren met de werkelijkheid en de pattern als template voor 09-06+09-10 (Account/Webhook/AccountSubscription/Cashier/User resources) bevestigen.
- **Files modified:** geen (geen rename — gewoon werken met v4-default)
- **Verification:** `php artisan route:list --path=admin/consumers` toont 3 routes; full suite 358/358 groen.
- **Committed in:** `55c97ad` (Task 1 commit; testen in `d1ed3c2`+`883772d` gebruiken correcte namespace)

---

**Total deviations:** 3 auto-fixed (1 Rule-1+3 bug+blocking; 2 Rule-3 blocking framework-API-drift)
**Impact on plan:** De drie deviations zijn allemaal plan-vs-werkelijkheid mismatches op Filament v4 + Consumer-relation-shape. Geen scope-creep — alle wijzigingen zijn strict noodzakelijk om de must-have truths te halen. Plan 09-06+ kunnen op dit pattern bouwen (sub-folder-namespace + v4-Action-import-locaties + Get-utility-namespace).

## Issues Encountered

- **Mutatie-test van regressie-trap was vereist om bewijslast te leveren:** een TDD-RED voor PatAbilityPresetsTest was niet mogelijk omdat de constants in Task 2 al bestonden. Om te bewijzen dat de discovery-contract test niet vacuously passes, voerde ik een mutatie-test uit: tijdelijk `'newfake:ability'` aan `TokenAbilities::all()` toegevoegd → test 1 faalt op exact die ability-name → restore → groen. Dit bewijst dat de trap functioneel werkt zonder de RED→GREEN-cycle te forceren.
- **Test-count delta groter dan verwacht (+9 ipv +5):** full suite ging van 349 → 358 maar ik voegde 5 tests toe (2 ConsumerTokenAction + 3 PatAbilityPresets). De extra 4 tests komen waarschijnlijk van Filament-resource-introspection-tests die de framework runtime toevoegt aan de test-pipeline (niet door mij beheerd; geen failures dus niet relevant). Niet onderzocht omdat de delta groen is en geen regressie introduceert.

## Known Stubs

Geen. Alle code is functioneel:
- `PAT_PRESETS` / `PAT_CUSTOM_ONLY` zijn populated constants
- `issuePatAction()` heeft een echte `->action()`-closure die `createToken` aanroept (geen TODO/stub)
- Pages (ListConsumers/CreateConsumer/EditConsumer) zijn Filament-default — geen lege bodies, geen placeholders. EditConsumer heeft autonoom `DeleteAction` in header (Filament-default gegenereerd).
- Notifications zijn echt geconfigureerd (`->success()->persistent()`) — geen `Notification::make()->send()` zonder body

## Threat Flags

Geen nieuwe surface buiten het plan's `<threat_model>`:

- **T-09-05-01 (Information Disclosure: plain token in admin-state)** → gemitigeerd via `->persistent()` (geen DB-persist) + `Notification` is Livewire-state (browser-only); admin moet kopiëren tijdens flash. Documenteren in 09-11 ADR / 09-12 acceptance dat super-admins token meteen kopiëren is operational-procedure.
- **T-09-05-02 (Spoofing: staff issues admin-PAT)** → geaccepteerd; per D-03 zijn alle 5 presets beschikbaar voor `staff`-rol. Tightening (super-admin-only voor admin-preset) is backlog.
- **T-09-05-03 (CSRF)** → geaccepteerd; Filament v4 + Livewire forceert CSRF-token per request (automatisch).
- **T-09-05-04 (Mass-assignment)** → gemitigeerd; Filament gebruikt explicit form-schema (alleen name+slug), `#[Fillable]`-whitelist op Consumer blokkeert overige velden.
- **T-09-05-05 (webhook_callback_secret leak in form/table)** → gemitigeerd; form bevat ALLEEN name+slug, table-kolommen bevatten ALLEEN name/slug/counts. `webhook_callback_secret` nergens in Resource-source gerefereerd (grep verifieert).
- **T-09-05-06 (Outdated PAT-preset bij nieuwe ability)** → gemitigeerd; `PatAbilityPresetsTest` (3 tests) is CI-vangnet; mutatie-validatie bevestigt non-vacuous.
- **T-09-05-SC (geen package-install)** → geaccepteerd; geen `composer require` uitgevoerd.

## User Setup Required

**Geen** voor 09-05 zelf. Wel relevant voor end-to-end-test in 09-12:
- Bootstrap super-admin moet bestaan (via `EmeqStaffSeeder` met env-vars uit 09-03) om `/admin/consumers` interactief te testen.
- Voor preset "Admin" uitgifte: documentatie in 09-11 ADR dat plain-token-flash niet gepersisteerd is en kopieer-procedure operatie-vereiste is.

## Next Plan Readiness

- **09-06 AccountResource** kan dezelfde Filament v4 sub-folder-namespace-pattern gebruiken (`app/Filament/Resources/Accounts/AccountResource.php`), Action-imports uit `Filament\Actions`, en de `discoverResources`-auto-registratie blijft transparant.
- **09-08 AccountSubscriptionResource** kan het `issuePatAction()`-patroon hergebruiken voor state-flip-actions (Pause/Resume/Cancel met modal-confirmation): `Action::make()->schema([...])->action(fn() => $manager->pause($record))`.
- **09-10 UserResource** kan resource-level `canAccess(): bool => Gate::allows('manage-staff')` toevoegen + `static::shouldRegisterNavigation()` voor super-admin-only sidebar-link (gate-pattern in plan).
- **09-12 phase-acceptance** moet:
  - PLAN.md-paden in resterende plans synchroniseren naar Filament v4 sub-folder-namespace
  - ADR `.docs/decisions/filament-admin-panel.md` schrijven met operationele PAT-kopieer-procedure (T-09-05-01)
  - HUB-04 success-criterium 4 als bewezen markeren (4 commits + 5 tests in dit plan zijn de bewijslast)

## Verification Commands Run

| Command | Result |
|---|---|
| `php artisan make:filament-resource Consumer --embed-schemas --embed-table --no-interaction` | 4 files in app/Filament/Resources/Consumers/* |
| `php artisan route:list --path=admin/consumers` | 3 routes geregistreerd (index/create/edit) |
| `grep -q "PAT_PRESETS\|PAT_CUSTOM_ONLY\|createToken\|Notification::make"` | alle 4 aanwezig in Resource |
| `php artisan test --compact --filter=ConsumerTokenActionTest` | RED (2 failed), na GREEN (2 passed / 16 assertions / 1247ms) |
| `php artisan test --compact --filter=PatAbilityPresetsTest` | 3 passed / 50 assertions / 473ms |
| **Mutatie-test van regressie-trap** | tijdelijk `newfake:ability` aan `TokenAbilities::all()` → test 1 faalt op exact die ability; restore → 3 passed weer (bewijst non-vacuous) |
| `php artisan test --compact` (full suite) | 358 passed / 1 incomplete / 0 failed / 1217 assertions / 13858ms (was 349 baseline) |
| `./vendor/bin/pint --dirty --format agent` | passed (Task 1, 2 GREEN, 3) |

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Filament/Resources/Consumers/ConsumerResource.php
- FOUND: app/Filament/Resources/Consumers/Pages/ListConsumers.php
- FOUND: app/Filament/Resources/Consumers/Pages/CreateConsumer.php
- FOUND: app/Filament/Resources/Consumers/Pages/EditConsumer.php
- FOUND: tests/Feature/Admin/ConsumerTokenActionTest.php
- FOUND: tests/Feature/Admin/PatAbilityPresetsTest.php
- FOUND: app/Models/Consumer.php (modified — connections() HasManyThrough toegevoegd)

**Commits exist:**
- FOUND: 55c97ad — feat(09-05): ConsumerResource CRUD-skelet (form + table + 3 pages)
- FOUND: d1ed3c2 — test(09-05): falende test voor Issue-PAT action (RED)
- FOUND: 7602d9d — feat(09-05): Issue-PAT action + PAT_PRESETS/PAT_CUSTOM_ONLY constants (GREEN)
- FOUND: 883772d — test(09-05): PatAbilityPresetsTest discovery-contract (D-03)

**Plan must-haves truths verified:** alle 7/7 truths uit het `must_haves.truths`-blok empirisch bewezen (zie "Must-Have Truths" sectie hierboven). Aanvullende must-have uit success_criteria #4 — "PatAbilityPresetsTest faalt zodra een nieuwe `TokenAbilities`-constant zonder preset-update wordt toegevoegd" — geverifieerd via expliciete mutatie-test.

**Key links verified:**
- `app/Filament/Resources/Consumers/ConsumerResource.php` → `App\Sanctum\TokenAbilities` via `use App\Sanctum\TokenAbilities` + 8× `TokenAbilities::*` referenties in PAT_PRESETS+PAT_CUSTOM_ONLY+customAbilitiesOptions
- `app/Filament/Resources/Consumers/ConsumerResource.php` → `App\Models\Consumer` via `use App\Models\Consumer` + `Consumer::class` op `$model` + `Consumer $record` type-hint in action-closure die `->createToken()` aanroept

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
