<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cobertura HTTP de los controladores del panel que no tenían test propio:
 * agenda (día/semana y aislamiento), personal + horario semanal, bloqueos de
 * agenda (crear/listar/borrar y su efecto en la disponibilidad) e informes
 * (respuestas y autorización por rol).
 */
final class AdminCoverageTest extends WebTestCase
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

    public function testAgendaMuestraLaCitaEnDiaYSemana(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        // Cita real en un hueco ofertado.
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        $slot = $offer['slots'][0];
        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode([
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'customer' => ['name' => 'Agenda Cover', 'phone' => '+34600771001'],
        ]));
        $apptId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['appointment_id'];

        // Vista día: la cita está, con servicio y cliente legibles.
        $day = $this->getJson("/api/v1/admin/agenda?location_id=1&date={$monday}&view=day", $token);
        $ids = array_column($day['appointments'], 'appointment_id');
        self::assertContains($apptId, $ids);
        self::assertSame('day', $day['view']);
        self::assertSame(1, $day['location']['id']);

        // Vista semana (anclada a cualquier día de esa semana): también está.
        $week = $this->getJson("/api/v1/admin/agenda?location_id=1&date={$monday}&view=week", $token);
        self::assertContains($apptId, array_column($week['appointments'], 'appointment_id'));

        // La sede de otra cuenta no es visible (404, sin filtrar existencia).
        $other = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra Ag', 'otra-ag', 'active') RETURNING id"
        );
        $foreignLoc = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone, active)
             VALUES (?, 'Ajena Ag', 'ajena-ag', 'Europe/Madrid', TRUE) RETURNING id",
            [$other]
        );
        $this->client->request('GET', "/api/v1/admin/agenda?location_id={$foreignLoc}&date={$monday}", server: $this->auth($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testPersonalConHorarioApareceEnDisponibilidad(): void
    {
        $token = $this->login();

        // Alta de profesional en la sede 1 haciendo el servicio 2.
        $this->client->request('POST', '/api/v1/admin/staff', server: $this->auth($token), content: (string) json_encode([
            'name' => 'Pro Cover',
            'location_ids' => [1],
            'service_ids' => [2],
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $staffId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Horario semanal: lunes 09:00-13:00 en la sede 1.
        $this->client->request('POST', "/api/v1/admin/staff/{$staffId}/schedule", server: $this->auth($token), content: (string) json_encode([
            'location_id' => 1,
            'entries' => [['weekday' => 0, 'start_time' => '09:00', 'end_time' => '13:00']],
        ]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $schedule = $this->getJson("/api/v1/admin/staff/{$staffId}/schedule", $token)['schedule'];
        self::assertCount(1, $schedule);
        self::assertSame(0, $schedule[0]['weekday']);

        // Con horario, la disponibilidad del lunes le ofrece huecos.
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $offer = $this->getJson(
            "/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}&staff_id={$staffId}",
            $token
        );
        self::assertNotEmpty($offer['slots'], 'El profesional nuevo con horario debe ofrecer huecos.');

        // La recepcionista NO puede dar de alta personal (solo admins).
        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (1, 'Rec Cover', 'rec.cover@salon.es', ?, 'recepcion', 1, TRUE)",
            [password_hash('secreta123', PASSWORD_BCRYPT)]
        );
        $recToken = $this->login('rec.cover@salon.es', 'secreta123');
        $this->client->request('POST', '/api/v1/admin/staff', server: $this->auth($recToken), content: (string) json_encode(['name' => 'No Debe']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testBloqueoQuitaHuecosYSePuedeBorrar(): void
    {
        $token = $this->login();
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');

        // Disponibilidad del lunes de un profesional concreto de la sede 1.
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        self::assertNotEmpty($offer['slots']);
        $staffId = (int) $offer['slots'][0]['staff_id'];
        $before = count(
            array_filter($offer['slots'], static fn (array $s): bool => (int) $s['staff_id'] === $staffId)
        );

        // Bloqueo de TODO el día para ese profesional.
        $this->client->request('POST', '/api/v1/admin/time-blocks', server: $this->auth($token), content: (string) json_encode([
            'staff_id' => $staffId,
            'location_id' => 1,
            'start' => $monday . 'T00:00:00+00:00',
            'end' => $monday . 'T23:59:00+00:00',
            'reason' => 'Vacaciones cover',
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $blockId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Aparece en el listado del rango (to es exclusivo: pedimos hasta el martes)…
        $tuesday = (new \DateTimeImmutable($monday))->modify('+1 day')->format('Y-m-d');
        $list = $this->getJson("/api/v1/admin/time-blocks?from={$monday}&to={$tuesday}", $token)['time_blocks'];
        self::assertContains($blockId, array_column($list, 'id'));

        // …y la disponibilidad de ese profesional desaparece.
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        $after = count(
            array_filter($offer['slots'], static fn (array $s): bool => (int) $s['staff_id'] === $staffId)
        );
        self::assertSame(0, $after);
        self::assertGreaterThan($after, $before);

        // Borrar el bloqueo restaura los huecos.
        $this->client->request('DELETE', "/api/v1/admin/time-blocks/{$blockId}", server: $this->auth($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $offer = $this->getJson("/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", $token);
        $restored = count(
            array_filter($offer['slots'], static fn (array $s): bool => (int) $s['staff_id'] === $staffId)
        );
        self::assertSame($before, $restored);
    }

    public function testInformesRespondenYExigenRolDeAdmin(): void
    {
        $token = $this->login();

        // Cada informe responde 200 con su forma mínima.
        $shapes = [
            '/api/v1/admin/reports/revenue' => 'total_revenue',
            '/api/v1/admin/reports/bookings-by-channel' => 'by_channel',
            '/api/v1/admin/reports/no-shows' => 'no_show_rate',
            '/api/v1/admin/reports/retention' => 'retention_rate',
            '/api/v1/admin/reports/ratings' => 'average',
            '/api/v1/admin/reports/peak-hours?location_id=1' => 'slots',
            '/api/v1/admin/reports/occupancy?location_id=1' => 'occupancy_rate',
            '/api/v1/admin/reports/no-show-customers' => 'customers',
        ];
        foreach ($shapes as $path => $key) {
            $data = $this->getJson($path, $token);
            self::assertArrayHasKey($key, $data, $path);
        }

        // Ocupación sin sede → 400 (la capacidad depende de la sede).
        $this->client->request('GET', '/api/v1/admin/reports/occupancy', server: $this->auth($token));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // El rol profesional no ve informes.
        $this->db->executeStatement(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (1, 'Pro Informes', 'pro.informes@salon.es', ?, 'profesional', 1, TRUE)",
            [password_hash('secreta123', PASSWORD_BCRYPT)]
        );
        $proToken = $this->login('pro.informes@salon.es', 'secreta123');
        $this->client->request('GET', '/api/v1/admin/reports/revenue', server: $this->auth($proToken));
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
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
