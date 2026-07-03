<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gestión de usuarios del panel: alta con rol/sede, permisos (solo
 * admin_cadena), unicidad de email, desactivación (corta sesiones) y
 * autoprotección (no puedes desactivarte a ti mismo).
 */
final class AdminUserTest extends WebTestCase
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

    public function testAltaListadoYPermisosDeUsuarios(): void
    {
        $token = $this->login('admin@salon.es', 'admin1234');

        // Alta de una recepcionista en la sede 1.
        $this->client->request('POST', '/api/v1/admin/users', server: $this->auth($token), content: (string) json_encode([
            'name' => 'Recepción Test',
            'email' => 'recepcion.test@salon.es',
            'password' => 'secreta123',
            'role' => 'recepcion',
            'location_id' => 1,
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // Aparece en el listado con su sede.
        $list = $this->getJson('/api/v1/admin/users', $token);
        $emails = array_column($list['users'], 'email');
        self::assertContains('recepcion.test@salon.es', $emails);

        // La nueva usuaria puede entrar…
        $userToken = $this->login('recepcion.test@salon.es', 'secreta123');

        // …pero no puede gestionar usuarios (solo admin_cadena).
        $this->client->request('GET', '/api/v1/admin/users', server: $this->auth($userToken));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // El email es único global.
        $this->client->request('POST', '/api/v1/admin/users', server: $this->auth($token), content: (string) json_encode([
            'name' => 'Duplicada',
            'email' => 'recepcion.test@salon.es',
            'password' => 'secreta123',
            'role' => 'recepcion',
            'location_id' => 1,
        ]));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        // Un rol de sede sin sede es inválido.
        $this->client->request('POST', '/api/v1/admin/users', server: $this->auth($token), content: (string) json_encode([
            'name' => 'Sin Sede',
            'email' => 'sinsede@salon.es',
            'password' => 'secreta123',
            'role' => 'admin_sede',
        ]));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDesactivarCortaSesionesYNoPuedesDesactivarte(): void
    {
        $token = $this->login('admin@salon.es', 'admin1234');

        $this->client->request('POST', '/api/v1/admin/users', server: $this->auth($token), content: (string) json_encode([
            'name' => 'Temporal',
            'email' => 'temporal@salon.es',
            'password' => 'secreta123',
            'role' => 'profesional',
            'location_id' => 1,
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $newId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // La usuaria abre sesión…
        $userToken = $this->login('temporal@salon.es', 'secreta123');
        $this->client->request('GET', '/api/v1/admin/me', server: $this->auth($userToken));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // …el admin la desactiva → su sesión abierta queda revocada y no puede reentrar.
        $this->client->request('PATCH', "/api/v1/admin/users/{$newId}", server: $this->auth($token), content: (string) json_encode(['active' => false]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/me', server: $this->auth($userToken));
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email' => 'temporal@salon.es',
            'password' => 'secreta123',
        ]));
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // Autoprotección: el admin no puede desactivarse a sí mismo.
        $meId = (int) $this->getJson('/api/v1/admin/me', $token)['user']['id'];
        $this->client->request('PATCH', "/api/v1/admin/users/{$meId}", server: $this->auth($token), content: (string) json_encode(['active' => false]));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testNoVeNiTocaUsuariosDeOtraCuenta(): void
    {
        $token = $this->login('admin@salon.es', 'admin1234');

        $other = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra U', 'otra-u', 'active') RETURNING id"
        );
        $foreignId = (int) $this->db->fetchOne(
            "INSERT INTO app_user (account_id, name, email, password_hash, role, active)
             VALUES (?, 'Ajena', 'ajena@otra.es', 'x', 'admin_cadena', TRUE) RETURNING id",
            [$other]
        );

        $list = $this->getJson('/api/v1/admin/users', $token);
        self::assertNotContains('ajena@otra.es', array_column($list['users'], 'email'));

        $this->client->request('PATCH', "/api/v1/admin/users/{$foreignId}", server: $this->auth($token), content: (string) json_encode(['active' => false]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email, string $password): string
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
