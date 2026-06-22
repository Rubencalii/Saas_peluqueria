<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Row-Level Security (multi-tenant Fase 4, doc 15). Conecta como el rol
 * restringido `peluqueria_app` (sujeto a RLS, a diferencia del owner) y
 * comprueba que, fijada `app.account_id`, la BD solo deja ver las filas de esa
 * cuenta — aunque la consulta no filtre por `account_id`. Sin la GUC, no ve nada.
 *
 * Todo en una transacción del propio rol que se revierte al final.
 */
final class RlsPolicyTest extends KernelTestCase
{
    private Connection $app;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $owner */
        $owner = static::getContainer()->get('doctrine.dbal.default_connection');
        $p = $owner->getParams();

        $this->app = DriverManager::getConnection([
            'driver' => $p['driver'] ?? 'pdo_pgsql',
            'host' => $p['host'] ?? '127.0.0.1',
            'port' => $p['port'] ?? 5432,
            'dbname' => $p['dbname'] ?? 'peluqueria_test',
            'user' => 'peluqueria_app',
            'password' => 'peluqueria_app',
        ]);
        $this->app->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app->isTransactionActive()) {
            $this->app->rollBack();
        }
        $this->app->close();
        parent::tearDown();
    }

    public function testLasPoliticasAislanPorCuenta(): void
    {
        $a = (int) $this->app->fetchOne("INSERT INTO account (name, slug, status) VALUES ('RLS A', 'rls-a', 'active') RETURNING id");
        $b = (int) $this->app->fetchOne("INSERT INTO account (name, slug, status) VALUES ('RLS B', 'rls-b', 'active') RETURNING id");
        // WITH CHECK (true) permite insertar filas de cualquier cuenta (el código fija account_id).
        $this->app->executeStatement("INSERT INTO location (account_id, name, slug, timezone, active) VALUES (?, 'La', 'rls-la', 'Europe/Madrid', TRUE)", [$a]);
        $this->app->executeStatement("INSERT INTO location (account_id, name, slug, timezone, active) VALUES (?, 'Lb', 'rls-lb', 'Europe/Madrid', TRUE)", [$b]);

        // Sin app.account_id fijado: fail-closed, no ve las filas recién creadas.
        self::assertSame([], $this->visibleSlugs());

        // Como cuenta A: solo ve la suya.
        $this->setTenant($a);
        $slugsA = $this->visibleSlugs();
        self::assertContains('rls-la', $slugsA);
        self::assertNotContains('rls-lb', $slugsA);

        // Como cuenta B: solo ve la suya.
        $this->setTenant($b);
        $slugsB = $this->visibleSlugs();
        self::assertContains('rls-lb', $slugsB);
        self::assertNotContains('rls-la', $slugsB);
    }

    private function setTenant(int $accountId): void
    {
        $this->app->executeStatement("SELECT set_config('app.account_id', ?, false)", [(string) $accountId]);
    }

    /**
     * @return list<string>
     */
    private function visibleSlugs(): array
    {
        return array_map(
            static fn ($v): string => (string) $v,
            $this->app->fetchFirstColumn("SELECT slug FROM location WHERE slug IN ('rls-la', 'rls-lb')")
        );
    }
}
