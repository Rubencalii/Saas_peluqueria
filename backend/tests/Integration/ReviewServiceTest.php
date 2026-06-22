<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Review\ReviewException;
use App\Service\Review\ReviewService;

/**
 * Valoraciones post-cita (doc 13).
 */
final class ReviewServiceTest extends DatabaseTestCase
{
    private ReviewService $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ReviewService $svc */
        $svc = $this->service(ReviewService::class);
        $this->reviews = $svc;
    }

    /**
     * Crea una cita y devuelve [id, public_code]. $status para probar el gate.
     *
     * @return array{0: int, 1: string}
     */
    private function cita(string $status, string $code): array
    {
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (name, phone) VALUES ('Rev Test', ?) RETURNING id",
            ['+34600' . random_int(100000, 999999)]
        );
        $id = (int) $this->db->fetchOne(
            "INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel, public_code)
             VALUES (?, 3, 2, 1, now() - interval '1 day', now() - interval '1 day' + interval '30 min', ?, 'web', ?)
             RETURNING id",
            [$customerId, $status, $code]
        );

        return [$id, $code];
    }

    public function testValorarCitaCompletada(): void
    {
        [$id, $code] = $this->cita('completada', 'rev-ok-1');
        $res = $this->reviews->submit($id, $code, 5, 'Muy bien');

        self::assertGreaterThan(0, $res['review_id']);

        $agg = $this->reviews->aggregates(1);
        self::assertSame(1, $agg['count']);
        self::assertSame(5.0, $agg['average']);
    }

    public function testNoSePuedeValorarCitaNoCompletada(): void
    {
        [$id, $code] = $this->cita('confirmada', 'rev-nc-1');

        $this->expectException(ReviewException::class);
        $this->reviews->submit($id, $code, 4, null);
    }

    public function testUnaValoracionPorCita(): void
    {
        [$id, $code] = $this->cita('completada', 'rev-dup-1');
        $this->reviews->submit($id, $code, 4, null);

        $this->expectException(ReviewException::class);
        $this->reviews->submit($id, $code, 2, 'otra');
    }

    public function testPuntuacionFueraDeRango(): void
    {
        [$id, $code] = $this->cita('completada', 'rev-rng-1');

        $this->expectException(ReviewException::class);
        $this->reviews->submit($id, $code, 9, null);
    }

    public function testCodigoIncorrectoNoEncuentra(): void
    {
        [$id] = $this->cita('completada', 'rev-code-1');

        $this->expectException(ReviewException::class);
        $this->reviews->submit($id, 'codigo-erroneo', 5, null);
    }
}
