<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tarjetas regalo: venta con código legible, consulta por código (tolerante a
 * cómo se teclee), canje parcial con libro de movimientos, saldo insuficiente,
 * caducidad y aislamiento entre cuentas.
 */
final class AdminGiftCardTest extends WebTestCase
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

    public function testVentaConsultaYCanjeParcial(): void
    {
        $token = $this->login();

        // Venta de una tarjeta de 50 € para "Marta".
        $this->client->request('POST', '/api/v1/admin/gift-cards', server: $this->auth($token), content: (string) json_encode([
            'amount' => 50,
            'recipient_name' => 'Marta',
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $code = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['code'];
        self::assertMatchesRegularExpression('/^GIFT-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        // Consulta tolerante al tecleo (minúsculas y sin guiones).
        $sloppy = strtolower(str_replace('-', ' ', $code));
        $card = $this->getJson('/api/v1/admin/gift-cards/' . rawurlencode($sloppy), $token)['gift_card'];
        self::assertSame(50.0, (float) $card['balance']);
        self::assertSame('Marta', $card['recipient_name']);

        // Canje parcial de 18 € → quedan 32 €.
        $this->client->request('POST', "/api/v1/admin/gift-cards/{$code}/redeem", server: $this->auth($token), content: (string) json_encode(['amount' => 18]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(32.0, (float) json_decode((string) $this->client->getResponse()->getContent(), true)['balance']);

        // Segundo canje de 32 € → 0, y el historial refleja ambos.
        $this->client->request('POST', "/api/v1/admin/gift-cards/{$code}/redeem", server: $this->auth($token), content: (string) json_encode(['amount' => 32]));
        self::assertSame(0.0, (float) json_decode((string) $this->client->getResponse()->getContent(), true)['balance']);
        $card = $this->getJson("/api/v1/admin/gift-cards/{$code}", $token)['gift_card'];
        self::assertCount(2, $card['redemptions']);

        // Sin saldo: canjear 1 € más falla con 409.
        $this->client->request('POST', "/api/v1/admin/gift-cards/{$code}/redeem", server: $this->auth($token), content: (string) json_encode(['amount' => 1]));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testCaducidadYValidaciones(): void
    {
        $token = $this->login();

        // Importe inválido.
        $this->client->request('POST', '/api/v1/admin/gift-cards', server: $this->auth($token), content: (string) json_encode(['amount' => 0]));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Tarjeta caducada: el canje se rechaza.
        $this->db->executeStatement(
            "INSERT INTO gift_card (account_id, code, initial_amount, balance, expires_at)
             VALUES (1, 'GIFT-TEST-CADU', 30, 30, now() - interval '1 day')"
        );
        $this->client->request('POST', '/api/v1/admin/gift-cards/GIFT-TEST-CADU/redeem', server: $this->auth($token), content: (string) json_encode(['amount' => 10]));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('EXPIRED', (string) $this->client->getResponse()->getContent());
    }

    public function testNoVeTarjetasDeOtraCuenta(): void
    {
        $token = $this->login();

        $other = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Otra GC', 'otra-gc', 'active') RETURNING id"
        );
        $this->db->executeStatement(
            "INSERT INTO gift_card (account_id, code, initial_amount, balance) VALUES (?, 'GIFT-AJEN-AAAA', 40, 40)",
            [$other]
        );

        $this->client->request('GET', '/api/v1/admin/gift-cards/GIFT-AJEN-AAAA', server: $this->auth($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/admin/gift-cards/GIFT-AJEN-AAAA/redeem', server: $this->auth($token), content: (string) json_encode(['amount' => 10]));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
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

    /** @return array<string, mixed> */
    private function getJson(string $path, string $token): array
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
