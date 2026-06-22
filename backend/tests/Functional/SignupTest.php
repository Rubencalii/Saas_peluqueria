<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Alta de salón (multi-tenant Fase 6, doc 15): el signup crea una cuenta nueva
 * aislada, devuelve sesión y el administrador solo ve lo suyo.
 */
final class SignupTest extends WebTestCase
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
        $this->db->setNestTransactionsWithSavepoints(true);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testAltaDeSalonCreaCuentaAisladaYDaSesion(): void
    {
        $body = [
            'business_name' => 'Mi Salón',
            'slug' => 'mi-salon',
            'admin' => ['name' => 'Dueño', 'email' => 'dueno@misalon.es', 'password' => 'secreta123'],
            'location' => ['name' => 'Sede Única', 'slug' => 'centro'],
        ];

        $this->post('/api/v1/signup', $body);
        $res = $this->client->getResponse();
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $created = json_decode((string) $res->getContent(), true);
        self::assertNotEmpty($created['token']);
        self::assertSame('mi-salon', $created['account']['slug']);

        $token = $created['token'];

        // La sesión ve su cuenta en trial con plan free.
        $this->client->request('GET', '/api/v1/admin/account', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $account = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('trial', $account['account']['status']);
        self::assertSame('free', $account['subscription']['plan_code']);

        // Solo ve su única sede, no las del seed (cuenta principal).
        $this->client->request('GET', '/api/v1/admin/locations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $locations = json_decode((string) $this->client->getResponse()->getContent(), true)['locations'];
        self::assertCount(1, $locations);
        self::assertSame('Sede Única', $locations[0]['name']);

        // Plan free = 1 sede: el alta ya creó una, así que añadir otra da 402.
        $this->client->request(
            'POST',
            '/api/v1/admin/locations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Segunda', 'slug' => 'segunda'])
        );
        self::assertSame(402, $this->client->getResponse()->getStatusCode());

        // El email es único global: repetir el alta da 409.
        $this->post('/api/v1/signup', ['business_name' => 'Otro', 'slug' => 'otro-salon', 'admin' => $body['admin'], 'location' => ['name' => 'X']]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): void
    {
        $this->client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($body)
        );
    }
}
