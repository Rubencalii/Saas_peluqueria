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

    public function testLosPrepagosVendidosSumanAlCajonSegunSuFormaDePago(): void
    {
        // Recepción vende desde la sede 1, así que la venta es de ese cajón.
        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (1, 'Rec Prepago', 'rec.prepago@salon.es', ?, 'recepcion', 1, TRUE)",
            [password_hash('secreta123', PASSWORD_BCRYPT)]
        );
        $token = $this->login('rec.prepago@salon.es', 'secreta123');
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d');

        $antes = (float) $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$hoy}", $token)['expected_cash'];

        // Tarjeta regalo de 50 € en efectivo: entra en el cajón.
        $this->post('/api/v1/admin/gift-cards', $token, ['amount' => 50, 'payment_method' => 'efectivo']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // Otra de 30 € con tarjeta: no entra en el cajón, pero sí en el listado.
        $this->post('/api/v1/admin/gift-cards', $token, ['amount' => 30, 'payment_method' => 'tarjeta']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $tarjetaId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$hoy}", $token);
        self::assertSame(round($antes + 50, 2), round((float) $caja['expected_cash'], 2));
        self::assertSame(80.0, round((float) $caja['prepaid_total'], 2));
        self::assertSame(30.0, round((float) $caja['prepaid_by_method']['tarjeta']['amount'], 2));

        // Corregir la forma de pago desde caja recalcula el cajón.
        $this->client->request('PATCH', '/api/v1/admin/cash/prepaid', server: $this->auth($token), content: (string) json_encode([
            'kind' => 'gift_card',
            'id' => $tarjetaId,
            'payment_method' => 'efectivo',
        ]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$hoy}", $token);
        self::assertSame(round($antes + 80, 2), round((float) $caja['expected_cash'], 2));

        // Una venta de otra cuenta no puede tocarse aunque se acierte el id.
        $otra = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra Caja', 'otra-caja', 'active') RETURNING id"
        );
        $ajena = (int) $this->db->fetchOne(
            "INSERT INTO gift_card (account_id, code, initial_amount, balance) VALUES (?, 'GIFT-ZZZZ-ZZZZ', 20, 20) RETURNING id",
            [$otra]
        );
        $this->client->request('PATCH', '/api/v1/admin/cash/prepaid', server: $this->auth($token), content: (string) json_encode([
            'kind' => 'gift_card',
            'id' => $ajena,
            'payment_method' => 'efectivo',
        ]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
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

    public function testLasEntradasYGastosMuevenElEfectivoEsperado(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        [$appt, $precio] = $this->citaCompletada($token, $monday, '+34600991006');
        $this->cobrar($token, $appt, 'efectivo');

        // Fondo de cambio: entra en el cajón.
        $this->post('/api/v1/admin/cash/movements', $token, [
            'location_id' => 1, 'date' => $monday,
            'kind' => 'entrada', 'amount' => 50, 'concept' => 'Fondo de cambio',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // Gasto en metálico: sale del cajón.
        $this->post('/api/v1/admin/cash/movements', $token, [
            'location_id' => 1, 'date' => $monday,
            'kind' => 'gasto', 'amount' => 12.5, 'concept' => 'Mensajero',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $gastoId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$monday}", $token);
        self::assertSame(round($precio + 50 - 12.5, 2), round((float) $caja['expected_cash'], 2));
        self::assertSame(37.5, round((float) $caja['movements_net'], 2));
        self::assertCount(2, $caja['movements']);

        // El cierre usa el mismo esperado que la pantalla.
        $this->post('/api/v1/admin/cash/close', $token, [
            'location_id' => 1, 'date' => $monday, 'counted_cash' => $precio + 50 - 12.5,
        ]);
        $cierre = json_decode((string) $this->client->getResponse()->getContent(), true)['close'];
        self::assertSame(0.0, round((float) $cierre['difference'], 2));

        // Borrar el gasto lo devuelve al cajón.
        $this->client->request('DELETE', "/api/v1/admin/cash/movements/{$gastoId}", server: $this->auth($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $caja = $this->getJson("/api/v1/admin/cash/day?location_id=1&date={$monday}", $token);
        self::assertSame(round($precio + 50, 2), round((float) $caja['expected_cash'], 2));

        // Validaciones: importe y concepto son obligatorios, kind acotado.
        foreach ([
            ['kind' => 'gasto', 'amount' => 0, 'concept' => 'Nada'],
            ['kind' => 'gasto', 'amount' => 5, 'concept' => '  '],
            ['kind' => 'traspaso', 'amount' => 5, 'concept' => 'Otro'],
        ] as $malo) {
            $this->post('/api/v1/admin/cash/movements', $token, ['location_id' => 1, 'date' => $monday] + $malo);
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), (string) json_encode($malo));
        }

        // Un movimiento de otra cuenta no se borra.
        $otra = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra Mov', 'otra-mov', 'active') RETURNING id"
        );
        $locAjena = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone, active)
             VALUES (?, 'Ajena Mov', 'ajena-mov', 'Europe/Madrid', TRUE) RETURNING id",
            [$otra]
        );
        $ajeno = (int) $this->db->fetchOne(
            "INSERT INTO cash_movement (account_id, location_id, business_date, kind, amount, concept)
             VALUES (?, ?, ?, 'gasto', 9, 'Ajeno') RETURNING id",
            [$otra, $locAjena, $monday]
        );
        $this->client->request('DELETE', "/api/v1/admin/cash/movements/{$ajeno}", server: $this->auth($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testElHistoricoResumeLosDescuadres(): void
    {
        $token = $this->login();
        $hoy = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
        $ayer = $hoy->modify('-1 day')->format('Y-m-d');
        $anteayer = $hoy->modify('-2 days')->format('Y-m-d');

        // Dos cierres: uno cuadrado y otro al que le faltan 5 €.
        $this->post('/api/v1/admin/cash/close', $token, ['location_id' => 1, 'date' => $anteayer, 'counted_cash' => 0]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Se fuerza el descuadre en BD: montar 100 € de citas reales solo para
        // esto no aporta nada al caso que se prueba (el resumen del histórico).
        $this->db->executeStatement(
            'UPDATE cash_close SET expected_cash = 100, counted_cash = 95 WHERE location_id = 1 AND business_date = ?',
            [$anteayer]
        );
        $this->post('/api/v1/admin/cash/close', $token, ['location_id' => 1, 'date' => $ayer, 'counted_cash' => 0]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $hist = $this->getJson('/api/v1/admin/cash/closes?location_id=1', $token);
        $fechas = array_column($hist['closes'], 'date');
        self::assertContains($anteayer, $fechas);
        self::assertContains($ayer, $fechas);
        // Más reciente primero.
        self::assertTrue(array_search($ayer, $fechas, true) < array_search($anteayer, $fechas, true));

        $desc = null;
        foreach ($hist['closes'] as $c) {
            if ($c['date'] === $anteayer) {
                $desc = $c;
            }
        }
        self::assertNotNull($desc);
        self::assertSame(-5.0, round((float) $desc['difference'], 2));
        self::assertSame(-5.0, round((float) $hist['total_difference'], 2));
        self::assertSame(1, (int) $hist['days_with_difference']);

        // Fuera del rango pedido no aparece.
        $vacio = $this->getJson("/api/v1/admin/cash/closes?location_id=1&from={$hoy->format('Y-m-d')}&to={$hoy->format('Y-m-d')}", $token);
        self::assertNotContains($anteayer, array_column($vacio['closes'], 'date'));

        // Rango invertido → 400.
        $this->client->request('GET', "/api/v1/admin/cash/closes?location_id=1&from={$ayer}&to={$anteayer}", server: $this->auth($token));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
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
