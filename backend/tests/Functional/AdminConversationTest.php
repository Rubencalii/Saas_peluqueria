<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bandeja de WhatsApp del panel (docs/06 §4): el listado debe filtrar por
 * atención humana pendiente, paginar y no filtrar conversaciones de otra cuenta.
 */
final class AdminConversationTest extends WebTestCase
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

    public function testListaSoloPendientesPorDefectoYTodasConStatusAll(): void
    {
        $token = $this->login();
        $waPending = '34911100001';
        $waResolved = '34911100002';
        $this->db->executeStatement(
            "INSERT INTO conversation (wa_id, state, needs_human) VALUES (?, 'human', TRUE)",
            [$waPending]
        );
        $this->db->executeStatement(
            "INSERT INTO conversation (wa_id, state, needs_human) VALUES (?, 'menu', FALSE)",
            [$waResolved]
        );

        // Por defecto solo las que esperan atención humana.
        $pending = $this->getJson('/api/v1/admin/conversations?per_page=100', $token);
        $pendingIds = array_column($pending['conversations'], 'wa_id');
        self::assertContains($waPending, $pendingIds);
        self::assertNotContains($waResolved, $pendingIds);

        // status=all incluye también las ya resueltas.
        $all = $this->getJson('/api/v1/admin/conversations?status=all&per_page=100', $token);
        $allIds = array_column($all['conversations'], 'wa_id');
        self::assertContains($waPending, $allIds);
        self::assertContains($waResolved, $allIds);
    }

    public function testPaginacionDeConversaciones(): void
    {
        $token = $this->login();

        // Línea base: total actual (independiente del seed) con status=all.
        $baseline = $this->getJson('/api/v1/admin/conversations?status=all&per_page=100', $token)['total'];

        // 7 conversaciones nuevas sin sede (visibles para cualquier cuenta).
        for ($i = 0; $i < 7; $i++) {
            $this->db->executeStatement(
                "INSERT INTO conversation (wa_id, state, needs_human) VALUES (?, 'menu', TRUE)",
                ['3490000000' . $i]
            );
        }

        $p1 = $this->getJson('/api/v1/admin/conversations?status=all&per_page=5&page=1', $token);
        self::assertSame($baseline + 7, $p1['total']);
        self::assertSame(5, $p1['per_page']);
        self::assertSame(1, $p1['page']);
        self::assertCount(5, $p1['conversations']); // total ≥ 7, la primera página va llena

        $p2 = $this->getJson('/api/v1/admin/conversations?status=all&per_page=5&page=2', $token);
        self::assertSame(2, $p2['page']);
        self::assertNotEmpty($p2['conversations']);
    }

    public function testNoFiltraConversacionesDeOtraCuenta(): void
    {
        $token = $this->login();

        $other = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra WA', 'otra-wa', 'active') RETURNING id"
        );
        $loc = (int) $this->db->fetchOne(
            "INSERT INTO location (account_id, name, slug, timezone, active)
             VALUES (?, 'Ajena WA', 'ajena-wa', 'Europe/Madrid', TRUE) RETURNING id",
            [$other]
        );
        $waAjena = '34922200001';
        $this->db->executeStatement(
            "INSERT INTO conversation (wa_id, state, needs_human, location_id) VALUES (?, 'human', TRUE, ?)",
            [$waAjena, $loc]
        );

        $all = $this->getJson('/api/v1/admin/conversations?status=all&per_page=100', $token);
        self::assertNotContains($waAjena, array_column($all['conversations'], 'wa_id'));
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

    /** @return array<string, mixed> */
    private function getJson(string $path, string $token): array
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
