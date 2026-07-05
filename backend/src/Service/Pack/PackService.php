<?php

declare(strict_types=1);

namespace App\Service\Pack;

use Doctrine\DBAL\Connection;

/**
 * Bonos/packs de sesiones (doc 13 §2): catálogo por cuenta, venta a clientes
 * desde el panel y canje automático al completar una cita del servicio.
 */
final class PackService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Catálogo de bonos de la cuenta (activos e inactivos, para gestión).
     *
     * @return list<array<string, mixed>>
     */
    public function listForAccount(int $accountId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.name, p.sessions, p.price, p.validity_days, p.active,
                    p.service_id, s.name AS service_name,
                    (SELECT COUNT(*) FROM customer_pack cp WHERE cp.pack_id = p.id) AS sold
               FROM pack p JOIN service s ON s.id = p.service_id
              WHERE p.account_id = ?
              ORDER BY p.active DESC, p.name',
            [$accountId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'sessions' => (int) $r['sessions'],
            'price' => (float) $r['price'],
            'validity_days' => $r['validity_days'] !== null ? (int) $r['validity_days'] : null,
            'active' => (bool) $r['active'],
            'service_id' => (int) $r['service_id'],
            'service_name' => (string) $r['service_name'],
            'sold' => (int) $r['sold'],
        ], $rows);
    }

    /**
     * Crea una definición de bono.
     *
     * @throws PackException
     */
    public function create(int $accountId, int $serviceId, string $name, int $sessions, float $price, ?int $validityDays): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new PackException('VALIDATION', 'El bono necesita un nombre.');
        }
        if ($sessions < 1 || $sessions > 1000) {
            throw new PackException('VALIDATION', 'Las sesiones deben estar entre 1 y 1000.');
        }
        if ($price < 0) {
            throw new PackException('VALIDATION', 'El precio no puede ser negativo.');
        }
        if ($validityDays !== null && $validityDays < 1) {
            throw new PackException('VALIDATION', 'La validez debe ser al menos 1 día.');
        }
        // El servicio debe ser de la cuenta (no filtra catálogos ajenos).
        if ($this->db->fetchOne('SELECT 1 FROM service WHERE id = ? AND account_id = ?', [$serviceId, $accountId]) === false) {
            throw new PackException('NOT_FOUND', 'Servicio no encontrado.', 404);
        }

        return (int) $this->db->fetchOne(
            'INSERT INTO pack (account_id, service_id, name, sessions, price, validity_days)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$accountId, $serviceId, $name, $sessions, $price, $validityDays]
        );
    }

    /** Activa/desactiva un bono del catálogo. */
    public function setActive(int $id, int $accountId, bool $active): bool
    {
        return $this->db->executeStatement(
            'UPDATE pack SET active = ? WHERE id = ? AND account_id = ?',
            [$active, $id, $accountId],
            [\Doctrine\DBAL\ParameterType::BOOLEAN]
        ) > 0;
    }

    /**
     * Vende un bono a un cliente (ambos de la misma cuenta).
     *
     * @return int id del bono vendido (customer_pack)
     *
     * @throws PackException
     */
    public function sell(int $customerId, int $packId, int $accountId, ?int $soldBy): int
    {
        $pack = $this->db->fetchAssociative(
            'SELECT sessions, validity_days FROM pack WHERE id = ? AND account_id = ? AND active',
            [$packId, $accountId]
        );
        if ($pack === false) {
            throw new PackException('NOT_FOUND', 'Bono no encontrado o inactivo.', 404);
        }
        if ($this->db->fetchOne('SELECT 1 FROM customer WHERE id = ? AND account_id = ?', [$customerId, $accountId]) === false) {
            throw new PackException('NOT_FOUND', 'Cliente no encontrado.', 404);
        }

        $expires = $pack['validity_days'] !== null
            ? (new \DateTimeImmutable('now'))->modify('+' . (int) $pack['validity_days'] . ' days')->format('c')
            : null;

        return (int) $this->db->fetchOne(
            'INSERT INTO customer_pack (customer_id, pack_id, sessions_left, expires_at, sold_by)
             VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$customerId, $packId, (int) $pack['sessions'], $expires, $soldBy]
        );
    }

    /**
     * Bonos de un cliente (saldo, caducidad y servicio al que aplican).
     *
     * @return list<array<string, mixed>>
     */
    public function forCustomer(int $customerId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT cp.id, cp.sessions_left, cp.expires_at, cp.sold_at,
                    p.name, p.sessions, p.service_id, s.name AS service_name
               FROM customer_pack cp
               JOIN pack p ON p.id = cp.pack_id
               JOIN service s ON s.id = p.service_id
              WHERE cp.customer_id = ?
              ORDER BY (cp.sessions_left > 0) DESC, cp.sold_at DESC',
            [$customerId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'service_id' => (int) $r['service_id'],
            'service_name' => (string) $r['service_name'],
            'sessions_total' => (int) $r['sessions'],
            'sessions_left' => (int) $r['sessions_left'],
            'expires_at' => $r['expires_at'] !== null ? (new \DateTimeImmutable($r['expires_at']))->format('c') : null,
            'sold_at' => (new \DateTimeImmutable($r['sold_at']))->format('c'),
        ], $rows);
    }

    /**
     * Canje automático al COMPLETAR una cita: si el cliente tiene un bono vivo
     * del servicio, descuenta una sesión (el que antes caduque primero).
     * Idempotente: una cita solo consume una sesión aunque se complete dos veces.
     *
     * @return bool true si se descontó una sesión
     */
    public function redeemForAppointment(int $appointmentId): bool
    {
        $appt = $this->db->fetchAssociative(
            'SELECT customer_id, service_id FROM appointment WHERE id = ?',
            [$appointmentId]
        );
        if ($appt === false || $appt['customer_id'] === null) {
            return false;
        }

        return (bool) $this->db->transactional(function (Connection $tx) use ($appointmentId, $appt): bool {
            // Bono vivo del servicio con saldo; el más próximo a caducar primero.
            $packId = $tx->fetchOne(
                "SELECT cp.id
                   FROM customer_pack cp
                   JOIN pack p ON p.id = cp.pack_id
                  WHERE cp.customer_id = ? AND p.service_id = ? AND cp.sessions_left > 0
                    AND (cp.expires_at IS NULL OR cp.expires_at >= now())
                  ORDER BY cp.expires_at ASC NULLS LAST, cp.sold_at ASC
                  LIMIT 1 FOR UPDATE",
                [(int) $appt['customer_id'], (int) $appt['service_id']]
            );
            if ($packId === false) {
                return false;
            }

            // La UNIQUE(appointment_id) hace el canje idempotente.
            $inserted = $tx->executeStatement(
                'INSERT INTO pack_redemption (customer_pack_id, appointment_id) VALUES (?, ?)
                 ON CONFLICT (appointment_id) DO NOTHING',
                [(int) $packId, $appointmentId]
            );
            if ($inserted === 0) {
                return false; // esta cita ya consumió su sesión
            }

            $tx->executeStatement(
                'UPDATE customer_pack SET sessions_left = sessions_left - 1 WHERE id = ?',
                [(int) $packId]
            );

            return true;
        });
    }
}
