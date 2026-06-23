<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Notification\NotificationService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * render() es lógica pura (no toca BD): comprobamos que cada tipo de
 * notificación produce el texto esperado con sede, fecha/hora local y servicio.
 */
final class NotificationServiceTest extends KernelTestCase
{
    private NotificationService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var NotificationService $svc */
        $svc = static::getContainer()->get(NotificationService::class);
        $this->service = $svc;
    }

    /**
     * @return array{type: string, status: string, name: string, location_name: string, start_at: string, service_name: string, timezone: string}
     */
    private function ctx(string $type, string $status = 'confirmada'): array
    {
        return [
            'type' => $type,
            'status' => $status,
            'name' => 'Lucía',
            'location_name' => 'Salón Centro',
            'start_at' => '2026-07-15T08:30:00+00:00', // 10:30 en Madrid (verano, +2)
            'service_name' => 'Corte mujer',
            'timezone' => 'Europe/Madrid',
        ];
    }

    public function testConfirmacionIncluyeDatosClave(): void
    {
        $text = $this->service->render($this->ctx('confirmacion'));

        self::assertStringContainsString('Lucía', $text);
        self::assertStringContainsString('Salón Centro', $text);
        self::assertStringContainsString('Corte mujer', $text);
        self::assertStringContainsString('10:30', $text); // hora local, no UTC
        self::assertStringContainsString('confirmada', $text);
    }

    public function testRecordatorioMencionaManana(): void
    {
        $text = $this->service->render($this->ctx('recordatorio'));

        self::assertStringContainsString('mañana', $text);
        self::assertStringContainsString('10:30', $text);
    }

    public function testCambioConCitaActivaAnunciaNuevaHora(): void
    {
        $text = $this->service->render($this->ctx('cambio', 'confirmada'));

        self::assertStringContainsString('ha cambiado', $text);
        self::assertStringNotContainsString('cancelada', $text);
    }

    public function testCambioConCitaCanceladaAnunciaCancelacion(): void
    {
        $text = $this->service->render($this->ctx('cambio', 'cancelada'));

        self::assertStringContainsString('cancelada', $text);
    }

    public function testRecordatorioRetornoUsaPlantillaMarketing(): void
    {
        $ctx = $this->ctx('seguimiento', 'completada');
        $ctx['template'] = 'recordatorio_retorno';
        $text = $this->service->render($ctx);

        self::assertStringContainsString('Lucía', $text);
        self::assertStringContainsString('Salón Centro', $text);
        self::assertStringContainsString('menú', $text);
        // No debe colarse el texto del seguimiento estándar ("¿Qué tal la experiencia").
        self::assertStringNotContainsString('experiencia', $text);
    }

    public function testHoraSeMuestraEnZonaLocalNoUtc(): void
    {
        // 08:30 UTC nunca debe aparecer como tal: el cliente ve su hora local.
        $text = $this->service->render($this->ctx('confirmacion'));

        self::assertStringNotContainsString('08:30', $text);
    }

    public function testLocaleEnGeneraMensajeEnIngles(): void
    {
        $ctx = $this->ctx('confirmacion');
        $ctx['locale'] = 'en';
        $text = $this->service->render($ctx);

        self::assertStringContainsString('confirmed', $text);
        self::assertStringContainsString('Wednesday', $text); // 2026-07-15 es miércoles
        self::assertStringContainsString('10:30', $text);
        self::assertStringNotContainsString('confirmada', $text);
    }

    public function testLocaleDesconocidoCaeEnEspanol(): void
    {
        $ctx = $this->ctx('confirmacion');
        $ctx['locale'] = 'fr';
        $text = $this->service->render($ctx);

        self::assertStringContainsString('confirmada', $text);
    }
}
