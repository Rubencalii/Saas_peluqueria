<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Marca (white-label) por cuenta (doc 08): el admin la edita y la web pública la
 * lee por subdominio.
 */
final class BrandingTest extends WebTestCase
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

    public function testEditarMarcaYLeerlaEnPublico(): void
    {
        $token = $this->login();

        // Color inválido → 400.
        $this->patch($token, ["brand_color" => "rojo"]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Edición válida.
        $this->patch($token, [
            "display_name" => "Estudio Aurora",
            "brand_color" => "#7C3AED",
            "accent_color" => "#10B981",
            "logo_url" => "https://cdn.example.com/logo.png",
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $b = json_decode((string) $this->client->getResponse()->getContent(), true)["branding"];
        self::assertSame("Estudio Aurora", $b["display_name"]);
        self::assertSame("#7C3AED", $b["brand_color"]);

        // La web pública (localhost → cuenta principal) lee la marca aplicada.
        $this->client->request("GET", "/api/v1/branding", server: ["HTTP_HOST" => "localhost"]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $pub = json_decode((string) $this->client->getResponse()->getContent(), true)["branding"];
        self::assertSame("Estudio Aurora", $pub["display_name"]);
        self::assertSame("#10B981", $pub["accent_color"]);
        self::assertSame("https://cdn.example.com/logo.png", $pub["logo_url"]);
    }

    private function login(): string
    {
        $this->client->request(
            "POST",
            "/api/v1/auth/login",
            server: ["CONTENT_TYPE" => "application/json"],
            content: (string) json_encode(["email" => "admin@salon.es", "password" => "admin1234"]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)["token"];
    }

    /** @param array<string, mixed> $body */
    private function patch(string $token, array $body): void
    {
        $this->client->request(
            "PATCH",
            "/api/v1/admin/account/branding",
            server: ["HTTP_AUTHORIZATION" => "Bearer " . $token, "CONTENT_TYPE" => "application/json"],
            content: (string) json_encode($body),
        );
    }
}
