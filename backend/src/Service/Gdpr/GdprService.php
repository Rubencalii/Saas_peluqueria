<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use Doctrine\DBAL\Connection;

/**
 * Derechos RGPD sobre los datos de un cliente (doc 09 §5).
 *
 * - export(): derecho de acceso y portabilidad → vuelca todos sus datos
 *   personales y su actividad en un objeto JSON.
 * - anonymize(): derecho de supresión ("olvido") → borra los datos personales
 *   pero CONSERVA las citas (anonimizadas) por necesidad fiscal/contable, que
 *   es una base legal válida para retener el registro transaccional.
 */
final class GdprService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Vuelca todos los datos del cliente (acceso/portabilidad).
     *
     * @return array<string, mixed>|null  null si no existe
     */
    public function export(int $customerId): ?array
    {
        $customer = $this->db->fetchAssociative(
            'SELECT id, name, phone, email, wa_consent, consent_at, created_at FROM customer WHERE id = ?',
            [$customerId]
        );
        if ($customer === false) {
            return null;
        }

        $appointments = $this->db->fetchAllAssociative(
            'SELECT a.id, a.status, a.channel, a.start_at, a.end_at, a.notes, a.public_code,
                    s.name AS service_name, l.name AS location_name, st.name AS staff_name
               FROM appointment a
               JOIN service  s ON s.id = a.service_id
               JOIN location l ON l.id = a.location_id
               LEFT JOIN staff st ON st.id = a.staff_id
              WHERE a.customer_id = ? ORDER BY a.start_at',
            [$customerId]
        );
        $payments = $this->db->fetchAllAssociative(
            'SELECT p.id, p.amount, p.currency, p.status, p.created_at, p.paid_at, p.appointment_id
               FROM payment p
               JOIN appointment a ON a.id = p.appointment_id
              WHERE a.customer_id = ? ORDER BY p.id',
            [$customerId]
        );
        $waitlist = $this->db->fetchAllAssociative(
            'SELECT id, location_id, service_id, staff_id, desired_date, status, created_at
               FROM waitlist WHERE customer_id = ? ORDER BY id',
            [$customerId]
        );
        $conversation = $this->db->fetchAssociative(
            'SELECT state, needs_human, updated_at FROM conversation WHERE wa_id = ?',
            [$this->waId((string) $customer['phone'])]
        );

        return [
            'customer' => $customer,
            'appointments' => $appointments,
            'payments' => $payments,
            'waitlist' => $waitlist,
            'whatsapp_conversation' => $conversation === false ? null : $conversation,
            'exported_at' => (new \DateTimeImmutable('now'))->format('c'),
        ];
    }

    /**
     * Anonimiza al cliente: borra sus datos personales y deja las citas sin PII.
     *
     * @return bool false si el cliente no existe
     */
    public function anonymize(int $customerId): bool
    {
        $customer = $this->db->fetchAssociative('SELECT id, phone FROM customer WHERE id = ?', [$customerId]);
        if ($customer === false) {
            return false;
        }
        $oldWaId = $this->waId((string) $customer['phone']);

        $this->db->transactional(function (Connection $tx) use ($customerId, $oldWaId): void {
            // El teléfono es UNIQUE NOT NULL: lo sustituimos por un marcador único.
            $tx->executeStatement(
                "UPDATE customer
                    SET name = 'Cliente anonimizado',
                        phone = 'anon:' || id,
                        email = NULL,
                        wa_consent = FALSE,
                        consent_at = NULL
                  WHERE id = ?",
                [$customerId]
            );
            // Quitamos el rastro de la conversación de WhatsApp y la lista de espera.
            $tx->executeStatement('DELETE FROM conversation WHERE wa_id = ?', [$oldWaId]);
            $tx->executeStatement('DELETE FROM waitlist WHERE customer_id = ?', [$customerId]);
            // Las citas y pagos se conservan (ya anonimizados al no tener PII propia).
        });

        return true;
    }

    /** wa_id (id de WhatsApp) = teléfono en formato E.164 sin el '+'. */
    private function waId(string $phone): string
    {
        return ltrim($phone, '+');
    }
}
