<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Auth\TotpService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Doble factor de punta a punta: alta en dos pasos (setup + enable con código
 * válido), login que exige el código con 2FA activo, y baja protegida por
 * código (una sesión robada no puede quitar el 2FA).
 */
final class TwoFactorTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private TotpService $totp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        $this->db->beginTransaction();
        $this->totp = new TotpService();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    /** Código TOTP vigente para un secreto (algoritmo verificado contra el RFC). */
    private function codeFor(string $secret): string
    {
        return $this->totp->code($secret);
    }

    public function testAltaLoginYBajaDelDobleFactor(): void
    {
        $token = $this->login(['email' => 'admin@salon.es', 'password' => 'admin1234']);

        // Estado inicial: sin 2FA.
        self::assertFalse($this->getJson('/api/v1/admin/2fa', $token)['enabled']);

        // Setup: devuelve secreto y URI para la app; aún NO activa nada.
        $this->client->request('POST', '/api/v1/admin/2fa/setup', server: $this->auth($token));
        $setup = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $setup['secret']);
        self::assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);
        self::assertFalse($this->getJson('/api/v1/admin/2fa', $token)['enabled']);

        // Enable con código erróneo → 400 y sigue sin activarse.
        $this->client->request('POST', '/api/v1/admin/2fa/enable', server: $this->auth($token), content: (string) json_encode([
            'secret' => $setup['secret'],
            'code' => '000000',
        ]));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Enable con código válido → activo.
        $this->client->request('POST', '/api/v1/admin/2fa/enable', server: $this->auth($token), content: (string) json_encode([
            'secret' => $setup['secret'],
            'code' => $this->codeFor($setup['secret']),
        ]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->getJson('/api/v1/admin/2fa', $token)['enabled']);

        // Con 2FA activo: la contraseña sola ya no entra…
        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email' => 'admin@salon.es',
            'password' => 'admin1234',
        ]));
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('TOTP_REQUIRED', (string) $this->client->getResponse()->getContent());

        // …con código erróneo tampoco…
        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email' => 'admin@salon.es',
            'password' => 'admin1234',
            'totp_code' => '000000',
        ]));
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('TOTP_INVALID', (string) $this->client->getResponse()->getContent());

        // …y con contraseña + código válido, sí.
        $token2 = $this->login([
            'email' => 'admin@salon.es',
            'password' => 'admin1234',
            'totp_code' => $this->codeFor($setup['secret']),
        ]);

        // Desactivar exige código válido (una sesión robada no basta).
        $this->client->request('POST', '/api/v1/admin/2fa/disable', server: $this->auth($token2), content: (string) json_encode(['code' => '000000']));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/admin/2fa/disable', server: $this->auth($token2), content: (string) json_encode([
            'code' => $this->codeFor($setup['secret']),
        ]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->getJson('/api/v1/admin/2fa', $token2)['enabled']);

        // Sin 2FA, el login vuelve a ser solo contraseña.
        $this->login(['email' => 'admin@salon.es', 'password' => 'admin1234']);
    }

    /** @param array<string, string> $body */
    private function login(array $body): string
    {
        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    /** @return array<string, mixed> */
    private function getJson(string $path, string $token): array
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
