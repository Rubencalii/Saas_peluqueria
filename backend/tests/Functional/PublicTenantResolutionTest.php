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

    public function testPersonalPublicoPorSedeYServicio(): void
    {
        // Sede 1 (centro) + servicio 2 (Corte hombre) del seed → lista de personal.
        $res = $this->getJson('/api/v1/staff?location_id=1&service_id=2', 'localhost');
        self::assertIsArray($res['staff']);
        self::assertNotEmpty($res['staff'], 'El seed debe tener personal para ese servicio/sede.');
        self::assertArrayHasKey('name', $res['staff'][0]);

        // Una sede de otra cuenta no expone personal (lista vacía).
        $other = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('X', 'x-acc', 'active') RETURNING id"
        );
        $loc = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone, active) VALUES (?, 'X', 'x-loc', 'Europe/Madrid', TRUE) RETURNING id",
            [$other]
        );
        $res2 = $this->getJson("/api/v1/staff?location_id={$loc}&service_id=2", 'localhost');
        self::assertSame([], $res2['staff']);
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
