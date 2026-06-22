<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Gdpr\GdprService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRM básico del panel (docs/06 §4). El cliente es único por teléfono en toda
 * la cadena (no está ligado a una sede), así que cualquier rol del panel puede
 * buscarlo y consultar su historial.
 *
 * Los derechos RGPD (export y supresión, doc 09 §5) se restringen a admin.
 */
final class AdminCustomerController extends AdminController
{
    private const GDPR_ROLES = ['admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
        private readonly GdprService $gdpr,
        private readonly \App\Service\Loyalty\LoyaltyService $loyalty,
    ) {
    }

    #[Route('/api/v1/admin/customers', name: 'admin_customer_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('query', ''));
        $pg = self::pagination($request);

        if ($query !== '') {
            $where = 'WHERE name ILIKE ? OR phone ILIKE ?';
            $order = 'ORDER BY name';
            $params = ['%' . $query . '%', '%' . $query . '%'];
        } else {
            $where = '';
            $order = 'ORDER BY created_at DESC';
            $params = [];
        }

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM customer $where", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name, phone, email, wa_consent, created_at FROM customer $where $order LIMIT ? OFFSET ?",
            [...$params, $pg['per_page'], $pg['offset']]
        );

        return $this->json([
            'customers' => array_map($this->present(...), $rows),
            'page' => $pg['page'],
            'per_page' => $pg['per_page'],
            'total' => $total,
        ]);
    }

    #[Route('/api/v1/admin/customers/{id}', name: 'admin_customer_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, name, phone, email, wa_consent, consent_at, created_at FROM customer WHERE id = ?',
            [$id]
        );
        if ($row === false) {
            return $this->error('NOT_FOUND', 'Cliente no encontrado.', 404);
        }

        $appts = $this->db->fetchAllAssociative(
            'SELECT a.id, a.status, a.start_at, a.end_at,
                    s.name AS service_name, l.name AS location_name, st.name AS staff_name
               FROM appointment a
               JOIN service  s ON s.id = a.service_id
               JOIN location l ON l.id = a.location_id
               LEFT JOIN staff st ON st.id = a.staff_id
              WHERE a.customer_id = ?
              ORDER BY a.start_at DESC LIMIT 50',
            [$id]
        );

        $customer = $this->present($row);
        $customer['consent_at'] = $row['consent_at'] !== null
            ? (new \DateTimeImmutable($row['consent_at']))->format('c')
            : null;
        $customer['appointments'] = array_map(static fn (array $a): array => [
            'appointment_id' => (int) $a['id'],
            'status' => (string) $a['status'],
            'start' => (new \DateTimeImmutable($a['start_at']))->format('c'),
            'end' => (new \DateTimeImmutable($a['end_at']))->format('c'),
            'service_name' => (string) $a['service_name'],
            'location_name' => (string) $a['location_name'],
            'staff_name' => $a['staff_name'] !== null ? (string) $a['staff_name'] : null,
        ], $appts);
        $customer['loyalty'] = $this->loyalty->summary($id);

        return $this->json(['customer' => $customer]);
    }

    #[Route('/api/v1/admin/customers/{id}', name: 'admin_customer_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $exists = $this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$id]);
        if ($exists === false) {
            return $this->error('NOT_FOUND', 'Cliente no encontrado.', 404);
        }

        $sets = [];
        $params = [];
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->error('VALIDATION', 'El nombre no puede quedar vacío.', 400);
            }
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if (array_key_exists('email', $payload)) {
            $email = $payload['email'] !== null ? trim((string) $payload['email']) : null;
            $sets[] = 'email = ?';
            $params[] = $email === '' ? null : $email;
        }

        if ($sets === []) {
            return $this->error('VALIDATION', 'Nada que actualizar (name y/o email).', 400);
        }

        $params[] = $id;
        $this->db->executeStatement('UPDATE customer SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->detail($id);
    }

    /**
     * RGPD: exporta todos los datos del cliente (acceso/portabilidad, doc 09 §5).
     */
    #[Route('/api/v1/admin/customers/{id}/export', name: 'admin_customer_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(int $id, Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), self::GDPR_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $data = $this->gdpr->export($id);
        if ($data === null) {
            return $this->error('NOT_FOUND', 'Cliente no encontrado.', 404);
        }

        return $this->json($data);
    }

    /**
     * RGPD: derecho de supresión. Anonimiza al cliente (borra su PII) conservando
     * las citas por necesidad fiscal/contable (doc 09 §5).
     */
    #[Route('/api/v1/admin/customers/{id}', name: 'admin_customer_anonymize', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function anonymize(int $id, Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), self::GDPR_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        if (!$this->gdpr->anonymize($id)) {
            return $this->error('NOT_FOUND', 'Cliente no encontrado.', 404);
        }

        return $this->json(['ok' => true, 'anonymized' => true]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'phone' => (string) $r['phone'],
            'email' => $r['email'] !== null ? (string) $r['email'] : null,
            'wa_consent' => (bool) $r['wa_consent'],
            'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
        ];
    }
}
