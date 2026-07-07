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
    /** Nombres de día (lun=0..dom=6) por idioma soportado. */
    private const DAYS = [
        'es' => ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'],
        'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ];

    /** Idioma por defecto si la cuenta no fija otro. */
    private const DEFAULT_LOCALE = 'es';

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

    /**
     * Programa un recordatorio de retorno "te toca volver" (doc 13 §2.3) para
     * una cita pasada. Tipo `seguimiento` con plantilla de marketing; el
     * dispatcher lo entrega (requiere consentimiento del cliente).
     */
    public function scheduleReturnReminder(int $appointmentId): void
    {
        $this->schedule($this->db, $appointmentId, 'seguimiento', 'recordatorio_retorno', new \DateTimeImmutable('now'));
    }

    /**
     * Al COMPLETAR una cita: agradecimiento + enlace de valoración, un par de
     * horas después (que al cliente le dé tiempo a llegar a casa). Idempotente:
     * completar dos veces no programa dos mensajes.
     */
    public function onAppointmentCompleted(int $appointmentId): void
    {
        $this->db->executeStatement(
            "INSERT INTO notification (appointment_id, type, status, template_name, scheduled_at)
             SELECT ?, 'seguimiento', 'programada', 'cita_valoracion', now() + interval '2 hours'
              WHERE NOT EXISTS (
                    SELECT 1 FROM notification WHERE appointment_id = ? AND template_name = 'cita_valoracion'
              )",
            [$appointmentId, $appointmentId]
        );
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
     * (docs/07). Mensajes cortos, un emoji, con sede/fecha/hora/servicio. El
     * idioma sale de `locale` (la cuenta del salón); por defecto español.
     *
     * @param array{type: string, status: string, name: string, location_name: string,
     *              start_at: string, service_name: string, timezone: string,
     *              template?: string, locale?: string, url?: string} $ctx
     */
    public function render(array $ctx): string
    {
        $locale = $this->locale($ctx['locale'] ?? null);
        [$fecha, $hora] = $this->formatLocal($ctx['start_at'], $ctx['timezone'], $locale);

        $repl = [
            '{name}' => $ctx['name'] !== '' ? $ctx['name'] : ($locale === 'en' ? 'there' : 'hola'),
            '{loc}' => $ctx['location_name'],
            '{svc}' => $ctx['service_name'],
            '{fecha}' => $fecha,
            '{hora}' => $hora,
            '{url}' => $ctx['url'] ?? '',
        ];

        $template = $ctx['template'] ?? '';
        $key = match (true) {
            $template === 'recordatorio_retorno' => 'retorno',
            $template === 'cita_valoracion' => 'valoracion',
            default => match ($ctx['type']) {
                'confirmacion' => 'confirmacion',
                'recordatorio' => 'recordatorio',
                'seguimiento' => 'seguimiento',
                'cambio' => $ctx['status'] === 'cancelada' ? 'cancelacion' : 'cambio',
                default => 'generico',
            },
        };

        return strtr(self::TEMPLATES[$locale][$key], $repl);
    }

    /** Plantillas por idioma (placeholders {name} {loc} {svc} {fecha} {hora}). */
    private const TEMPLATES = [
        'es' => [
            'retorno' => "¡Hola {name}! ✂️ Hace ya un tiempo de tu última visita a {loc}.\n"
                . 'Si te apetece renovar el look, escríbenos "menú" y te buscamos hueco. ¡Te esperamos!',
            'confirmacion' => "¡Hola {name}! ✅ Tu cita en {loc} está confirmada:\n"
                . "🗓️ {fecha} a las {hora}\n✂️ {svc}\n" . 'Si necesitas cambiarla, escríbenos "menú".',
            'recordatorio' => "¡Hola {name}! 👋 Te recordamos tu cita de mañana en {loc}:\n"
                . "🗓️ {fecha} a las {hora} · {svc}\n¿Confirmas que vendrás? (responde \"menú\" para gestionarla)",
            'seguimiento' => "¡Gracias por tu visita, {name}! 💛 ¿Qué tal la experiencia en {loc}?\n"
                . 'Escríbenos "menú" para reservar de nuevo cuando quieras.',
            'valoracion' => "¡Gracias por tu visita, {name}! 💛 ¿Qué tal la experiencia en {loc}?\n"
                . "Valóranos en un minuto: {url}\n"
                . 'Y cuando quieras repetir, escríbenos "menú". ✂️',
            'cancelacion' => "Hola {name}, tu cita del {fecha} a las {hora} en {loc} ha sido cancelada.\n"
                . 'Si quieres, escríbenos "menú" y te ayudamos a reubicarla.',
            'cambio' => "Hola {name}, tu cita en {loc} ha cambiado:\n🗓️ {fecha} a las {hora} · {svc}",
            'generico' => 'Hola {name}, tienes una actualización de tu cita en {loc}.',
        ],
        'en' => [
            'retorno' => "Hi {name}! ✂️ It's been a while since your last visit to {loc}.\n"
                . 'If you fancy a fresh look, reply "menu" and we\'ll find you a slot. See you soon!',
            'confirmacion' => "Hi {name}! ✅ Your appointment at {loc} is confirmed:\n"
                . "🗓️ {fecha} at {hora}\n✂️ {svc}\n" . 'Need to change it? Just reply "menu".',
            'recordatorio' => "Hi {name}! 👋 A reminder of your appointment tomorrow at {loc}:\n"
                . "🗓️ {fecha} at {hora} · {svc}\nCan you make it? (reply \"menu\" to manage it)",
            'seguimiento' => "Thanks for your visit, {name}! 💛 How was your experience at {loc}?\n"
                . 'Reply "menu" to book again whenever you like.',
            'valoracion' => "Thanks for your visit, {name}! 💛 How was your experience at {loc}?\n"
                . "Rate us in a minute: {url}\n"
                . 'And whenever you fancy a repeat, reply "menu". ✂️',
            'cancelacion' => "Hi {name}, your appointment on {fecha} at {hora} at {loc} has been cancelled.\n"
                . 'If you like, reply "menu" and we\'ll help you rebook.',
            'cambio' => "Hi {name}, your appointment at {loc} has changed:\n🗓️ {fecha} at {hora} · {svc}",
            'generico' => 'Hi {name}, there is an update to your appointment at {loc}.',
        ],
    ];

    private function locale(?string $locale): string
    {
        // PHP 8.5 depreca null como índice de array: normaliza antes de mirar.
        $locale ??= '';

        return isset(self::TEMPLATES[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /**
     * @return array{0: string, 1: string} [fecha legible, hora HH:MM] en hora local
     */
    private function formatLocal(string $isoUtc, string $tz, string $locale): array
    {
        $dt = (new \DateTimeImmutable($isoUtc))->setTimezone(new \DateTimeZone($tz));
        $dia = self::DAYS[$locale][((int) $dt->format('N')) - 1];

        return [sprintf('%s %s', $dia, $dt->format('d/m')), $dt->format('H:i')];
    }
}
