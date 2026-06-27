<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cobertura HTTP de endpoints del panel sumados con el frontend: disponibilidad
 * para alta manual (acotada a la cuenta) y RGPD/edición de cliente.
 */
final class AdminPanelExtraTest extends WebTestCase
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

    public function testDisponibilidadDelPanelEstaAcotadaALaCuenta(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        // Sede 1 (centro) + servicio 2 (Corte hombre) son de la cuenta principal.
        $this->get("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data['slots']);

        // Una sede de OTRA cuenta no es accesible (404, no se filtra su existencia).
        $other = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra', 'otra-x', 'active') RETURNING id"
        );
        $loc = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone, active)
             VALUES (?, 'Ajena', 'ajena-x', 'Europe/Madrid', TRUE) RETURNING id",
            [$other]
        );
        $this->get("/api/v1/admin/availability?location_id={$loc}&service_id=2&date={$monday}", $token);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testEdicionYDerechosRgpdDeCliente(): void
    {
        $token = $this->login();
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (account_id, name, phone, email) VALUES (1, 'Cliente RGPD', '+34600555444', 'rgpd@x.es') RETURNING id"
        );

        // Editar nombre/email.
        $this->client->request('PATCH', "/api/v1/admin/customers/{$customerId}", server: $this->auth($token), content: (string) json_encode(['name' => 'Nombre Nuevo']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Nombre Nuevo', $this->db->fetchOne('SELECT name FROM customer WHERE id = ?', [$customerId]));

        // Exportar datos (acceso/portabilidad).
        $this->get("/api/v1/admin/customers/{$customerId}/export", $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('rgpd@x.es', (string) $this->client->getResponse()->getContent());

        // Anonimizar (supresión): borra la PII pero conserva la fila.
        $this->client->request('DELETE', "/api/v1/admin/customers/{$customerId}", server: $this->auth($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $phone = (string) $this->db->fetchOne('SELECT phone FROM customer WHERE id = ?', [$customerId]);
        self::assertStringNotContainsString('600555444', $phone);
    }

    private function login(): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => 'admin@salon.es', 'password' => 'admin1234']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function get(string $path, string $token): void
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }
}
