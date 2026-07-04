<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Purga de datos técnicos caducados (doc 09): borra lo caducado, conserva lo
 * vigente y el --dry-run no toca nada.
 */
final class MaintenancePurgeCommandTest extends KernelTestCase
{
    private Connection $db;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        /** @var Connection $db */
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        $this->db->beginTransaction();

        $command = (new Application($kernel))->find('app:maintenance:purge');
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    /**
     * @return array{reset_old: int, reset_ok: int, audit_old: int, audit_ok: int}
     */
    private function seedRows(): array
    {
        $userId = (int) $this->db->fetchOne("SELECT id FROM app_user WHERE email = 'admin@salon.es'");

        // Reset caducado y reset vigente.
        $resetOld = (int) $this->db->fetchOne(
            "INSERT INTO password_reset (user_id, token_hash, expires_at) VALUES (?, 'h1', now() - interval '1 day') RETURNING id",
            [$userId]
        );
        $resetOk = (int) $this->db->fetchOne(
            "INSERT INTO password_reset (user_id, token_hash, expires_at) VALUES (?, 'h2', now() + interval '1 hour') RETURNING id",
            [$userId]
        );

        // Auditoría vieja (2 años) y reciente.
        $auditOld = (int) $this->db->fetchOne(
            "INSERT INTO audit_log (user_id, user_email, method, path, status_code, created_at)
             VALUES (NULL, 'x@y.es', 'GET', '/vieja', 200, now() - interval '2 years') RETURNING id"
        );
        $auditOk = (int) $this->db->fetchOne(
            "INSERT INTO audit_log (user_id, user_email, method, path, status_code)
             VALUES (NULL, 'x@y.es', 'GET', '/reciente', 200) RETURNING id"
        );

        // Dedupe de WhatsApp viejo y reciente.
        $this->db->executeStatement(
            "INSERT INTO wa_processed_message (message_id, received_at) VALUES ('msg-vieja', now() - interval '60 days')"
        );
        $this->db->executeStatement(
            "INSERT INTO wa_processed_message (message_id) VALUES ('msg-reciente')"
        );

        return ['reset_old' => $resetOld, 'reset_ok' => $resetOk, 'audit_old' => $auditOld, 'audit_ok' => $auditOk];
    }

    public function testPurgaBorraLoCaducadoYConservaLoVigente(): void
    {
        $ids = $this->seedRows();

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        self::assertFalse($this->db->fetchOne('SELECT 1 FROM password_reset WHERE id = ?', [$ids['reset_old']]));
        self::assertNotFalse($this->db->fetchOne('SELECT 1 FROM password_reset WHERE id = ?', [$ids['reset_ok']]));

        self::assertFalse($this->db->fetchOne('SELECT 1 FROM audit_log WHERE id = ?', [$ids['audit_old']]));
        self::assertNotFalse($this->db->fetchOne('SELECT 1 FROM audit_log WHERE id = ?', [$ids['audit_ok']]));

        self::assertFalse($this->db->fetchOne("SELECT 1 FROM wa_processed_message WHERE message_id = 'msg-vieja'"));
        self::assertNotFalse($this->db->fetchOne("SELECT 1 FROM wa_processed_message WHERE message_id = 'msg-reciente'"));
    }

    public function testDryRunCuentaPeroNoBorra(): void
    {
        $ids = $this->seedRows();

        $this->tester->execute(['--dry-run' => true]);
        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('[DRY]', $this->tester->getDisplay());

        // Todo sigue ahí.
        self::assertNotFalse($this->db->fetchOne('SELECT 1 FROM password_reset WHERE id = ?', [$ids['reset_old']]));
        self::assertNotFalse($this->db->fetchOne('SELECT 1 FROM audit_log WHERE id = ?', [$ids['audit_old']]));
        self::assertNotFalse($this->db->fetchOne("SELECT 1 FROM wa_processed_message WHERE message_id = 'msg-vieja'"));
    }

    public function testRetencionConfigurable(): void
    {
        // Con --audit-days=1000, la fila de hace 2 años NO se borra todavía.
        $ids = $this->seedRows();

        $this->tester->execute(['--audit-days' => '1000']);
        $this->tester->assertCommandIsSuccessful();

        self::assertNotFalse($this->db->fetchOne('SELECT 1 FROM audit_log WHERE id = ?', [$ids['audit_old']]));
    }
}
