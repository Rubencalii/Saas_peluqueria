<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use Doctrine\DBAL\Connection;

/**
 * Feed iCal (RFC 5545) de la agenda de un profesional (doc 13 §2.6).
 *
 * Genera un calendario de solo lectura con las próximas citas del profesional
 * para que pueda suscribirse desde Google/Apple Calendar. Se identifica por un
 * token secreto en la URL; rotarlo invalida las suscripciones anteriores.
 */
final class IcalService
{
    /** Ventana del feed: una semana atrás y ~4 meses por delante. */
    private const PAST_DAYS = 7;
    private const FUTURE_DAYS = 120;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Devuelve el .ics del profesional con ese token, o null si no existe.
     */
    public function feedForToken(string $token): ?string
    {
        $staff = $this->db->fetchAssociative(
            'SELECT id, name FROM staff WHERE calendar_token = ?',
            [$token]
        );
        if ($staff === false) {
            return null;
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT a.id, a.start_at, a.end_at, a.status,
                    s.name AS service_name, l.name AS location_name,
                    c.name AS customer_name, c.phone AS customer_phone
               FROM appointment a
               JOIN service  s ON s.id = a.service_id
               JOIN location l ON l.id = a.location_id
               LEFT JOIN customer c ON c.id = a.customer_id
              WHERE a.staff_id = ?
                AND a.start_at >= now() - make_interval(days => ?)
                AND a.start_at <  now() + make_interval(days => ?)
              ORDER BY a.start_at",
            [(int) $staff['id'], self::PAST_DAYS, self::FUTURE_DAYS]
        );

        return $this->build((string) $staff['name'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function build(string $staffName, array $rows): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SaaS Peluqueria//Agenda//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape('Agenda · ' . $staffName),
        ];

        foreach ($rows as $r) {
            $start = (new \DateTimeImmutable($r['start_at']))->setTimezone(new \DateTimeZone('UTC'));
            $end = (new \DateTimeImmutable($r['end_at']))->setTimezone(new \DateTimeZone('UTC'));
            $customer = $r['customer_name'] !== null ? (string) $r['customer_name'] : 'Cliente';
            $summary = sprintf('%s · %s', (string) $r['service_name'], $customer);
            $desc = $customer
                . ($r['customer_phone'] !== null ? ' (' . $r['customer_phone'] . ')' : '')
                . ' — ' . (string) $r['service_name'];

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:appt-' . (int) $r['id'] . '@saas-peluqueria';
            $lines[] = 'DTSTAMP:' . $now;
            $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . $this->escape($summary);
            $lines[] = 'DESCRIPTION:' . $this->escape($desc);
            $lines[] = 'LOCATION:' . $this->escape((string) $r['location_name']);
            $lines[] = 'STATUS:' . ($r['status'] === 'cancelada' ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545: líneas separadas por CRLF y plegadas a 75 octetos.
        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    /** Escapa texto para un valor de propiedad iCal (RFC 5545 §3.3.11). */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $text
        );
    }

    /** Plegado de líneas largas (>75 octetos) con continuación por espacio. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $chunk = '';
        // Trocear respetando caracteres multibyte (no partir un carácter UTF-8).
        foreach (mb_str_split($line) as $ch) {
            if (strlen($chunk) + strlen($ch) > 74) {
                $out .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
            }
            $chunk .= $ch;
        }

        return $out . ($out === '' ? '' : "\r\n ") . $chunk;
    }
}
