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

    public function testProximoHuecoPorProfesional(): void
    {
        $token = $this->login();

        // Sede 1 (centro) + servicio 2 (Corte hombre): hay personal que lo hace.
        $data = $this->getJson('/api/v1/admin/availability/next?location_id=1&service_id=2', $token);
        self::assertIsArray($data['staff']);
        self::assertNotEmpty($data['staff'], 'Debe haber profesionales que ofrezcan el servicio.');

        $algunoConHueco = false;
        foreach ($data['staff'] as $s) {
            self::assertArrayHasKey('staff_id', $s);
            self::assertArrayHasKey('staff_name', $s);
            self::assertArrayHasKey('next', $s);
            if ($s['next'] !== null) {
                self::assertArrayHasKey('date', $s['next']);
                self::assertArrayHasKey('start', $s['next']);
                $algunoConHueco = true;
            }
        }
        self::assertTrue($algunoConHueco, 'Al menos un profesional debe tener hueco en las próximas semanas.');
    }

    public function testFiltroDeConsentimientoWhatsApp(): void
    {
        $token = $this->login();
        $this->db->executeStatement(
            "INSERT INTO customer (account_id, name, phone, wa_consent, consent_at)
             VALUES (1, 'Con Consent', '+34600111222', TRUE, now())"
        );
        $this->db->executeStatement(
            "INSERT INTO customer (account_id, name, phone, wa_consent) VALUES (1, 'Sin Consent', '+34600333444', FALSE)"
        );

        $withConsent = $this->getJson('/api/v1/admin/customers?consent=yes', $token);
        $names = array_column($withConsent['customers'], 'name');
        self::assertContains('Con Consent', $names);
        self::assertNotContains('Sin Consent', $names);
        foreach ($withConsent['customers'] as $c) {
            self::assertTrue($c['wa_consent']);
        }

        $withoutConsent = $this->getJson('/api/v1/admin/customers?consent=no', $token);
        foreach ($withoutConsent['customers'] as $c) {
            self::assertFalse($c['wa_consent']);
        }
    }

    public function testAltaManualDeCitaPorElPanel(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        // Hueco real ofertado (sede 1 + servicio 2), igual que dispara "Reservar".
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        self::assertNotEmpty($offer['slots'], 'El seed debe ofrecer huecos el próximo lunes.');
        $slot = $offer['slots'][0];

        $body = [
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'channel' => 'web', // el panel lo fuerza a "manual": debe ignorarse
            'customer' => ['name' => 'Cliente Manual', 'phone' => '+34600999888', 'email' => 'manual@x.es'],
        ];
        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode($body));

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('confirmada', $created['status']);
        self::assertSame($slot['staff_id'], $created['staff_id']);
        self::assertNotEmpty($created['public_code']);

        // Persistió como cita manual, con el cliente y la trazabilidad de quién la creó.
        $row = $this->db->fetchAssociative(
            'SELECT channel, created_by, customer_id FROM appointment WHERE id = ?',
            [$created['appointment_id']]
        );
        self::assertSame('manual', $row['channel']);
        self::assertNotNull($row['created_by']);
        self::assertSame(
            '+34600999888',
            $this->db->fetchOne('SELECT phone FROM customer WHERE id = ?', [(int) $row['customer_id']])
        );
    }

    public function testElPanelRechazaUnHuecoNoOfrecido(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        self::assertNotEmpty($offer['slots']);

        // Desplazado 5 min: cae fuera del grid de 15 min, no es un hueco ofertado.
        $bogusStart = (new \DateTimeImmutable($offer['slots'][0]['start']))->modify('+5 minutes')->format('c');
        $body = [
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $offer['slots'][0]['staff_id'],
            'start' => $bogusStart,
            'customer' => ['name' => 'No Cabe', 'phone' => '+34600111000'],
        ];
        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode($body));

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('SLOT_TAKEN', (string) $this->client->getResponse()->getContent());
    }

    public function testReservaParaClienteExistenteLoReusaSinDuplicarNiRevocarConsentimiento(): void
    {
        $token = $this->login();
        $phone = '+34655777888';

        // Cliente ya registrado con consentimiento de WhatsApp.
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (account_id, name, phone, email, wa_consent, consent_at)
             VALUES (1, 'Cliente Buscable', ?, 'buscable@x.es', TRUE, now()) RETURNING id",
            [$phone]
        );

        // 1) Aparece en la búsqueda del panel (lo que alimenta el selector "Existente").
        $found = $this->getJson('/api/v1/admin/customers?query=Buscable', $token);
        self::assertContains($customerId, array_column($found['customers'], 'id'));

        // 2) Reservamos reusándolo: el panel manda nombre+teléfono, sin wa_consent.
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        self::assertNotEmpty($offer['slots']);
        $slot = $offer['slots'][0];

        $body = [
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'customer' => ['name' => 'Cliente Buscable', 'phone' => $phone],
        ];
        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode($body));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);

        // 3) No se ha duplicado el cliente y la cita apunta al existente.
        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM customer WHERE account_id = 1 AND phone = ?', [$phone])
        );
        self::assertSame(
            $customerId,
            (int) $this->db->fetchOne('SELECT customer_id FROM appointment WHERE id = ?', [$created['appointment_id']])
        );

        // 4) El consentimiento sigue intacto tras reservar.
        self::assertTrue(
            (bool) $this->db->fetchOne('SELECT wa_consent FROM customer WHERE id = ?', [$customerId]),
            'Reservar no debe revocar el consentimiento.'
        );
    }

    public function testCortaElPanelConSecretoInseguroEnHostNoLocal(): void
    {
        // En test el APP_SECRET es un placeholder inseguro: desde un host NO local
        // el panel debe cortar con 500 (defensa ante despliegue mal configurado).
        $this->client->request('GET', '/api/v1/admin/me', server: ['HTTP_HOST' => 'panel.example.com']);
        self::assertSame(500, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('INSECURE_CONFIG', (string) $this->client->getResponse()->getContent());
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

    /** @return array<string, mixed> */
    private function getJson(string $path, string $token): array
    {
        $this->get($path, $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
