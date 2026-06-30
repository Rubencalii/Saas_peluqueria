<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Comando de avisos de lista de espera (doc 13 §2.4): avisa cuando hay hueco
 * para el servicio del cliente y marca 'avisado' (sin reavisar), respeta el
 * --dry-run y no avisa si no hay disponibilidad. WhatsAppMessenger hace no-op
 * en test (sin credenciales), así que se prueba el flujo completo sin enviar.
 */
final class WaitlistNotifyCommandTest extends KernelTestCase
{
    private Connection $db;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        /** @var Connection $db */
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        $this->db->setNestTransactionsWithSavepoints(true);
        $this->db->beginTransaction();

        $command = (new Application($kernel))->find('app:waitlist:notify');
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testAvisaCuandoHayHuecoYMarcaAvisadoSinReavisar(): void
    {
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $id = $this->joinWaiting('Espera Uno', '+34655200201', 2, $monday);

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        $row = $this->db->fetchAssociative('SELECT status, notified_at FROM waitlist WHERE id = ?', [$id]);
        self::assertSame('avisado', $row['status']);
        self::assertNotNull($row['notified_at']);

        // Segunda pasada: ya no está 'esperando', no se vuelve a avisar ni a tocar.
        $this->tester->execute([]);
        self::assertSame(
            $row['notified_at'],
            $this->db->fetchOne('SELECT notified_at FROM waitlist WHERE id = ?', [$id])
        );
    }

    public function testDryRunDetectaPeroNoMarca(): void
    {
        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $id = $this->joinWaiting('Espera Dry', '+34655200202', 2, $monday);

        $this->tester->execute(['--dry-run' => true]);
        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('[DRY]', $this->tester->getDisplay());

        self::assertSame('esperando', $this->db->fetchOne('SELECT status FROM waitlist WHERE id = ?', [$id]));
    }

    public function testSinHuecoSeQuedaEsperando(): void
    {
        // Servicio ofertado en la sede 1 pero sin profesional que lo haga: la
        // disponibilidad será siempre vacía, así que no debe avisarse a nadie.
        $svc = (int) $this->db->fetchOne(
            "INSERT INTO service (account_id, name, duration_min, buffer_min, price, active)
             VALUES (1, 'Servicio Sin Personal', 30, 0, 10, TRUE) RETURNING id"
        );
        $this->db->executeStatement(
            'INSERT INTO service_segment (service_id, position, minutes, busy) VALUES (?, 1, 30, TRUE)',
            [$svc]
        );
        $this->db->executeStatement('INSERT INTO service_location (service_id, location_id) VALUES (?, 1)', [$svc]);

        $monday = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $id = $this->joinWaiting('Espera Sin Hueco', '+34655200203', $svc, $monday);

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        self::assertSame('esperando', $this->db->fetchOne('SELECT status FROM waitlist WHERE id = ?', [$id]));
    }

    private function joinWaiting(string $name, string $phone, int $serviceId, ?string $desiredDate): int
    {
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (account_id, name, phone, wa_consent, consent_at)
             VALUES (1, ?, ?, TRUE, now()) RETURNING id",
            [$name, $phone]
        );

        return (int) $this->db->fetchOne(
            "INSERT INTO waitlist (location_id, service_id, staff_id, customer_id, desired_date, status)
             VALUES (1, ?, NULL, ?, ?, 'esperando') RETURNING id",
            [$serviceId, $customerId, $desiredDate]
        );
    }
}
