<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Doctrine\DBAL\Connection;

/**
 * Programación y redacción de notificaciones (docs/07).
 *
 * No envía nada por sí mismo: inserta filas en `notification` con estado
 * `programada` y una fecha de envío. El comando `app:notifications:dispatch`
 * (cron) las recoge cuando vencen y las entrega. Así el envío real queda
 * desacoplado de la petición que crea/cambia la cita y es reintentable.
 *
 * Tipos (enum notification_type): confirmacion, recordatorio, cambio, seguimiento.
 */
final class NotificationService
{
    private const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    /** Antelación del recordatorio antes de la cita. */
    private const REMINDER_HOURS_BEFORE = 24;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Al crear una cita: confirmación inmediata + recordatorio 24 h antes
     * (si la cita es dentro de más de 24 h). Se ejecuta dentro de la misma
     * transacción que la creación para que sea atómico.
     */
    public function onAppointmentCreated(Connection $tx, int $appointmentId): void
    {
        $this->schedule($tx, $appointmentId, 'confirmacion', 'cita_confirmacion', new \DateTimeImmutable('now'));
        $this->scheduleReminder($tx, $appointmentId);
    }

    /**
     * Al reprogramar: avisa del cambio y reprograma el recordatorio a la nueva hora.
     */
    public function onAppointmentRescheduled(Connection $tx, int $appointmentId): void
    {
        $this->cancelPending($tx, $appointmentId);
        $this->schedule($tx, $appointmentId, 'cambio', 'cita_cambio_salon', new \DateTimeImmutable('now'));
        $this->scheduleReminder($tx, $appointmentId);
    }

    /**
     * Al cancelar: elimina lo pendiente y deja un aviso de cancelación.
     */
    public function onAppointmentCancelled(Connection $tx, int $appointmentId): void
    {
        $this->cancelPending($tx, $appointmentId);
        $this->schedule($tx, $appointmentId, 'cambio', 'cita_cancelacion_salon', new \DateTimeImmutable('now'));
    }

    private function scheduleReminder(Connection $tx, int $appointmentId): void
    {
        $start = $tx->fetchOne('SELECT start_at FROM appointment WHERE id = ?', [$appointmentId]);
        if ($start === false) {
            return;
        }
        $remindAt = (new \DateTimeImmutable((string) $start))->modify('-' . self::REMINDER_HOURS_BEFORE . ' hours');
        if ($remindAt <= new \DateTimeImmutable('now')) {
            return; // la cita es demasiado próxima: no procede recordatorio
        }
        $this->schedule($tx, $appointmentId, 'recordatorio', 'cita_recordatorio_24h', $remindAt);
    }

    private function schedule(Connection $tx, int $appointmentId, string $type, string $template, \DateTimeImmutable $when): void
    {
        $tx->executeStatement(
            'INSERT INTO notification (appointment_id, type, status, template_name, scheduled_at)
             VALUES (?, ?, \'programada\', ?, ?)',
            [$appointmentId, $type, $template, $when->setTimezone(new \DateTimeZone('UTC'))->format('c')]
        );
    }

    /** Elimina las notificaciones aún no enviadas de una cita. */
    private function cancelPending(Connection $tx, int $appointmentId): void
    {
        $tx->executeStatement(
            "DELETE FROM notification WHERE appointment_id = ? AND status = 'programada'",
            [$appointmentId]
        );
    }

    /**
     * Redacta el texto de una notificación a partir de los datos de la cita
     * (docs/07). Mensajes cortos, un emoji, con sede/fecha/hora/servicio.
     *
     * @param array{type: string, status: string, name: string, location_name: string,
     *              start_at: string, service_name: string, timezone: string} $ctx
     */
    public function render(array $ctx): string
    {
        $name = $ctx['name'] !== '' ? $ctx['name'] : 'hola';
        $loc = $ctx['location_name'];
        $svc = $ctx['service_name'];
        [$fecha, $hora] = $this->formatLocal($ctx['start_at'], $ctx['timezone']);

        return match ($ctx['type']) {
            'confirmacion' => "¡Hola {$name}! ✅ Tu cita en {$loc} está confirmada:\n"
                . "🗓️ {$fecha} a las {$hora}\n✂️ {$svc}\n"
                . 'Si necesitas cambiarla, escríbenos "menú".',
            'recordatorio' => "¡Hola {$name}! 👋 Te recordamos tu cita de mañana en {$loc}:\n"
                . "🗓️ {$fecha} a las {$hora} · {$svc}\n¿Confirmas que vendrás? (responde \"menú\" para gestionarla)",
            'seguimiento' => "¡Gracias por tu visita, {$name}! 💛 ¿Qué tal la experiencia en {$loc}?\n"
                . 'Escríbenos "menú" para reservar de nuevo cuando quieras.',
            'cambio' => $ctx['status'] === 'cancelada'
                ? "Hola {$name}, tu cita del {$fecha} a las {$hora} en {$loc} ha sido cancelada.\n"
                    . 'Si quieres, escríbenos "menú" y te ayudamos a reubicarla.'
                : "Hola {$name}, tu cita en {$loc} ha cambiado:\n🗓️ {$fecha} a las {$hora} · {$svc}",
            default => "Hola {$name}, tienes una actualización de tu cita en {$loc}.",
        };
    }

    /**
     * @return array{0: string, 1: string} [fecha legible, hora HH:MM] en hora local
     */
    private function formatLocal(string $isoUtc, string $tz): array
    {
        $dt = (new \DateTimeImmutable($isoUtc))->setTimezone(new \DateTimeZone($tz));
        $dia = self::DAYS[((int) $dt->format('N')) - 1];

        return [sprintf('%s %s', $dia, $dt->format('d/m')), $dt->format('H:i')];
    }
}
