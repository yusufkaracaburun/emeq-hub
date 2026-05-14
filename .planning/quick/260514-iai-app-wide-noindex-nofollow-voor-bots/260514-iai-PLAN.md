---
phase: quick-260514-iai
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Middleware/SetNoIndexHeaders.php
  - bootstrap/app.php
  - public/robots.txt
  - resources/views/welcome.blade.php
  - tests/Feature/NoIndexHeaderTest.php
autonomous: true
requirements:
  - QUICK-260514-iai
must_haves:
  truths:
    - "Elke HTTP-response van emeq-hub bevat de header `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`"
    - "`/robots.txt` retourneert `User-agent: *` + `Disallow: /` zodat zoekmachines de hele app uitsluiten"
    - "De `welcome.blade.php`-view rendert `<meta name=\"robots\" content=\"noindex,nofollow\">` in `<head>`"
    - "Een PHPUnit feature-test asserteert dat `/up` de `X-Robots-Tag`-header retourneert"
  artifacts:
    - path: "app/Http/Middleware/SetNoIndexHeaders.php"
      provides: "Globale middleware die `X-Robots-Tag` op elke response zet"
      contains: "class SetNoIndexHeaders"
    - path: "bootstrap/app.php"
      provides: "Globale middleware-registratie via `$middleware->append(SetNoIndexHeaders::class)`"
      contains: "SetNoIndexHeaders"
    - path: "public/robots.txt"
      provides: "robots.txt met `Disallow: /` voor de hele app"
      contains: "Disallow: /"
    - path: "resources/views/welcome.blade.php"
      provides: "`<meta name=\"robots\">`-fallback voor HTML-scrapers"
      contains: "name=\"robots\""
    - path: "tests/Feature/NoIndexHeaderTest.php"
      provides: "PHPUnit feature-test op `/up`"
      contains: "X-Robots-Tag"
  key_links:
    - from: "bootstrap/app.php"
      to: "App\\Http\\Middleware\\SetNoIndexHeaders"
      via: "`->withMiddleware(fn (Middleware $middleware) => $middleware->append(SetNoIndexHeaders::class))`"
      pattern: "SetNoIndexHeaders::class"
    - from: "tests/Feature/NoIndexHeaderTest.php"
      to: "/up"
      via: "`$this->get('/up')->assertHeader('X-Robots-Tag', ...)`"
      pattern: "assertHeader.*X-Robots-Tag"
---

<objective>
App-wide noindex/nofollow afdwingen op emeq-hub via drie defensieve lagen: HTTP-header (middleware), `robots.txt` en een meta-tag in de enige bestaande view.

Purpose: emeq-hub is een interne integration-platform. Het bestaande `/up` health-endpoint, de toekomstige `/v1/*` REST-API en het straks volgende `/admin` Filament-panel mogen nooit in zoekresultaten verschijnen. Drie lagen omdat geen enkele zoekmachine/bot alle drie negeert.

Output:
- Nieuwe middleware `App\Http\Middleware\SetNoIndexHeaders` die `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` zet
- Middleware globaal geregistreerd in `bootstrap/app.php`
- `public/robots.txt` met `Disallow: /`
- `<meta name="robots" content="noindex,nofollow">` in `welcome.blade.php`
- PHPUnit feature-test `NoIndexHeaderTest`
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@CLAUDE.md
@bootstrap/app.php
@routes/web.php
@public/robots.txt
@tests/Feature/ExampleTest.php

<interfaces>
<!-- Huidige `bootstrap/app.php` middleware-config (Laravel 13 stijl) -->
```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

<!-- Laravel 13 Middleware API: gebruik `$middleware->append(ClassName::class)` voor globale registratie (draait op zowel `web` als `api`/health-routes). -->

<!-- Huidige `welcome.blade.php` `<head>` (regels 3-19) bevat `<meta charset>` + viewport + title. Voeg robots-meta toe direct na viewport. -->

<!-- Huidige `public/robots.txt` (Laravel default): `User-agent: *` + `Disallow:` (leeg = alles toegestaan). Moet worden: `Disallow: /` -->

<!-- Test-conventie uit `ExampleTest.php`: `class XxxTest extends TestCase` + `test_xxx(): void` methods + `$this->get('/path')->assertXxx()`. -->
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Middleware + bootstrap-registratie + robots.txt + meta-tag</name>
  <files>
    app/Http/Middleware/SetNoIndexHeaders.php,
    bootstrap/app.php,
    public/robots.txt,
    resources/views/welcome.blade.php
  </files>
  <behavior>
    - SetNoIndexHeaders::handle($request, Closure $next): elke response (incl. health `/up`) krijgt header `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`. Bestaande headers blijven intact.
    - bootstrap/app.php registreert de middleware globaal via `$middleware->append(SetNoIndexHeaders::class)`.
    - public/robots.txt bevat exact `User-agent: *\nDisallow: /\n`.
    - welcome.blade.php heeft `<meta name="robots" content="noindex,nofollow">` in `<head>` (direct na viewport-meta).
  </behavior>
  <action>
1. Maak middleware via artisan: `php artisan make:middleware SetNoIndexHeaders --no-interaction`. Dit creëert `app/Http/Middleware/SetNoIndexHeaders.php` (en de `app/Http/Middleware/` directory indien afwezig).
2. Vervang de handle-method body zodat na `$response = $next($request);` de header wordt gezet:
```php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    return $response;
}
```
Imports: `Closure`, `Illuminate\Http\Request`, `Symfony\Component\HttpFoundation\Response`. Geen PHPDoc-noise — types spreken voor zich (per `.ai/rules` "minimal comments").

3. Registreer in `bootstrap/app.php`: vervang de lege `->withMiddleware(function (Middleware $middleware): void { // })` body door `$middleware->append(\App\Http\Middleware\SetNoIndexHeaders::class);`. Globaal `append` werkt op alle groepen incl. de health-route (Laravel 13 health-routing).

4. Overschrijf `public/robots.txt` met:
```
User-agent: *
Disallow: /
```

5. Voeg in `resources/views/welcome.blade.php` direct na regel 5 (`<meta name="viewport" ...>`) toe: `<meta name="robots" content="noindex,nofollow">`. Niets anders in die view aanraken.

6. Run `vendor/bin/pint --dirty --format agent` om de PHP-wijzigingen te formatten.
  </action>
  <verify>
    <automated>vendor/bin/pint --dirty --test --format agent &amp;&amp; php artisan route:list --except-vendor &gt; /dev/null &amp;&amp; grep -q "SetNoIndexHeaders" bootstrap/app.php &amp;&amp; grep -q "Disallow: /" public/robots.txt &amp;&amp; grep -q 'name="robots"' resources/views/welcome.blade.php</automated>
  </verify>
  <done>
    - `app/Http/Middleware/SetNoIndexHeaders.php` bestaat met juiste handle-method.
    - `bootstrap/app.php` bevat `$middleware->append(\App\Http\Middleware\SetNoIndexHeaders::class)`.
    - `public/robots.txt` bevat `Disallow: /`.
    - `welcome.blade.php` bevat `<meta name="robots" content="noindex,nofollow">`.
    - Pint clean.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: PHPUnit feature-test voor X-Robots-Tag header</name>
  <files>tests/Feature/NoIndexHeaderTest.php</files>
  <behavior>
    - Test 1: `GET /up` retourneert status 200 én header `X-Robots-Tag` met waarde `noindex, nofollow, noarchive, nosnippet`.
    - Test 2: `GET /` (welcome-route die JSON retourneert) bevat dezelfde `X-Robots-Tag` header — bewijst dat middleware globaal werkt, niet alleen op health.
    - Test 3: `GET /robots.txt` retourneert body met `Disallow: /` (asserts via `$this->get('/robots.txt')->assertSee('Disallow: /', false)`).
  </behavior>
  <action>
1. Maak test via artisan: `php artisan make:test --phpunit NoIndexHeaderTest --no-interaction`.
2. Vul de testklasse met drie methods:
```php
public function test_up_endpoint_has_x_robots_tag_header(): void
{
    $this->get('/up')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
}

public function test_root_endpoint_has_x_robots_tag_header(): void
{
    $this->get('/')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
}

public function test_robots_txt_disallows_all(): void
{
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /', false);
}
```
3. Run `php artisan test --compact --filter=NoIndexHeaderTest`. Alle drie moeten groen zijn.
4. Run `vendor/bin/pint --dirty --format agent`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=NoIndexHeaderTest</automated>
  </verify>
  <done>
    - `tests/Feature/NoIndexHeaderTest.php` bestaat met 3 passing tests.
    - `php artisan test --compact --filter=NoIndexHeaderTest` exit 0.
    - Pint clean.
  </done>
</task>

</tasks>

<verification>
End-to-end smoke check (handmatig of in volgorde uitvoeren — niet onderdeel van geautomatiseerde gates):

```bash
# 1. Middleware-header op health-endpoint
curl -I http://hub.emeq.test:8090/up | grep -i "x-robots-tag"
# Verwacht: X-Robots-Tag: noindex, nofollow, noarchive, nosnippet

# 2. robots.txt
curl -s http://hub.emeq.test:8090/robots.txt
# Verwacht:
# User-agent: *
# Disallow: /

# 3. Meta-tag in welcome-view (alleen zichtbaar als route('/') HTML retourneert; nu retourneert ze JSON, dus dit blijft latent tot welcome-view weer in gebruik komt)
curl -s http://hub.emeq.test:8090/ | grep 'name="robots"' || echo "geen HTML (verwacht: / retourneert JSON)"

# 4. Volledige test-suite blijft groen
php artisan test --compact
```

Geautomatiseerde gates (gedekt door task-verify-commando's):
- Pint clean op gewijzigde PHP-files
- `php artisan test --compact --filter=NoIndexHeaderTest` exit 0
- Grep-gates op `bootstrap/app.php`, `public/robots.txt`, `welcome.blade.php`
</verification>

<success_criteria>
- [ ] `SetNoIndexHeaders`-middleware bestaat en zet `X-Robots-Tag` op alle responses
- [ ] Middleware globaal geregistreerd in `bootstrap/app.php`
- [ ] `public/robots.txt` bevat `Disallow: /`
- [ ] `welcome.blade.php` bevat `<meta name="robots" content="noindex,nofollow">`
- [ ] `NoIndexHeaderTest` heeft 3 passing tests
- [ ] Pint clean (`vendor/bin/pint --dirty --test --format agent` exit 0)
- [ ] Geen wijzigingen buiten de 5 files in `files_modified`
</success_criteria>

<output>
Geen SUMMARY.md vereist voor quick-tasks. Stop na groene test-run.
</output>
