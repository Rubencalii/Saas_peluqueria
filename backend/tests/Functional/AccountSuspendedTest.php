<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cuenta suspendida ⇒ solo lectura (multi-tenant Fase 5, doc 15). Las lecturas
 * siguen funcionando; las escrituras del panel se rechazan con 402.
 */
final class AccountSuspendedTest extends WebTestCase
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

    public function testCuentaSuspendidaQuedaEnSoloLectura(): void
    {
        $token = $this->login('admin@salon.es', 'admin1234');

        // Suspendemos la cuenta principal (id 1, la del seed).
        $this->db->executeStatement("UPDATE account SET status = 'suspended' WHERE id = 1");

        // Lectura: permitida.
        $this->client->request('GET', '/api/v1/admin/locations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Escritura: bloqueada con 402.
        $this->client->request(
            'POST',
            '/api/v1/admin/locations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Nueva', 'slug' => 'nueva'])
        );
        self::assertSame(402, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email, string $password): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => $password])
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }
}
