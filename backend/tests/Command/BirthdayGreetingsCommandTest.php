<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Felicitación de cumpleaños: felicita a quien cumple HOY (con consentimiento),
 * es idempotente por año, y no toca a quien cumple otro día, no tiene
 * consentimiento o ya fue felicitado este año.
 */
final class BirthdayGreetingsCommandTest extends KernelTestCase
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

        $command = (new Application($kernel))->find('app:notifications:birthdays');
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function customer(string $name, string $phone, string $birthdaySql, bool $consent, ?string $greetedSql = null): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO customer (account_id, name, phone, wa_consent, consent_at, birthday, birthday_greeted_on)
             VALUES (1, ?, ?, ?, CASE WHEN ? THEN now() END, {$birthdaySql}, " . ($greetedSql ?? 'NULL') . ')
             RETURNING id',
            [$name, $phone, $consent, $consent],
            [2 => \Doctrine\DBAL\ParameterType::BOOLEAN, 3 => \Doctrine\DBAL\ParameterType::BOOLEAN]
        );
    }

    public function testFelicitaSoloAQuienTocaYEsIdempotente(): void
    {
        // Cumple HOY (hace 30 años), con consentimiento → se felicita.
        $today = $this->customer('Cumple Hoy', '+34655300301', "current_date - interval '30 years'", true);
        // Cumple otro día → no.
        $other = $this->customer('Cumple Otro Dia', '+34655300302', "current_date - interval '30 years' + interval '10 days'", true);
        // Cumple hoy pero sin consentimiento → no.
        $noConsent = $this->customer('Sin Consent', '+34655300303', "current_date - interval '25 years'", false);
        // Cumple hoy pero ya felicitado este año → no repetir.
        $greeted = $this->customer('Ya Felicitado', '+34655300304', "current_date - interval '40 years'", true, 'current_date');

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        self::assertNotNull($this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$today]));
        self::assertNull($this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$other]));
        self::assertNull($this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$noConsent]));

        // Segunda pasada el mismo día: nadie nuevo (idempotente).
        $before = (string) $this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$today]);
        $this->tester->execute([]);
        self::assertSame($before, (string) $this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$today]));
        self::assertStringContainsString('pendiente de felicitar', $this->tester->getDisplay());

        // El ya felicitado este año conserva su fecha original.
        self::assertNotNull($this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$greeted]));
    }

    public function testDryRunNoMarca(): void
    {
        $id = $this->customer('Dry Cumple', '+34655300305', "current_date - interval '20 years'", true);

        $this->tester->execute(['--dry-run' => true]);
        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('[DRY]', $this->tester->getDisplay());
        self::assertNull($this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$id]));
    }

    public function testFelicitadoElAnoPasadoVuelveATocar(): void
    {
        $id = $this->customer(
            'Cumple Anual',
            '+34655300306',
            "current_date - interval '35 years'",
            true,
            "current_date - interval '1 year'"
        );

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            (string) $this->db->fetchOne('SELECT birthday_greeted_on FROM customer WHERE id = ?', [$id])
        );
    }
}
