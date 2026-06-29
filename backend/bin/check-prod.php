#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Valida la configuración de PRODUCCIÓN antes de desplegar. Lee las VARIABLES DE
 * ENTORNO reales (no .env), así que ejecútalo en el entorno destino o con las
 * variables exportadas. Sale con código !=0 si hay fallos graves.
 *
 *   APP_ENV=prod APP_SECRET=... CORS_ALLOWED_ORIGINS=... php bin/check-prod.php
 */

$fail = [];
$warn = [];
$env = static fn (string $k): string => (string) (getenv($k) ?: '');

$secret = $env('APP_SECRET');
if ($secret === '' || mb_strlen($secret) < 32 || preg_match('/(placeholder|insecure|change.?me|example|secret|test)/i', $secret) === 1) {
    $fail[] = 'APP_SECRET inseguro o por defecto. Genera uno: php -r "echo bin2hex(random_bytes(32));"';
}

if ($env('APP_ENV') !== 'prod') {
    $fail[] = 'APP_ENV debe ser "prod" (es "' . ($env('APP_ENV') ?: 'sin definir') . '").';
}

if (in_array(strtolower($env('APP_DEBUG')), ['1', 'true'], true)) {
    $fail[] = 'APP_DEBUG está activo; debe ser 0/false en producción.';
}

$cors = $env('CORS_ALLOWED_ORIGINS');
if ($cors === '' || str_contains($cors, '*')) {
    $fail[] = 'CORS_ALLOWED_ORIGINS no debe ser "*" ni vacío; fija los dominios reales.';
}

$dbUrl = $env('DATABASE_URL');
if ($dbUrl === '') {
    $fail[] = 'DATABASE_URL sin definir.';
} elseif (preg_match('#://peluqueria(_app)?:#', $dbUrl, $m)) {
    if (($m[1] ?? '') !== '_app') {
        $warn[] = 'DATABASE_URL conecta como el owner; para activar RLS conecta como "peluqueria_app".';
    }
}

if ($env('TRUSTED_PROXIES') === '') {
    $warn[] = 'TRUSTED_PROXIES vacío; tras un balanceador, fíjalo para que el rate limiting use la IP real.';
}

if ($env('WHATSAPP_TOKEN') !== '' && $env('WHATSAPP_APP_SECRET') === '') {
    $warn[] = 'WHATSAPP_TOKEN definido pero WHATSAPP_APP_SECRET vacío: el webhook se rechazará en prod (fail-closed).';
}

foreach ($warn as $w) {
    fwrite(STDERR, "⚠  {$w}\n");
}
foreach ($fail as $f) {
    fwrite(STDERR, "✗  {$f}\n");
}

if ($fail !== []) {
    fwrite(STDERR, "\n✗ Configuración de producción NO válida (" . count($fail) . " error(es)).\n");
    exit(1);
}

fwrite(STDOUT, "✓ Configuración de producción válida" . ($warn !== [] ? ' (con ' . count($warn) . ' aviso(s))' : '') . ".\n");
exit(0);
