<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Las respuestas de la API deben llevar las cabeceras de seguridad básicas
 * (SecurityHeadersListener). Cubre sniffing de tipo, clickjacking y fuga de
 * Referer. Las rutas no-API no las añaden.
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testLaApiAniadeLasCabecerasDeSeguridad(): void
    {
        $client = static::createClient();
        // Endpoint público del seed (sede 'centro' = sede 1).
        $client->request('GET', '/api/v1/locations', server: ['HTTP_HOST' => 'localhost']);
        $res = $client->getResponse();

        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $res->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $res->headers->get('Referrer-Policy'));
        self::assertSame('same-site', $res->headers->get('Cross-Origin-Resource-Policy'));
    }
}
