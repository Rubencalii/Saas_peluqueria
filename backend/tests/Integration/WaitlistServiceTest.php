<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Waitlist\WaitlistException;
use App\Service\Waitlist\WaitlistService;

/**
 * Lista de espera contra la BD real (doc 13 §2.4).
 */
final class WaitlistServiceTest extends DatabaseTestCase
{
    private WaitlistService $waitlist;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var WaitlistService $svc */
        $svc = $this->service(WaitlistService::class);
        $this->waitlist = $svc;
    }

    public function testApuntarCreaEntrada(): void
    {
        $res = $this->waitlist->join(1, 2, null, 'Test Espera', '+34699000111', true, null);

        self::assertGreaterThan(0, $res['waitlist_id']);
        self::assertFalse($res['already']);

        $status = $this->db->fetchOne('SELECT status FROM waitlist WHERE id = ?', [$res['waitlist_id']]);
        self::assertSame('esperando', $status);
    }

    public function testApuntarseDosVecesEsIdempotente(): void
    {
        $first = $this->waitlist->join(1, 2, null, 'Test Espera', '+34699000222', false, null);
        $second = $this->waitlist->join(1, 2, null, 'Test Espera', '+34699000222', false, null);

        self::assertSame($first['waitlist_id'], $second['waitlist_id']);
        self::assertTrue($second['already']);
    }

    public function testServicioNoOfrecidoEnLaSedeLanza(): void
    {
        // El servicio 99999 no existe / no se ofrece en la sede 1.
        $this->expectException(WaitlistException::class);
        $this->waitlist->join(1, 99999, null, 'X', '+34699000333', false, null);
    }

    public function testFechaPasadaLanza(): void
    {
        $this->expectException(WaitlistException::class);
        $this->waitlist->join(1, 2, null, 'X', '+34699000444', false, '2020-01-01');
    }

    public function testCancelarCambiaEstado(): void
    {
        $res = $this->waitlist->join(1, 2, null, 'Test Espera', '+34699000555', false, null);
        self::assertSame(1, $this->waitlist->locationOf($res['waitlist_id']));

        $this->waitlist->markCancelled($res['waitlist_id']);
        $status = $this->db->fetchOne('SELECT status FROM waitlist WHERE id = ?', [$res['waitlist_id']]);
        self::assertSame('cancelado', $status);
    }
}
