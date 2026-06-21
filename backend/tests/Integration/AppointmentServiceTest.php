<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\AppointmentException;
use App\Service\AppointmentService;
use App\Service\AvailabilityService;

/**
 * Reservas contra la BD real: camino feliz, condición de carrera (la BD rechaza
 * el solape con 409), idempotencia, rollback de reprogramación y cancelación.
 * Son las garantías críticas del sistema (doc 02 §1, §7).
 */
final class AppointmentServiceTest extends DatabaseTestCase
{
    private AppointmentService $appointments;
    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appointments = $this->service(AppointmentService::class);
        $this->availability = $this->service(AvailabilityService::class);
    }

    /**
     * @return list<string> ISOs de los primeros huecos de Corte hombre el próximo lunes
     */
    private function slots(int $n = 2): array
    {
        $offer = $this->availability->find(1, 2, null, $this->nextMonday());
        self::assertGreaterThanOrEqual($n, count($offer['slots']), 'Hacen falta huecos para el test.');

        return array_map(static fn (array $s): string => $s['start'], array_slice($offer['slots'], 0, $n));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $start, string $phone = '+34600000001'): array
    {
        return [
            'location_id' => 1,
            'service_id' => 2,
            'staff_id' => null,
            'start' => $start,
            'customer' => ['name' => 'Cliente Test', 'phone' => $phone],
            'channel' => 'web',
        ];
    }

    public function testCreaCitaConfirmada(): void
    {
        [$slot] = $this->slots(1);
        $res = $this->appointments->create($this->payload($slot));

        self::assertSame('confirmada', $res['status']);
        self::assertGreaterThan(0, $res['appointment_id']);
        self::assertNotEmpty($res['public_code']);
    }

    public function testDobleReservaDelMismoHuecoLanzaSlotTaken(): void
    {
        [$slot] = $this->slots(1);
        $this->appointments->create($this->payload($slot));

        try {
            $this->appointments->create($this->payload($slot, '+34600000002'));
            self::fail('La segunda reserva del mismo hueco debía fallar.');
        } catch (AppointmentException $e) {
            self::assertSame('SLOT_TAKEN', $e->errorCode);
            self::assertSame(409, $e->statusCode);
        }
    }

    public function testIdempotenciaDevuelveLaMismaCita(): void
    {
        [$slot] = $this->slots(1);
        $first = $this->appointments->create($this->payload($slot), 'clave-idem-1');
        $second = $this->appointments->create($this->payload($slot), 'clave-idem-1');

        self::assertSame($first['appointment_id'], $second['appointment_id']);
        self::assertTrue($second['idempotent_replay'] ?? false);
    }

    public function testReprogramarAHuecoOcupadoRevierteYConservaOriginal(): void
    {
        // Huecos no solapados: el corte dura 30 min, así que cogemos el 1.º y el 3.º
        // del grid de 15 min (p. ej. 09:00 y 09:30), que son adyacentes sin solape.
        $slots = $this->slots(3);
        $slotA = $slots[0];
        $slotB = $slots[2];
        $a = $this->appointments->create($this->payload($slotA, '+34600000003'));
        $this->appointments->create($this->payload($slotB, '+34600000004'));

        try {
            $this->appointments->reschedule($a['appointment_id'], $a['public_code'], $slotB);
            self::fail('Reprogramar a un hueco ocupado debía fallar.');
        } catch (AppointmentException $e) {
            self::assertSame('SLOT_TAKEN', $e->errorCode);
        }

        // La cita original sigue intacta y activa en su hueco.
        $row = $this->db->fetchAssociative(
            'SELECT status, start_at FROM appointment WHERE id = ?',
            [$a['appointment_id']]
        );
        self::assertSame('confirmada', $row['status']);
        self::assertSame(
            (new \DateTimeImmutable($slotA))->getTimestamp(),
            (new \DateTimeImmutable($row['start_at']))->getTimestamp()
        );
    }

    public function testCancelarLiberaLaCita(): void
    {
        [$slot] = $this->slots(1);
        $a = $this->appointments->create($this->payload($slot));

        $this->appointments->cancel($a['appointment_id'], $a['public_code']);

        $status = $this->db->fetchOne('SELECT status FROM appointment WHERE id = ?', [$a['appointment_id']]);
        self::assertSame('cancelada', $status);
    }
}
