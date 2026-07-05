<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AppointmentException;
use App\Service\AppointmentService;
use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestión de citas desde el panel (docs/06 §4): alta manual/telefónica,
 * edición de estado/notas y cancelación por parte del salón.
 *
 * La creación reutiliza AppointmentService (misma validación de hueco y la
 * garantía anti-solape de la BD que la web y WhatsApp). La cancelación desde
 * el panel NO aplica la ventana de antelación del cliente: el personal puede
 * cancelar en cualquier momento.
 */
final class AdminAppointmentController extends AdminController
{
    private const EDITABLE_STATUSES = ['pendiente', 'confirmada', 'completada', 'no_show', 'cancelada'];

    public function __construct(
        private readonly Connection $db,
        private readonly AppointmentService $appointments,
        private readonly AuthService $auth,
        private readonly NotificationService $notifications,
        private readonly \App\Service\Loyalty\LoyaltyService $loyalty,
        private readonly \App\Service\Pack\PackService $packs,
    ) {
    }

    #[Route('/api/v1/admin/appointments', name: 'admin_appointment_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = self::user($request);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $locationId = (int) ($payload['location_id'] ?? 0);
        try {
            $this->auth->assertLocation($user, $locationId);
            $this->auth->assertLocationAccount($user, $locationId);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload['channel'] = 'manual';

        try {
            $result = $this->appointments->create($payload);
        } catch (AppointmentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        // Trazabilidad: quién la creó (doc 05, columna created_by).
        $this->db->executeStatement(
            'UPDATE appointment SET created_by = ? WHERE id = ?',
            [$user['id'], $result['appointment_id']]
        );

        return $this->json($result, 201);
    }

    #[Route('/api/v1/admin/appointments/{id}', name: 'admin_appointment_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $appt = $this->loadInLocation($id, $user);
        if ($appt instanceof JsonResponse) {
            return $appt;
        }

        $sets = [];
        $params = [];

        if (array_key_exists('status', $payload)) {
            $status = is_string($payload['status']) ? $payload['status'] : '';
            if (!in_array($status, self::EDITABLE_STATUSES, true)) {
                return $this->error('VALIDATION', 'Estado inválido.', 400);
            }
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if (array_key_exists('notes', $payload)) {
            $sets[] = 'notes = ?';
            $params[] = $payload['notes'] !== null ? (string) $payload['notes'] : null;
        }

        if ($sets === []) {
            return $this->error('VALIDATION', 'Nada que actualizar (status y/o notes).', 400);
        }

        $params[] = $id;
        $this->db->executeStatement(
            'UPDATE appointment SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        // Al completar la cita: puntos de fidelización y canje de bono si el
        // cliente tiene uno vivo del servicio (ambos idempotentes).
        if (($payload['status'] ?? null) === 'completada') {
            $this->loyalty->awardForCompletedAppointment($id);
            $this->packs->redeemForAppointment($id);
        }

        return $this->json($this->detail($id));
    }

    #[Route('/api/v1/admin/appointments/{id}', name: 'admin_appointment_cancel', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);

        $appt = $this->loadInLocation($id, $user);
        if ($appt instanceof JsonResponse) {
            return $appt;
        }

        $this->db->transactional(function (Connection $tx) use ($id): void {
            $tx->executeStatement("UPDATE appointment SET status = 'cancelada' WHERE id = ?", [$id]);
            $this->notifications->onAppointmentCancelled($tx, $id);
        });

        return $this->json($this->detail($id));
    }

    /**
     * Carga una cita y comprueba que el usuario tiene acceso a su sede.
     *
     * @param array{role: string, location_id: int|null, account_id: int} $user
     *
     * @return array<string, mixed>|JsonResponse  fila de la cita, o respuesta de error
     */
    private function loadInLocation(int $id, array $user): array|JsonResponse
    {
        $row = $this->db->fetchAssociative('SELECT id, location_id FROM appointment WHERE id = ?', [$id]);
        if ($row === false) {
            return $this->error('NOT_FOUND', 'Cita no encontrada.', 404);
        }
        try {
            $this->auth->assertLocation($user, (int) $row['location_id']);
            $this->auth->assertLocationAccount($user, (int) $row['location_id']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(int $id): array
    {
        $r = $this->db->fetchAssociative(
            'SELECT a.id, a.status, a.channel, a.start_at, a.end_at, a.notes, a.public_code,
                    a.service_id, s.name AS service_name,
                    a.staff_id, st.name AS staff_name,
                    a.customer_id, c.name AS customer_name, c.phone AS customer_phone
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN staff st ON st.id = a.staff_id
               LEFT JOIN customer c ON c.id = a.customer_id
              WHERE a.id = ?',
            [$id]
        );

        return [
            'appointment_id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'channel' => (string) $r['channel'],
            'start' => (new \DateTimeImmutable($r['start_at']))->format('c'),
            'end' => (new \DateTimeImmutable($r['end_at']))->format('c'),
            'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
            'public_code' => $r['public_code'] !== null ? (string) $r['public_code'] : null,
            'service' => ['id' => (int) $r['service_id'], 'name' => (string) $r['service_name']],
            'staff' => $r['staff_id'] !== null
                ? ['id' => (int) $r['staff_id'], 'name' => (string) $r['staff_name']]
                : null,
            'customer' => $r['customer_id'] !== null ? [
                'id' => (int) $r['customer_id'],
                'name' => (string) $r['customer_name'],
                'phone' => (string) $r['customer_phone'],
            ] : null,
        ];
    }
}
