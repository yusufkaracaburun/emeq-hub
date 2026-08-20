<?php

declare(strict_types=1);

/*
 * Audits Laravel Brain's graph output: every provider-scoped SDK route
 * (URI prefix matching a key in config/hub-providers.php) must carry the
 * `feature.provider:{provider}` middleware.
 *
 * Standalone — does not require laramint/laravel-brain to be installed.
 * Reads pre-generated JSON from storage/app/laravel-brain/.
 *
 * Usage:
 *   php bin/audit-provider-gates.php
 *   php bin/audit-provider-gates.php /custom/path/to/laravel-brain
 *
 * Exit codes:
 *   0 — all provider-scoped routes have their provider gate
 *   1 — one or more routes are missing the gate (printed to stdout)
 *   2 — preconditions failed (missing brain output or provider config)
 */

$root = dirname(__DIR__);
$brainDir = $argv[1] ?? $root.'/storage/app/laravel-brain';
$configPath = $root.'/config/hub-providers.php';

if (! is_dir($brainDir)) {
    fwrite(STDERR, "ERR: Brain output dir not found: {$brainDir}\n");
    fwrite(STDERR, "Hint: composer require --dev laramint/laravel-brain && php artisan brain:scan\n");
    exit(2);
}

if (! file_exists($configPath)) {
    fwrite(STDERR, "ERR: Provider config not found: {$configPath}\n");
    exit(2);
}

/** @var array<string, array<string, mixed>> $providers */
$providers = require $configPath;
$providerKeys = array_keys($providers);

$audited = 0;
$missing = [];

foreach (glob($brainDir.'/.graph-*.json') ?: [] as $file) {
    if (str_contains($file, 'manifest')) {
        continue;
    }

    $graph = json_decode((string) file_get_contents($file), true);
    if (! is_array($graph)) {
        continue;
    }

    $routeData = null;
    foreach ($graph['nodes'] ?? [] as $node) {
        if (($node['type'] ?? null) === 'route') {
            $routeData = $node['data'] ?? null;
            break;
        }
    }
    if (! is_array($routeData)) {
        continue;
    }

    $uri = ltrim((string) ($routeData['uri'] ?? ''), '/');
    $matchedProvider = null;
    foreach ($providerKeys as $provider) {
        if (str_starts_with($uri, $provider.'/') || $uri === $provider) {
            $matchedProvider = $provider;
            break;
        }
    }
    if ($matchedProvider === null) {
        continue;
    }

    $audited++;
    $middleware = [];
    foreach ($graph['nodes'] ?? [] as $node) {
        if (($node['type'] ?? null) === 'middleware') {
            $middleware[] = str_replace('middleware::', '', (string) ($node['id'] ?? ''));
        }
    }

    $expectedGate = 'feature.provider:'.$matchedProvider;
    if (! in_array($expectedGate, $middleware, true)) {
        $missing[] = sprintf(
            '%-7s /%s  — expected %s, got [%s]',
            $routeData['method'] ?? '?',
            $uri,
            $expectedGate,
            implode(', ', $middleware) ?: '(none)'
        );
    }
}

echo "Audited {$audited} provider-scoped routes across ".count($providerKeys).' providers ('.implode(', ', $providerKeys).").\n";

if ($missing === []) {
    echo "OK — every provider-scoped SDK route carries its feature.provider:* gate.\n";
    exit(0);
}

echo "\nMISSING provider gate on ".count($missing)." route(s):\n";
foreach ($missing as $line) {
    echo "  - {$line}\n";
}
exit(1);
