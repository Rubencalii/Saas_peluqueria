<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aislamiento entre cuentas (multi-tenant Fase 2, doc 15).
 *
 * Crea un SEGUNDO tenant con su propia sede (que incluso reutiliza el slug
 * 'centro' del tenant 1, ahora permitido por la unicidad por-cuenta) y verifica
 * que el panel de cada cuenta sólo ve y sólo puede tocar sus propios datos.
 *
 * Todo corre dentro de una transacción que se revierte; `disableReboot()`
 * mantiene el mismo kernel/conexión entre peticiones para que la transacción
 * envuelva tanto el seed como las llamadas HTTP.
 */
final class TenantIsolationTest extends WebTestCase
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

    public function testPanelEstaAisladoPorCuenta(): void
    {
        $accountId = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra Cadena', 'otra-cadena', 'active') RETURNING id"
        );
        $this->db->executeStatement(
            "INSERT INTO subscription (account_id, plan_code, status) VALUES (?, 'free', 'active')",
            [$accountId]
        );
        // Mismo slug 'centro' que el tenant 1: válido al ser unicidad por-cuenta.
        $foreignLocId = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone)
             VALUES (?, 'Sede Ajena', 'centro', 'Europe/Madrid') RETURNING id",
            [$accountId]
        );
        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id)
             VALUES (?, 'Admin Otra', 'otra@ajena.es', crypt('secret123', gen_salt('bf')), 'admin_cadena', NULL)",
            [$accountId]
        );

        $token1 = $this->login('admin@salon.es', 'admin1234');   // tenant 1 (seed)
        $token2 = $this->login('otra@ajena.es', 'secret123');    // tenant 2 (creado aquí)

        // El tenant 1 ve sus sedes (centro/norte) y NO la ajena.
        $locations1 = $this->getJson('/api/v1/admin/locations', $token1)['locations'];
        $ids1 = array_column($locations1, 'id');
        self::assertContains('centro', array_column($locations1, 'slug'));
        self::assertContains('norte', array_column($locations1, 'slug'));
        self::assertNotContains($foreignLocId, $ids1, 'El tenant 1 no debe ver la sede del tenant 2.');

        // El tenant 2 sólo ve SU sede.
        $locations2 = $this->getJson('/api/v1/admin/locations', $token2)['locations'];
        self::assertCount(1, $locations2);
        self::assertSame($foreignLocId, $locations2[0]['id']);

        // El tenant 2 no puede modificar una sede del tenant 1 → 404 (no existe para él).
        $this->client->request(
            'PATCH',
            '/api/v1/admin/locations/1',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token2, 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Secuestrada'])
        );
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // La sede del tenant 1 sigue intacta.
        self::assertSame('Salón Centro', $this->db->fetchOne('SELECT name FROM location WHERE id = 1'));
    }

    private function login(string $email, string $password): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => $password])
        );
        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        return (string) json_decode((string) $res->getContent(), true)['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path, string $token): array
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        return json_decode((string) $res->getContent(), true);
    }
}
