<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bonos/packs de punta a punta: catálogo (solo admins), venta a un cliente y
 * canje automático e idempotente al completar una cita del servicio; los
 * bonos caducados o de otro servicio no se tocan.
 */
final class AdminPackTest extends WebTestCase
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

    public function testCatalogoVentaYCanjeAlCompletarCita(): void
    {
        $token = $this->login();

        // 1) Catálogo: bono de 5 cortes (servicio 2) con 90 días de validez.
        $this->client->request('POST', '/api/v1/admin/packs', server: $this->auth($token), content: (string) json_encode([
            'service_id' => 2,
            'name' => 'Bono 5 cortes',
            'sessions' => 5,
            'price' => 60,
            'validity_days' => 90,
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $packId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $catalog = $this->getJson('/api/v1/admin/packs', $token)['packs'];
        self::assertContains('Bono 5 cortes', array_column($catalog, 'name'));

        // 2) Venta a un cliente.
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (account_id, name, phone) VALUES (1, 'Clienta Bono', '+34600123123') RETURNING id"
        );
        $this->client->request('POST', "/api/v1/admin/customers/{$customerId}/packs", server: $this->auth($token), content: (string) json_encode([
            'pack_id' => $packId,
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $packs = $this->getJson("/api/v1/admin/customers/{$customerId}/packs", $token)['packs'];
        self::assertCount(1, $packs);
        self::assertSame(5, $packs[0]['sessions_left']);
        self::assertNotNull($packs[0]['expires_at'], 'Con validity_days el bono caduca.');

        // 3) Cita del servicio del bono, reservada en un hueco real.
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        $slot = $offer['slots'][0];
        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode([
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'customer' => ['name' => 'Clienta Bono', 'phone' => '+34600123123'],
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $apptId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['appointment_id'];

        // 4) Completar la cita descuenta UNA sesión…
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $packs = $this->getJson("/api/v1/admin/customers/{$customerId}/packs", $token)['packs'];
        self::assertSame(4, $packs[0]['sessions_left']);

        // …y volver a marcarla completada NO descuenta otra (idempotente).
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        $packs = $this->getJson("/api/v1/admin/customers/{$customerId}/packs", $token)['packs'];
        self::assertSame(4, $packs[0]['sessions_left']);
    }

    public function testNoCanjeaBonosCaducadosNiDeOtroServicio(): void
    {
        $token = $this->login();
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (account_id, name, phone) VALUES (1, 'Sin Canje', '+34600456456') RETURNING id"
        );

        // Bono CADUCADO del servicio 2 y bono vivo de OTRO servicio (1).
        $packCorte = (int) $this->db->fetchOne(
            "INSERT INTO pack (account_id, service_id, name, sessions, price) VALUES (1, 2, 'Caducado', 5, 50) RETURNING id"
        );
        $this->db->executeStatement(
            "INSERT INTO customer_pack (customer_id, pack_id, sessions_left, expires_at)
             VALUES (?, ?, 5, now() - interval '1 day')",
            [$customerId, $packCorte]
        );
        $packOtro = (int) $this->db->fetchOne(
            "INSERT INTO pack (account_id, service_id, name, sessions, price) VALUES (1, 1, 'Otro servicio', 5, 50) RETURNING id"
        );
        $this->db->executeStatement(
            'INSERT INTO customer_pack (customer_id, pack_id, sessions_left) VALUES (?, ?, 5)',
            [$customerId, $packOtro]
        );

        // Cita del servicio 2 completada: ningún bono debe moverse.
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        $slot = $offer['slots'][0];
        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode([
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'customer' => ['name' => 'Sin Canje', 'phone' => '+34600456456'],
        ]));
        $apptId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['appointment_id'];
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));

        $lefts = $this->db->fetchFirstColumn('SELECT sessions_left FROM customer_pack WHERE customer_id = ? ORDER BY id', [$customerId]);
        self::assertSame([5, 5], array_map(intval(...), $lefts));
    }

    public function testSoloAdminsGestionanElCatalogo(): void
    {
        // La recepcionista puede vender pero no crear bonos.
        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (1, 'Rec Bonos', 'rec.bonos@salon.es', ?, 'recepcion', 1, TRUE)",
            [password_hash('secreta123', PASSWORD_BCRYPT)]
        );
        $recToken = $this->login('rec.bonos@salon.es', 'secreta123');

        $this->client->request('POST', '/api/v1/admin/packs', server: $this->auth($recToken), content: (string) json_encode([
            'service_id' => 2,
            'name' => 'No debería',
            'sessions' => 5,
            'price' => 50,
        ]));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email = 'admin@salon.es', string $password = 'admin1234'): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => $password]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

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
