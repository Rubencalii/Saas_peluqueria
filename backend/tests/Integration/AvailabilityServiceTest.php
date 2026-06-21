<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\AvailabilityService;

/**
 * Disponibilidad contra la BD real (algoritmo doc 02).
 */
final class AvailabilityServiceTest extends DatabaseTestCase
{
    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var AvailabilityService $svc */
        $svc = $this->service(AvailabilityService::class);
        $this->availability = $svc;
    }

    public function testOfreceHuecosEnDiaLaborable(): void
    {
        // Corte hombre (service 2) en Salón Centro (1), próximo lunes.
        $offer = $this->availability->find(1, 2, null, $this->nextMonday());

        self::assertNotEmpty($offer['slots'], 'Debe haber huecos un lunes laborable.');
        // Las horas vienen en ISO 8601 con zona; el primer hueco abre a las 09:00 local.
        $first = (new \DateTimeImmutable($offer['slots'][0]['start']))
            ->setTimezone(new \DateTimeZone('Europe/Madrid'));
        self::assertSame('09:00', $first->format('H:i'));
        self::assertSame(3, $offer['slots'][0]['staff_id'], 'El corte hombre lo da Carlos (3).');
    }

    public function testSpanIncluyeTiempoMuertoDelTinte(): void
    {
        // Tinte (3): 20 ocupado + 35 reposo + 15 ocupado = 70 min de span total.
        self::assertSame(70, $this->availability->spanMinutes(3));
        // Corte hombre (2): sin segmentos → 30 min.
        self::assertSame(30, $this->availability->spanMinutes(2));
    }

    public function testServicioInexistenteLanzaExcepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->availability->spanMinutes(99999);
    }
}
