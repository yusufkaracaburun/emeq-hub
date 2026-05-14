---
phase: quick
plan: 260514-ndk
type: execute
wave: 1
depends_on: []
files_modified:
  - tests/Feature/ConnectionEncryptionTest.php
autonomous: true
requirements:
  - HUB-01-SC-3
must_haves:
  truths:
    - "Een test bewijst dat `connections.refresh_token` versleuteld at rest is voor een Mollie-Connection"
    - "De `ConnectionEncryptionTest`-suite groeit van 7 naar 8 tests en blijft volledig groen"
    - "Phase 3 SC-3 gap (refresh_token-coverage) is daarmee gesloten zonder productiecode-wijziging"
  artifacts:
    - path: "tests/Feature/ConnectionEncryptionTest.php"
      provides: "Nieuwe test_method `test_mollie_refresh_token_is_encrypted_at_rest`"
      contains: "test_mollie_refresh_token_is_encrypted_at_rest"
  key_links:
    - from: "tests/Feature/ConnectionEncryptionTest.php::test_mollie_refresh_token_is_encrypted_at_rest"
      to: "connections.refresh_token kolom"
      via: "DB::table('connections')->where('id', $connection->id)->value('refresh_token')"
      pattern: "DB::table\\('connections'\\).*->value\\('refresh_token'\\)"
---

<objective>
Sluit de SC-3-gap uit `.planning/phases/03-hub-skeleton/03-VERIFICATION.md` (= BL-01 uit `03-REVIEW.md`): de `ConnectionEncryptionTest` bewijst encryption-at-rest voor `client_key`, `subscription_key` en `access_token`, maar niet voor `refresh_token`. `Connection.php` heeft de `encrypted` cast al correct staan (regel 55 in `casts()`). Productiegedrag is dus al goed — alleen het bewijs ontbreekt.

Purpose: Phase 3 acceptance-status van "groen met gap" naar "volledig groen" brengen, zodat HUB-01 SC-3 zonder asterisk afgevinkt staat voor Phase 5b/4-werk.

Output: Eén nieuwe test-method in `tests/Feature/ConnectionEncryptionTest.php`. Geen wijzigingen aan productiecode.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.ai/rules/global.md
@.ai/rules/engineering.md
@.planning/STATE.md

<interfaces>
<!-- Bestaand patroon in dezelfde file — exact kopiëren, alleen veld + secret swappen. -->

From tests/Feature/ConnectionEncryptionTest.php (regel 43-55, de directe analog):
```php
public function test_mollie_access_token_is_encrypted_at_rest(): void
{
    $connection = Connection::factory()
        ->forMollie()
        ->create(['access_token' => 'access_secret-789']);

    $rawAtRest = DB::table('connections')
        ->where('id', $connection->id)
        ->value('access_token');

    $this->assertNotSame('access_secret-789', $rawAtRest);
    $this->assertSame('access_secret-789', $connection->fresh()->access_token);
}
```

From app/Models/Connection.php (regel 51-63):
```php
protected function casts(): array
{
    return [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',   // ← cast is correct; alleen test ontbreekt
        'client_key' => 'encrypted',
        'subscription_key' => 'encrypted',
        ...
    ];
}
```

From database/factories/ConnectionFactory.php — `forMollie()`-state (regel 43-55):
- Default `refresh_token` = `'refresh_'.Str::random(40)`
- Override via `->create(['refresh_token' => '...'])` zoals bij `access_token`
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Voeg test_mollie_refresh_token_is_encrypted_at_rest toe</name>
  <files>tests/Feature/ConnectionEncryptionTest.php</files>
  <action>
Voeg één nieuwe public method toe aan `Tests\Feature\ConnectionEncryptionTest`, direct ná `test_mollie_access_token_is_encrypted_at_rest` (regel 55) en vóór `test_to_array_hides_all_credential_fields` (regel 57). Naam: `test_mollie_refresh_token_is_encrypted_at_rest`.

Kopieer het patroon van `test_mollie_access_token_is_encrypted_at_rest` 1-op-1 en swap alleen:
- Geheim-literal: `'access_secret-789'` → `'refresh_secret-789'`
- Overgeschreven attribuut + DB-kolom + property-access: `access_token` → `refresh_token`

Concreet:

```php
public function test_mollie_refresh_token_is_encrypted_at_rest(): void
{
    $connection = Connection::factory()
        ->forMollie()
        ->create(['refresh_token' => 'refresh_secret-789']);

    $rawAtRest = DB::table('connections')
        ->where('id', $connection->id)
        ->value('refresh_token');

    $this->assertNotSame('refresh_secret-789', $rawAtRest);
    $this->assertSame('refresh_secret-789', $connection->fresh()->refresh_token);
}
```

Geen andere wijzigingen in deze file. Geen PHPDoc-blok toevoegen (matched de bestaande 7 methods — geen daarvan heeft een docblock; rule `engineering.md` + minimal-comments-feedback).

Geen productiecode aanraken. `Connection::$casts['refresh_token'] = 'encrypted'` staat er al; deze test bewijst dat hij ook in de praktijk werkt op de productiestack.

Per HUB-01-SC-3.
  </action>
  <verify>
    <automated>php artisan test --compact tests/Feature/ConnectionEncryptionTest.php</automated>
  </verify>
  <done>
- `ConnectionEncryptionTest` rapporteert **8 passed** (was 7).
- Nieuwe method `test_mollie_refresh_token_is_encrypted_at_rest` staat tussen `test_mollie_access_token_is_encrypted_at_rest` en `test_to_array_hides_all_credential_fields`.
- `grep -c 'refresh_token' tests/Feature/ConnectionEncryptionTest.php` >= 4 (1× create-override, 1× DB-kolom-`value`, 2× assertions).
- Geen wijzigingen in `app/Models/Connection.php` of `database/factories/ConnectionFactory.php` (`git diff --stat` toont enkel `tests/Feature/ConnectionEncryptionTest.php`).
  </done>
</task>

</tasks>

<verification>
**Acceptance-grep:**

```bash
grep -n 'test_mollie_refresh_token_is_encrypted_at_rest' tests/Feature/ConnectionEncryptionTest.php
# verwacht: één hit

grep -v '^#' tests/Feature/ConnectionEncryptionTest.php | grep -c "function test_"
# verwacht: 8

git diff --stat
# verwacht: enkel `tests/Feature/ConnectionEncryptionTest.php`
```

**Full suite re-run (sanity):**

```bash
php artisan test --compact
# verwacht: 28 passed / 1 incomplete / 0 failed (was 27 passed)
```

**Pint:**

```bash
./vendor/bin/pint --dirty --format agent
# verwacht: clean of geringe whitespace-fix in de gewijzigde file
```
</verification>

<success_criteria>
- 8/8 tests in `ConnectionEncryptionTest` groen.
- Volledige suite: 28 passed / 1 incomplete / 0 failed.
- Phase 3 SC-3 gap gesloten: `refresh_token`-encryption-at-rest is nu expliciet bewezen op productiestack (echte `APP_KEY`, geen MockEncrypter — analog aan SC-3-decision uit STATE.md regel 76).
- Eén commit, max 1 file changed, commit-message in Nederlands, geen `--no-verify`.
- Voorgestelde commit-message: `test(phase-3): sluit SC-3-gap met refresh_token encryption-at-rest test`
</success_criteria>

<output>
After completion, create `.planning/quick/260514-ndk-sluit-sc-3-gap-voor-phase-3-voeg-refresh/260514-ndk-SUMMARY.md` met:
- Welke test is toegevoegd + waar in de file.
- Test-output (8 passed bevestigd).
- Update voor `.planning/phases/03-hub-skeleton/03-VERIFICATION.md` SC-3 (gap → closed).
- Commit-SHA.
</output>
