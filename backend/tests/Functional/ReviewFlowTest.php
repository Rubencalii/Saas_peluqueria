<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Circuito de valoraciones de punta a punta: completar una cita programa el
 * mensaje con el enlace de /valorar (idempotente), el contexto público se
 * verifica por código, y una nota alta devuelve el enlace de reseñas de
 * Google de la sede (una nota baja, no).
 */
final class ReviewFlowTest extends WebTestCase
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

    /** Crea una cita real por el panel y devuelve [id, public_code]. */
    private function bookAppointment(string $token, string $phone): array
    {
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $this->client->request('GET', "/api/v1/admin/availability?location_id=1&service_id=2&date={$monday}", server: $this->auth($token));
        $offer = json_decode((string) $this->client->getResponse()->getContent(), true);
        $slot = $offer['slots'][0];

        $this->client->request('POST', '/api/v1/admin/appointments', server: $this->auth($token), content: (string) json_encode([
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => $slot['staff_id'],
            'start' => $slot['start'],
            'customer' => ['name' => 'Clienta Review', 'phone' => $phone],
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [(int) $created['appointment_id'], (string) $created['public_code']];
    }

    public function testCompletarProgramaElMensajeDeValoracionUnaSolaVez(): void
    {
        $token = $this->login();
        [$apptId] = $this->bookAppointment($token, '+34600888111');

        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE appointment_id = ? AND template_name = 'cita_valoracion'",
            [$apptId]
        );
        self::assertSame(1, $count, 'Completar programa el mensaje de valoración.');

        // Completar de nuevo no duplica el mensaje.
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$apptId}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE appointment_id = ? AND template_name = 'cita_valoracion'",
            [$apptId]
        );
        self::assertSame(1, $count);
    }

    public function testContextoYEnvioConEnlaceDeGoogleSegunNota(): void
    {
        $token = $this->login();
        $this->db->executeStatement(
            "UPDATE location SET google_review_url = 'https://g.page/r/salon-centro/review' WHERE id = 1"
        );

        // Cita completada con nota ALTA → devuelve el enlace de Google.
        [$idAlta, $codeAlta] = $this->bookAppointment($token, '+34600888222');
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$idAlta}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));

        // Contexto público verificado por código.
        $this->client->request('GET', "/api/v1/appointments/{$idAlta}/review?code={$codeAlta}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $ctx = json_decode((string) $this->client->getResponse()->getContent(), true)['appointment'];
        self::assertSame('completada', $ctx['status']);
        self::assertFalse($ctx['already_reviewed']);

        // Código erróneo → 404 (no filtra citas ajenas).
        $this->client->request('GET', "/api/v1/appointments/{$idAlta}/review?code=incorrecto");
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', "/api/v1/appointments/{$idAlta}/review", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'code' => $codeAlta,
            'rating' => 5,
            'comment' => 'Excelente corte',
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('https://g.page/r/salon-centro/review', $result['google_review_url']);

        // Segunda valoración de la misma cita → 409.
        $this->client->request('POST', "/api/v1/appointments/{$idAlta}/review", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'code' => $codeAlta,
            'rating' => 5,
        ]));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        // Cita con nota BAJA → sin invitación a Google.
        [$idBaja, $codeBaja] = $this->bookAppointment($token, '+34600888333');
        $this->client->request('PATCH', "/api/v1/admin/appointments/{$idBaja}", server: $this->auth($token), content: (string) json_encode(['status' => 'completada']));
        $this->client->request('POST', "/api/v1/appointments/{$idBaja}/review", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'code' => $codeBaja,
            'rating' => 2,
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($result['google_review_url']);
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
}
