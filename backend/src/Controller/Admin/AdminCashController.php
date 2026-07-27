<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Caja del día (migraciones 0029 y 0030): qué se ha cobrado en una sede y
 * cómo, y arqueo al cerrar. Es trabajo de mostrador, así que también lo usa
 * recepción.
 *
 * En el cajón hay tres cosas: los servicios cobrados en efectivo, los prepagos
 * (bonos y tarjetas regalo) vendidos en efectivo y los movimientos apuntados a
 * mano (fondo de cambio que entra, gastos que salen). Una cita pagada con
 * 'bono' o 'regalo' NO suma dinero: se cobró el día de la venta.
 */
final class AdminCashController extends AdminController
{
    private const ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    /** Formas de pago de la enum payment_method, en el orden en que se muestran. */
    public const METHODS = ['efectivo', 'tarjeta', 'bono', 'regalo', 'online'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/cash/day', name: 'admin_cash_day', methods: ['GET'])]
    public function day(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $date, $tz] = $ctx;
        [$fromUtc, $toUtc] = $this->dayBounds($date, $tz);

        $lines = $this->db->fetchAllAssociative(
            "SELECT a.id, a.start_at, a.payment_method,
                    s.name AS service_name, st.name AS staff_name, c.name AS customer_name,
                    COALESCE(sl.price_override, s.price, 0) AS amount
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id
               LEFT JOIN staff st ON st.id = a.staff_id
               LEFT JOIN customer c ON c.id = a.customer_id
              WHERE a.location_id = ? AND a.start_at >= ? AND a.start_at < ?
                AND a.status = 'completada'
              ORDER BY a.start_at",
            [$locationId, $fromUtc, $toUtc]
        );

        $byMethod = array_fill_keys([...self::METHODS, 'sin_registrar'], ['count' => 0, 'amount' => 0.0]);
        $total = 0.0;
        $rows = [];
        foreach ($lines as $l) {
            $amount = round((float) $l['amount'], 2);
            $method = $l['payment_method'] !== null ? (string) $l['payment_method'] : 'sin_registrar';
            ++$byMethod[$method]['count'];
            $byMethod[$method]['amount'] = round($byMethod[$method]['amount'] + $amount, 2);
            $total = round($total + $amount, 2);

            $rows[] = [
                'appointment_id' => (int) $l['id'],
                'start' => (new \DateTimeImmutable((string) $l['start_at']))->format('c'),
                'customer_name' => $l['customer_name'] !== null ? (string) $l['customer_name'] : null,
                'service_name' => (string) $l['service_name'],
                'staff_name' => $l['staff_name'] !== null ? (string) $l['staff_name'] : null,
                'amount' => $amount,
                'payment_method' => $l['payment_method'] !== null ? (string) $l['payment_method'] : null,
            ];
        }

        // Prepagos vendidos hoy en esta sede: dinero que entró con la venta.
        $prepaid = $this->prepaidOfDay($locationId, $fromUtc, $toUtc);
        $prepaidByMethod = array_fill_keys([...self::METHODS, 'sin_registrar'], ['count' => 0, 'amount' => 0.0]);
        $prepaidTotal = 0.0;
        foreach ($prepaid as $p) {
            $key = $p['payment_method'] ?? 'sin_registrar';
            ++$prepaidByMethod[$key]['count'];
            $prepaidByMethod[$key]['amount'] = round($prepaidByMethod[$key]['amount'] + $p['amount'], 2);
            $prepaidTotal = round($prepaidTotal + $p['amount'], 2);
        }

        $movements = $this->movementsOfDay($locationId, $date);

        return $this->json([
            'location_id' => $locationId,
            'date' => $date,
            'total' => $total,
            'prepaid_total' => $prepaidTotal,
            'movements' => $movements,
            // Neto de entradas y salidas del cajón (negativo si salió dinero).
            'movements_net' => round(array_sum(array_map(
                static fn (array $m): float => 'entrada' === $m['kind'] ? $m['amount'] : -$m['amount'],
                $movements
            )), 2),
            // Se calcula con el mismo helper que usa el cierre: la pantalla y el
            // arqueo no pueden discrepar.
            'expected_cash' => $this->expectedCash($locationId, $date, $fromUtc, $toUtc),
            'by_method' => $byMethod,
            'prepaid_by_method' => $prepaidByMethod,
            'appointments' => $rows,
            'prepaid' => $prepaid,
            'close' => $this->closeOf($locationId, $date),
        ]);
    }

    /**
     * Apunta una entrada o una salida de efectivo del cajón (migración 0031).
     * Body: { location_id, date, kind: 'entrada'|'gasto', amount, concept }
     */
    #[Route('/api/v1/admin/cash/movements', name: 'admin_cash_movement_create', methods: ['POST'])]
    public function createMovement(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $ctx = $this->context($request, $payload);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $date] = $ctx;

        $kind = $payload['kind'] ?? '';
        $amount = $payload['amount'] ?? null;
        $concept = trim((string) ($payload['concept'] ?? ''));
        if (!in_array($kind, ['entrada', 'gasto'], true)) {
            return $this->error('VALIDATION', 'kind debe ser entrada o gasto.', 400);
        }
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return $this->error('VALIDATION', 'El importe debe ser mayor que 0.', 400);
        }
        if ($concept === '') {
            return $this->error('VALIDATION', 'Escribe el concepto del movimiento.', 400);
        }

        $user = self::user($request);
        $id = (int) $this->db->fetchOne(
            'INSERT INTO cash_movement (account_id, location_id, business_date, kind, amount, concept, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$user['account_id'], $locationId, $date, (string) $kind, round((float) $amount, 2), $concept, $user['id']]
        );

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/cash/movements/{id}', name: 'admin_cash_movement_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteMovement(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        // La sede se comprueba además del tenant: un admin_sede no borra
        // movimientos de la caja de otra sede.
        $locationId = $this->db->fetchOne(
            'SELECT location_id FROM cash_movement WHERE id = ? AND account_id = ?',
            [$id, $user['account_id']]
        );
        if ($locationId === false) {
            return $this->error('NOT_FOUND', 'Movimiento no encontrado.', 404);
        }
        try {
            $this->auth->assertLocation($user, (int) $locationId);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $this->db->executeStatement('DELETE FROM cash_movement WHERE id = ?', [$id]);

        return $this->json(['ok' => true]);
    }

    /**
     * Histórico de arqueos de la sede: para ver de un vistazo si los
     * descuadres son cosa de un día suelto o van a más.
     */
    #[Route('/api/v1/admin/cash/closes', name: 'admin_cash_closes', methods: ['GET'])]
    public function closes(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
            $requested = $request->query->get('location_id');
            $locationId = $this->auth->resolveLocation($user, $requested !== null && (int) $requested > 0 ? (int) $requested : null);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if ($locationId === null) {
            return $this->error('VALIDATION', 'Indica la sede (location_id).', 400);
        }

        $tzName = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ? AND account_id = ?', [$locationId, $user['account_id']]);
        if ($tzName === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }
        $tz = new \DateTimeZone((string) $tzName);

        $hoy = new \DateTimeImmutable('now', $tz);
        $to = $this->parseDate($request->query->get('to'), $tz) ?? $hoy;
        $from = $this->parseDate($request->query->get('from'), $tz) ?? $to->modify('-29 days');
        if ($from > $to) {
            return $this->error('VALIDATION', 'El rango de fechas es inválido (to debe ser >= from).', 400);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT c.business_date, c.expected_cash, c.counted_cash, c.notes, c.closed_at, u.name AS closed_by_name
               FROM cash_close c LEFT JOIN app_user u ON u.id = c.closed_by
              WHERE c.location_id = ? AND c.business_date BETWEEN ? AND ?
              ORDER BY c.business_date DESC',
            [$locationId, $from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        $closes = [];
        $totalDiff = 0.0;
        $conDescuadre = 0;
        foreach ($rows as $r) {
            $expected = round((float) $r['expected_cash'], 2);
            $counted = round((float) $r['counted_cash'], 2);
            $diff = round($counted - $expected, 2);
            $totalDiff = round($totalDiff + $diff, 2);
            if (0.0 !== $diff) {
                ++$conDescuadre;
            }
            $closes[] = [
                'date' => (new \DateTimeImmutable((string) $r['business_date']))->format('Y-m-d'),
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => $diff,
                'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
                'closed_at' => (new \DateTimeImmutable((string) $r['closed_at']))->format('c'),
                'closed_by_name' => $r['closed_by_name'] !== null ? (string) $r['closed_by_name'] : null,
            ];
        }

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'closes' => $closes,
            'total_difference' => $totalDiff,
            'days_with_difference' => $conDescuadre,
        ]);
    }

    /**
     * Cambia la forma de pago de un prepago ya vendido (para corregir desde la
     * pantalla de caja). Body: { kind: 'gift_card'|'pack', id, payment_method }
     */
    #[Route('/api/v1/admin/cash/prepaid', name: 'admin_cash_prepaid_method', methods: ['PATCH'])]
    public function prepaidMethod(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $kind = $payload['kind'] ?? '';
        $id = (int) ($payload['id'] ?? 0);
        $method = $payload['payment_method'] ?? null;
        if (!in_array($kind, ['gift_card', 'pack'], true) || $id <= 0) {
            return $this->error('VALIDATION', 'Indica kind (gift_card o pack) e id.', 400);
        }
        if ($method !== null && !in_array($method, self::METHODS, true)) {
            return $this->error('VALIDATION', 'Forma de pago inválida.', 400);
        }

        // El WHERE ata la fila a la cuenta: no se toca lo de otro tenant.
        $affected = 'gift_card' === $kind
            ? $this->db->executeStatement(
                'UPDATE gift_card SET payment_method = ? WHERE id = ? AND account_id = ?',
                [$method, $id, $user['account_id']]
            )
            : $this->db->executeStatement(
                'UPDATE customer_pack cp SET payment_method = ?
                   FROM pack p WHERE p.id = cp.pack_id AND cp.id = ? AND p.account_id = ?',
                [$method, $id, $user['account_id']]
            );

        if ($affected === 0) {
            return $this->error('NOT_FOUND', 'Venta no encontrada.', 404);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Bonos y tarjetas regalo vendidos en esa sede y ventana. Los vendidos sin
     * sede (admin_cadena sin sede fija) no son de ningún cajón, así que no
     * aparecen en ningún arqueo.
     *
     * @return list<array{kind: string, id: int, label: string, amount: float, payment_method: string|null}>
     */
    private function prepaidOfDay(int $locationId, string $fromUtc, string $toUtc): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT 'gift_card' AS kind, id, 'Tarjeta regalo ' || code AS label,
                    initial_amount AS amount, payment_method, created_at AS sold_at
               FROM gift_card
              WHERE sold_location_id = ? AND created_at >= ? AND created_at < ?
              UNION ALL
             SELECT 'pack' AS kind, cp.id, 'Bono · ' || p.name AS label,
                    p.price AS amount, cp.payment_method, cp.sold_at
               FROM customer_pack cp
               JOIN pack p ON p.id = cp.pack_id
              WHERE cp.sold_location_id = ? AND cp.sold_at >= ? AND cp.sold_at < ?
              ORDER BY sold_at",
            [$locationId, $fromUtc, $toUtc, $locationId, $fromUtc, $toUtc]
        );

        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'id' => (int) $r['id'],
            'label' => (string) $r['label'],
            'amount' => round((float) $r['amount'], 2),
            'payment_method' => $r['payment_method'] !== null ? (string) $r['payment_method'] : null,
        ], $rows);
    }

    /**
     * Cierra (o vuelve a cerrar) el día: guarda lo contado frente a lo esperado.
     * El esperado NO viene del cliente, se recalcula aquí.
     */
    #[Route('/api/v1/admin/cash/close', name: 'admin_cash_close', methods: ['POST'])]
    public function close(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $ctx = $this->context($request, $payload);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $date, $tz] = $ctx;

        $counted = $payload['counted_cash'] ?? null;
        if (!is_numeric($counted) || (float) $counted < 0) {
            return $this->error('VALIDATION', 'Indica el efectivo contado (0 o más).', 400);
        }

        [$fromUtc, $toUtc] = $this->dayBounds($date, $tz);
        $expected = $this->expectedCash($locationId, $date, $fromUtc, $toUtc);

        $user = self::user($request);
        $this->db->executeStatement(
            'INSERT INTO cash_close (account_id, location_id, business_date, expected_cash, counted_cash, notes, closed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (location_id, business_date) DO UPDATE
                SET expected_cash = EXCLUDED.expected_cash,
                    counted_cash  = EXCLUDED.counted_cash,
                    notes         = EXCLUDED.notes,
                    closed_by     = EXCLUDED.closed_by,
                    closed_at     = now()',
            [
                $user['account_id'], $locationId, $date,
                round($expected, 2), round((float) $counted, 2),
                isset($payload['notes']) && $payload['notes'] !== '' ? (string) $payload['notes'] : null,
                $user['id'],
            ]
        );

        return $this->json(['close' => $this->closeOf($locationId, $date)]);
    }

    /**
     * Entradas y salidas de efectivo apuntadas ese día en esa sede.
     *
     * @return list<array{id: int, kind: string, amount: float, concept: string, created_by_name: string|null}>
     */
    private function movementsOfDay(int $locationId, string $date): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT m.id, m.kind, m.amount, m.concept, u.name AS created_by_name
               FROM cash_movement m LEFT JOIN app_user u ON u.id = m.created_by
              WHERE m.location_id = ? AND m.business_date = ?
              ORDER BY m.created_at',
            [$locationId, $date]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'amount' => round((float) $r['amount'], 2),
            'concept' => (string) $r['concept'],
            'created_by_name' => $r['created_by_name'] !== null ? (string) $r['created_by_name'] : null,
        ], $rows);
    }

    /**
     * Lo que debería haber en el cajón: servicios y prepagos cobrados en
     * efectivo, más las entradas apuntadas y menos las salidas.
     */
    private function expectedCash(int $locationId, string $date, string $fromUtc, string $toUtc): float
    {
        $servicios = (float) $this->db->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(sl.price_override, s.price, 0)), 0)
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id
              WHERE a.location_id = ? AND a.start_at >= ? AND a.start_at < ?
                AND a.status = 'completada' AND a.payment_method = 'efectivo'",
            [$locationId, $fromUtc, $toUtc]
        );

        $prepagos = 0.0;
        foreach ($this->prepaidOfDay($locationId, $fromUtc, $toUtc) as $p) {
            if ('efectivo' === $p['payment_method']) {
                $prepagos += $p['amount'];
            }
        }

        $movimientos = 0.0;
        foreach ($this->movementsOfDay($locationId, $date) as $m) {
            $movimientos += 'entrada' === $m['kind'] ? $m['amount'] : -$m['amount'];
        }

        return round($servicios + $prepagos + $movimientos, 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function closeOf(int $locationId, string $date): ?array
    {
        $r = $this->db->fetchAssociative(
            'SELECT c.expected_cash, c.counted_cash, c.notes, c.closed_at, u.name AS closed_by_name
               FROM cash_close c LEFT JOIN app_user u ON u.id = c.closed_by
              WHERE c.location_id = ? AND c.business_date = ?',
            [$locationId, $date]
        );
        if ($r === false) {
            return null;
        }
        $expected = round((float) $r['expected_cash'], 2);
        $counted = round((float) $r['counted_cash'], 2);

        return [
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => round($counted - $expected, 2),
            'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
            'closed_at' => (new \DateTimeImmutable((string) $r['closed_at']))->format('c'),
            'closed_by_name' => $r['closed_by_name'] !== null ? (string) $r['closed_by_name'] : null,
        ];
    }

    /**
     * Rol, sede (de la cuenta) y fecha del día pedido.
     *
     * @param array<string, mixed>|null $body para el POST, donde llegan en el cuerpo
     *
     * @return array{0: int, 1: string, 2: \DateTimeZone}|JsonResponse
     */
    private function context(Request $request, ?array $body = null): array|JsonResponse
    {
        $user = self::user($request);
        $rawLocation = $body !== null ? ($body['location_id'] ?? null) : $request->query->get('location_id');
        $rawDate = $body !== null ? ($body['date'] ?? null) : $request->query->get('date');

        try {
            $this->auth->assertRole($user, self::ROLES);
            $requested = $rawLocation !== null && (int) $rawLocation > 0 ? (int) $rawLocation : null;
            $locationId = $this->auth->resolveLocation($user, $requested);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        if ($locationId === null) {
            return $this->error('VALIDATION', 'Indica la sede (location_id): la caja es de una sede concreta.', 400);
        }

        $tzName = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ? AND account_id = ?', [$locationId, $user['account_id']]);
        if ($tzName === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }
        $tz = new \DateTimeZone((string) $tzName);

        $date = is_string($rawDate) && $rawDate !== '' ? $rawDate : (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        if ($this->parseDate($date, $tz) === null) {
            return $this->error('VALIDATION', 'La fecha debe tener el formato AAAA-MM-DD.', 400);
        }

        return [$locationId, $date, $tz];
    }

    /** Fecha AAAA-MM-DD en la zona de la sede, o null si no es válida. */
    private function parseDate(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);

        return $d !== false && $d->format('Y-m-d') === $raw ? $d : null;
    }

    /**
     * Límites UTC del día local de la sede.
     *
     * @return array{0: string, 1: string}
     */
    private function dayBounds(string $date, \DateTimeZone $tz): array
    {
        $from = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $utc = new \DateTimeZone('UTC');

        return [
            $from->setTimezone($utc)->format('c'),
            $from->modify('+1 day')->setTimezone($utc)->format('c'),
        ];
    }
}
