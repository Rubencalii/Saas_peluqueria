<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Comisiones del personal (migración 0028): configuración por profesional
 * (tarifa general + excepciones por servicio) y su informe, que calcula sobre
 * las mismas citas completadas que el informe de ingresos.
 */
final class CommissionsTest extends WebTestCase
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

    public function testGuardaTarifaGeneralYExcepcionPorServicio(): void
    {
        $token = $this->login();
        $staffId = $this->anyStaffId();

        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $token, [
            'default_rate_pct' => 40,
            'by_service' => [['service_id' => 2, 'rate_pct' => 55.5]],
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Ojo: json_encode escribe 40.0 como 40, así que se compara como float.
        $saved = $this->getJson("/api/v1/admin/staff/{$staffId}/commissions", $token);
        self::assertSame(40.0, (float) $saved['default_rate_pct']);
        self::assertCount(1, $saved['by_service']);
        self::assertSame(2, $saved['by_service'][0]['service_id']);
        self::assertSame(55.5, (float) $saved['by_service'][0]['rate_pct']);

        // Guardar reemplaza: sin excepciones y sin tarifa general queda vacío.
        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $token, ['default_rate_pct' => null, 'by_service' => []]);
        $saved = $this->getJson("/api/v1/admin/staff/{$staffId}/commissions", $token);
        self::assertNull($saved['default_rate_pct']);
        self::assertSame([], $saved['by_service']);
    }

    public function testRechazaPorcentajesFueraDeRango(): void
    {
        $token = $this->login();
        $staffId = $this->anyStaffId();

        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $token, ['default_rate_pct' => 120]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $token, [
            'by_service' => [['service_id' => 2, 'rate_pct' => -1]],
        ]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testInformeAplicaLaTarifaDelServicioSobreLaGeneral(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        // Cita real del servicio 2 y la completamos (solo cuentan las completadas).
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        self::assertNotEmpty($offer['slots']);
        $slot = $offer['slots'][0];
        $staffId = (int) $slot['staff_id'];

        $this->post('/api/v1/admin/appointments', $token, [
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $staffId,
            'start' => $slot['start'],
            'customer' => ['name' => 'Comision Test', 'phone' => '+34600881001'],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $apptId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['appointment_id'];

        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $price = (float) $this->db->fetchOne(
            'SELECT COALESCE(sl.price_override, s.price) FROM service s
               LEFT JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = 1
              WHERE s.id = 2'
        );
        $range = "from={$monday}&to={$monday}";

        // Sin comisiones configuradas, el informe responde con todo a cero.
        // (json_encode escribe los float enteros sin decimales: se comparan como float.)
        $report = $this->getJson("/api/v1/admin/reports/commissions?location_id=1&{$range}", $token);
        self::assertSame(0.0, (float) $report['total_commission']);
        self::assertGreaterThanOrEqual($price, (float) $report['total_revenue']);

        // Tarifa general 10 % → se aplica al no haber excepción del servicio.
        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $token, ['default_rate_pct' => 10]);
        $report = $this->getJson("/api/v1/admin/reports/commissions?location_id=1&{$range}", $token);
        $mine = $this->rowFor($report['by_staff'], $staffId);
        self::assertSame(round((float) $mine['revenue'] * 0.10, 2), (float) $mine['commission']);

        // Excepción del servicio 2 al 50 % → manda sobre la general.
        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $token, [
            'default_rate_pct' => 10,
            'by_service' => [['service_id' => 2, 'rate_pct' => 50]],
        ]);
        $report = $this->getJson("/api/v1/admin/reports/commissions?location_id=1&{$range}", $token);
        $mine = $this->rowFor($report['by_staff'], $staffId);
        self::assertSame(round((float) $mine['revenue'] * 0.50, 2), (float) $mine['commission']);
        self::assertSame(50.0, (float) $mine['effective_rate_pct']);

        // El desglose identifica el servicio y la tarifa aplicada.
        $line = null;
        foreach ($report['detail'] as $d) {
            if ((int) $d['staff_id'] === $staffId && (int) $d['service_id'] === 2) {
                $line = $d;
            }
        }
        self::assertNotNull($line, 'El desglose debe incluir la línea del servicio cobrado.');
        self::assertSame(50.0, (float) $line['rate_pct']);

        // Una cita cancelada no genera comisión: al cancelarla, baja a cero.
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'cancelada']));
        $report = $this->getJson("/api/v1/admin/reports/commissions?location_id=1&{$range}", $token);
        self::assertSame(0.0, (float) $report['total_commission']);
    }

    public function testSoloLosAdminsTocanLasComisiones(): void
    {
        $token = $this->login();
        $staffId = $this->anyStaffId();

        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (1, 'Rec Comi', 'rec.comi@salon.es', ?, 'recepcion', 1, TRUE)",
            [password_hash('secreta123', PASSWORD_BCRYPT)]
        );
        $recToken = $this->login('rec.comi@salon.es', 'secreta123');

        $this->client->request('GET', "/api/v1/admin/staff/{$staffId}/commissions", server: $this->auth($recToken));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->post("/api/v1/admin/staff/{$staffId}/commissions", $recToken, ['default_rate_pct' => 90]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/reports/commissions', server: $this->auth($recToken));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // El admin sí, y sobre un profesional inexistente responde 404.
        $this->client->request('GET', '/api/v1/admin/staff/999999/commissions', server: $this->auth($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, int $staffId): array
    {
        foreach ($rows as $r) {
            if ((int) $r['staff_id'] === $staffId) {
                return $r;
            }
        }
        self::fail("El informe no incluye al profesional $staffId.");
    }

    private function anyStaffId(): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM staff WHERE account_id = 1 ORDER BY id LIMIT 1');
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
