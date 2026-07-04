<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base de los tests que tocan la BD real (la de Docker en localhost:5446, con
 * los datos del seed). Cada test corre dentro de una transacción que se revierte
 * en tearDown, así que no deja rastro. Se activan los savepoints para que los
 * `transactional()` anidados de los servicios funcionen dentro de esa transacción.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
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

    protected function service(string $id): object
    {
        return static::getContainer()->get($id);
    }

    /**
     * Próximo lunes (weekday 0 en el seed: tiene horario 09:00-14:00 y 16:00-20:00).
     */
    protected function nextMonday(): string
    {
        return (new \DateTimeImmutable('next monday', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    }
}
