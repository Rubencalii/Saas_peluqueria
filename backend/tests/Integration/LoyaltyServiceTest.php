<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Loyalty\LoyaltyService;

/**
 * Fidelización por puntos (doc 13). El servicio 2 (Corte hombre) vale 14 € en
 * el seed → 14 puntos.
 */
final class LoyaltyServiceTest extends DatabaseTestCase
{
    private LoyaltyService $loyalty;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var LoyaltyService $svc */
        $svc = $this->service(LoyaltyService::class);
        $this->loyalty = $svc;
    }

    /**
     * @return array{0: int, 1: int} [customerId, appointmentId]
     */
    private function cita(string $status): array
    {
        $customerId = (int) $this->db->fetchOne(
            "INSERT INTO customer (name, phone) VALUES ('Loyal', ?) RETURNING id",
            ['+34600' . random_int(100000, 999999)]
        );
        $apptId = (int) $this->db->fetchOne(
            "INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel, public_code)
             VALUES (?, 3, 2, 1, now() - interval '1 day', now() - interval '1 day' + interval '30 min', ?, 'web', ?)
             RETURNING id",
            [$customerId, $status, 'loy-' . random_int(1000, 9999)]
        );

        return [$customerId, $apptId];
    }

    public function testAbonaPuntosAlCompletar(): void
    {
        [$customerId, $apptId] = $this->cita('completada');
        $this->loyalty->awardForCompletedAppointment($apptId);

        $summary = $this->loyalty->summary($customerId);
        self::assertSame(14, $summary['points']);
        self::assertCount(1, $summary['history']);
    }

    public function testAbonoEsIdempotente(): void
    {
        [$customerId, $apptId] = $this->cita('completada');
        $this->loyalty->awardForCompletedAppointment($apptId);
        $this->loyalty->awardForCompletedAppointment($apptId);

        $summary = $this->loyalty->summary($customerId);
        self::assertSame(14, $summary['points']);
        self::assertCount(1, $summary['history']);
    }

    public function testNoAbonaSiNoEstaCompletada(): void
    {
        [$customerId, $apptId] = $this->cita('confirmada');
        $this->loyalty->awardForCompletedAppointment($apptId);

        self::assertSame(0, $this->loyalty->summary($customerId)['points']);
    }
}
