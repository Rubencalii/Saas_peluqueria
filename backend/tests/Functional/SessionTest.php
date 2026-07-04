<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sesiones del panel: revocación (logout en todos los dispositivos) y refresco
 * del token (doc 14 §9). El logout invalida los tokens previos; el refresh
 * renueva uno válido.
 */
final class SessionTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testLogoutRevocaYRefreshRenueva(): void
    {
        $token = $this->login();
        self::assertSame(200, $this->meStatus($token), 'El token recién emitido debe valer.');

        // Logout en todos los dispositivos → el token deja de valer.
        $this->client->request('POST', '/api/v1/admin/auth/logout', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(401, $this->meStatus($token), 'Tras el logout el token debe quedar revocado.');

        // Un nuevo login funciona.
        $token2 = $this->login();
        self::assertSame(200, $this->meStatus($token2));

        // Refresh: a partir de un token válido se obtiene otro válido.
        $this->client->request('POST', '/api/v1/auth/refresh', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token2]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $refreshed = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
        self::assertNotSame('', $refreshed);
        self::assertSame(200, $this->meStatus($refreshed));
    }

    private function login(): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => 'admin@salon.es', 'password' => 'admin1234'])
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    private function meStatus(string $token): int
    {
        $this->client->request('GET', '/api/v1/admin/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse()->getStatusCode();
    }
}
