<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Super-admin de plataforma: gestiona TODAS las cuentas (transversal a tenants).
 * Acceso por el flag is_superadmin; un admin de salón normal no puede entrar.
 */
final class SuperAdminTest extends WebTestCase
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

    public function testSuperadminGestionaCuentasYOtrosNo(): void
    {
        // Una segunda cuenta para gestionar.
        $accountId = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Cliente Demo', 'cliente-demo', 'active') RETURNING id"
        );

        $superToken = $this->login("super@plataforma.es", "super1234");

        // Lista todas las cuentas (incluida la nueva y la principal).
        $accounts = $this->getJson("/api/v1/superadmin/accounts", $superToken)["accounts"];
        $ids = array_column($accounts, "id");
        self::assertContains($accountId, $ids);
        self::assertContains(1, $ids, "Debe ver también la cuenta principal (transversal).");

        // Stats globales.
        $stats = $this->getJson("/api/v1/superadmin/stats", $superToken);
        self::assertGreaterThanOrEqual(2, $stats["accounts"]["total"]);

        // Suspende la cuenta demo y le cambia el plan.
        $this->client->request(
            "PATCH",
            "/api/v1/superadmin/accounts/" . $accountId,
            server: ["HTTP_AUTHORIZATION" => "Bearer " . $superToken, "CONTENT_TYPE" => "application/json"],
            content: (string) json_encode(["status" => "suspended", "plan_code" => "cadena"]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame("suspended", $this->db->fetchOne("SELECT status FROM account WHERE id = ?", [$accountId]));
        self::assertSame("cadena", $this->db->fetchOne("SELECT plan_code FROM subscription WHERE account_id = ?", [$accountId]));

        // Un admin de salón normal NO puede acceder al panel de plataforma.
        $adminToken = $this->login("admin@salon.es", "admin1234");
        $this->client->request("GET", "/api/v1/superadmin/accounts", server: ["HTTP_AUTHORIZATION" => "Bearer " . $adminToken]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email, string $password): string
    {
        $this->client->request(
            "POST",
            "/api/v1/auth/login",
            server: ["CONTENT_TYPE" => "application/json"],
            content: (string) json_encode(["email" => $email, "password" => $password]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)["token"];
    }

    /** @return array<string, mixed> */
    private function getJson(string $path, string $token): array
    {
        $this->client->request("GET", $path, server: ["HTTP_AUTHORIZATION" => "Bearer " . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
