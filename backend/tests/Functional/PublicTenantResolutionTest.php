<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Resolución del tenant en la web pública por subdominio (multi-tenant Fase 3).
 *
 * Dos cuentas con una sede que comparte el `slug` 'centro' (válido al ser único
 * por-cuenta). El catálogo público debe devolver la sede de la cuenta del
 * subdominio, y `localhost` (sin subdominio) cae en la cuenta principal.
 */
final class PublicTenantResolutionTest extends WebTestCase
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

    public function testElSubdominioResuelveLaCuenta(): void
    {
        $accountId = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra Cadena', 'otra-cadena', 'active') RETURNING id"
        );
        $foreignLocId = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone, active)
             VALUES (?, 'Centro Ajeno', 'centro', 'Europe/Madrid', TRUE) RETURNING id",
            [$accountId]
        );

        // localhost (sin subdominio) → cuenta principal: 'centro' es la sede 1 del seed.
        $res1 = $this->getJson('/api/v1/locations/centro/services', 'localhost');
        self::assertSame(1, $res1['location_id']);

        // Subdominio de la otra cuenta → su propia sede 'centro'.
        $res2 = $this->getJson('/api/v1/locations/centro/services', 'otra-cadena.reservas.app');
        self::assertSame($foreignLocId, $res2['location_id']);

        // El listado de sedes del subdominio solo trae las de esa cuenta.
        $locations = $this->getJson('/api/v1/locations', 'otra-cadena.reservas.app');
        $ids = array_column($locations, 'id');
        self::assertSame([$foreignLocId], $ids);
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path, string $host): array
    {
        $this->client->request('GET', $path, server: ['HTTP_HOST' => $host]);
        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        return json_decode((string) $res->getContent(), true);
    }
}
