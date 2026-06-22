<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Gdpr\GdprService;

/**
 * Derechos RGPD sobre datos de cliente (doc 09 §5).
 */
final class GdprServiceTest extends DatabaseTestCase
{
    private GdprService $gdpr;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var GdprService $svc */
        $svc = $this->service(GdprService::class);
        $this->gdpr = $svc;
    }

    private function nuevoCliente(): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO customer (name, phone, email, wa_consent, consent_at)
             VALUES ('RGPD Test', '+34699111000', 'rgpd@test.es', TRUE, now()) RETURNING id"
        );
    }

    public function testExportDevuelveLosDatos(): void
    {
        $id = $this->nuevoCliente();
        $data = $this->gdpr->export($id);

        self::assertNotNull($data);
        self::assertSame('RGPD Test', $data['customer']['name']);
        self::assertArrayHasKey('appointments', $data);
        self::assertArrayHasKey('payments', $data);
        self::assertArrayHasKey('waitlist', $data);
    }

    public function testExportClienteInexistenteDevuelveNull(): void
    {
        self::assertNull($this->gdpr->export(999999));
    }

    public function testAnonimizarBorraLaPii(): void
    {
        $id = $this->nuevoCliente();

        self::assertTrue($this->gdpr->anonymize($id));

        $row = $this->db->fetchAssociative('SELECT name, phone, email, wa_consent FROM customer WHERE id = ?', [$id]);
        self::assertSame('Cliente anonimizado', $row['name']);
        self::assertSame('anon:' . $id, $row['phone']);
        self::assertNull($row['email']);
        self::assertFalse((bool) $row['wa_consent']);
    }

    public function testAnonimizarInexistenteDevuelveFalse(): void
    {
        self::assertFalse($this->gdpr->anonymize(999999));
    }
}
