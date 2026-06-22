<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Recurring\RecurringService;

/**
 * Citas recurrentes (doc 13): generación de la próxima cita.
 */
final class RecurringServiceTest extends DatabaseTestCase
{
    private RecurringService $recurring;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var RecurringService $svc */
        $svc = $this->service(RecurringService::class);
        $this->recurring = $svc;
    }

    /**
     * Próximo día laborable (lun-vie) estrictamente futuro: tiene horario en el
     * seed y su hueco de 09:00 está libre, así que la generación debe crear cita.
     */
    private function proximoLaborable(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Madrid');
        $d = new \DateTimeImmutable('tomorrow', $tz);
        while ((int) $d->format('N') > 5) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    public function testGeneraLaProximaCita(): void
    {
        $dia = $this->proximoLaborable();
        $weekday = ((int) $dia->format('N')) - 1;

        $this->recurring->create(1, 2, null, 'Recurrente', '+34699888000', $weekday, '09:00', 4);

        $res = $this->recurring->generateDue();
        self::assertGreaterThanOrEqual(1, $res['created']);

        // Existe una cita para ese cliente el día calculado a las 09:00 (07:00 UTC en verano).
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM appointment a JOIN customer c ON c.id = a.customer_id
              WHERE c.phone = ? AND a.start_at::date = ?",
            ['+34699888000', $dia->format('Y-m-d')]
        );
        self::assertSame(1, $count);
    }

    public function testNoGeneraFueraDeHorizonte(): void
    {
        $dia = $this->proximoLaborable();
        $weekday = ((int) $dia->format('N')) - 1;

        $res = $this->recurring->create(1, 2, null, 'Lejano', '+34699888111', $weekday, '09:00', 8);
        // Ya generada hoy: la siguiente (hoy + 8 semanas) cae fuera del horizonte (35 días).
        $this->db->executeStatement(
            'UPDATE recurring_appointment SET last_generated_date = CURRENT_DATE WHERE id = ?',
            [$res['id']]
        );

        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM appointment');
        $this->recurring->generateDue();
        $after = (int) $this->db->fetchOne('SELECT COUNT(*) FROM appointment');

        self::assertSame($before, $after);
    }

    public function testServicioNoOfrecidoLanza(): void
    {
        $this->expectException(\App\Service\Recurring\RecurringException::class);
        $this->recurring->create(1, 99999, null, 'X', '+34699888222', 1, '09:00', 4);
    }
}
