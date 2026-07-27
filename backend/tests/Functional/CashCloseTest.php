<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Caja del día (migración 0029): forma de pago por cita, totales por método y
 * arqueo al cerrar (esperado vs contado).
 */
final class CashCloseTest extends WebTestCase
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

    public function testLaCajaSumaPorFormaDePagoYDestacaLoSinCobrar(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        [$apptA, $precio] = $this->citaCompletada($token, $monday, '+34600991001');
        [$apptB] = $this->citaCompletada($token, $monday, '+34600991002');

        // Recién completadas, ninguna tiene forma de pago: van a "sin registrar".
        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$monday}", $token);
        self::assertSame(2, $caja['by_method']['sin_registrar']['count']);
        self::assertSame(0.0, (float) $caja['expected_cash']);
        self::assertSame(round($precio * 2, 2), round((float) $caja['total'], 2));
        self::assertNull($caja['close']);

        // Se cobran: una en efectivo y otra con tarjeta.
        $this->cobrar($token, $apptA, 'efectivo');
        $this->cobrar($token, $apptB, 'tarjeta');

        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$monday}", $token);
        self::assertSame(0, $caja['by_method']['sin_registrar']['count']);
        self::assertSame($precio, round((float) $caja['by_method']['efectivo']['amount'], 2));
        self::assertSame($precio, round((float) $caja['by_method']['tarjeta']['amount'], 2));
        // Solo el efectivo tiene que aparecer en el cajón.
        self::assertSame($precio, round((float) $caja['expected_cash'], 2));

        // La línea de la cita lleva su método, para poder revisarlo en pantalla.
        $linea = null;
        foreach ($caja['appointments'] as $l) {
            if ((int) $l['appointment_id'] === $apptA) {
                $linea = $l;
            }
        }
        self::assertNotNull($linea);
        self::assertSame('efectivo', $linea['payment_method']);
    }

    public function testElCierreGuardaElDescuadreYSePuedeRehacer(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        [$appt, $precio] = $this->citaCompletada($token, $monday, '+34600991003');
        $this->cobrar($token, $appt, 'efectivo');

        // Falta un euro en el cajón: el descuadre queda registrado.
        $this->post('/api/v1/admin/cash/close', $token, [
            'location_id' => 1,
            'date' => $monday,
            'counted_cash' => $precio - 1,
            'notes' => 'Falta un euro',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $cierre = json_decode((string) $this->client->getResponse()->getContent(), true)['close'];
        self::assertSame($precio, round((float) $cierre['expected_cash'], 2));
        self::assertSame(-1.0, round((float) $cierre['difference'], 2));
        self::assertSame('Falta un euro', $cierre['notes']);

        // Volver a cerrar el mismo día actualiza en vez de duplicar.
        $this->post('/api/v1/admin/cash/close', $token, [
            'location_id' => 1,
            'date' => $monday,
            'counted_cash' => $precio,
        ]);
        $cierre = json_decode((string) $this->client->getResponse()->getContent(), true)['close'];
        self::assertSame(0.0, round((float) $cierre['difference'], 2));
        self::assertNull($cierre['notes']);
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM cash_close WHERE location_id = 1 AND business_date = ?',
            [$monday]
        ));

        // Y el día lo devuelve ya cerrado.
        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$monday}", $token);
        self::assertNotNull($caja['close']);
        self::assertSame('Admin', explode(' ', (string) $caja['close']['closed_by_name'])[0]);
    }

    public function testElEsperadoNoSeFiaDelCliente(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        [$appt, $precio] = $this->citaCompletada($token, $monday, '+34600991004');
        $this->cobrar($token, $appt, 'efectivo');

        // Aunque manden un expected_cash inventado, se recalcula en el servidor.
        $this->post('/api/v1/admin/cash/close', $token, [
            'location_id' => 1,
            'date' => $monday,
            'counted_cash' => $precio,
            'expected_cash' => 99999,
        ]);
        $cierre = json_decode((string) $this->client->getResponse()->getContent(), true)['close'];
        self::assertSame($precio, round((float) $cierre['expected_cash'], 2));
    }

    public function testValidaFechaFormaDePagoYPermisos(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        [$appt] = $this->citaCompletada($token, $monday, '+34600991005');

        $this->client->request('GET', '/api/v1/admin/cash/day?location_id=1&date=31-07-2026', server: $this->auth($token));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->client->request('PATCH', "/api/v1/admin/appointments/{$appt}", server: $this->auth($token), content: (string) json_encode(['payment_method' => 'bizum']));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Cerrar sin decir cuánto hay contado no vale.
        $this->post('/api/v1/admin/cash/close', $token, ['location_id' => 1, 'date' => $monday]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Recepción sí lleva la caja; el profesional no.
        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (1, 'Rec Caja', 'rec.caja@salon.es', ?, 'recepcion', 1, TRUE),
                    (1, 'Pro Caja', 'pro.caja@salon.es', ?, 'profesional', 1, TRUE)",
            [password_hash('secreta123', PASSWORD_BCRYPT), password_hash('secreta123', PASSWORD_BCRYPT)]
        );
        $this->client->request('GET', "/api/v1/admin/cash/day?location_id=1&date={$monday}", server: $this->auth($this->login('rec.caja@salon.es', 'secreta123')));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', "/api/v1/admin/cash/day?location_id=1&date={$monday}", server: $this->auth($this->login('pro.caja@salon.es', 'secreta123')));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Crea una cita del servicio 2 el día indicado y la completa.
     *
     * @return array{0: int, 1: float} id y precio vigente
     */
    private function citaCompletada(string $token, string $date, string $phone): array
    {
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$date}", $token);
        self::assertNotEmpty($offer['slots'], 'El seed debe ofrecer huecos ese día.');
        $slot = $offer['slots'][0];

        $this->post('/api/v1/admin/appointments', $token, [
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'customer' => ['name' => 'Caja Test', 'phone' => $phone],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['appointment_id'];

        $this->client->request('PATCH', "/api/v1/admin/appointments/{$id}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $price = (float) $this->db->fetchOne(
            'SELECT COALESCE(sl.price_override, s.price, 0) FROM service s
               LEFT JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = 1
              WHERE s.id = 2'
        );

        return [$id, round($price, 2)];
    }

    private function cobrar(string $token, int $appointmentId, string $method): void
    {
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$appointmentId}", server: $this->auth($token), content: (string) json_encode(['payment_method' => $method]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
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

    /** @param array<string, mixed> $body */
    private function post(string $path, string $token, array $body): void
    {
        $this->client->request('POST', $path, server: $this->auth($token), content: (string) json_encode($body));
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
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
