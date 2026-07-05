<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Pack\PackException;
use App\Service\Pack\PackService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bonos/packs (doc 13 §2): catálogo (admins) y venta a clientes (también
 * recepción). El canje es automático al completar citas.
 */
final class AdminPackController extends AdminController
{
    private const CONFIG_ROLES = ['admin_sede', 'admin_cadena'];
    private const SELL_ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly PackService $packs,
        private readonly AuthService $auth,
        private readonly Connection $db,
    ) {
    }

    #[Route('/api/v1/admin/packs', name: 'admin_pack_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json(['packs' => $this->packs->listForAccount(self::user($request)['account_id'])]);
    }

    #[Route('/api/v1/admin/packs', name: 'admin_pack_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
            $id = $this->packs->create(
                $user['account_id'],
                (int) ($payload['service_id'] ?? 0),
                is_string($payload['name'] ?? null) ? $payload['name'] : '',
                (int) ($payload['sessions'] ?? 0),
                (float) ($payload['price'] ?? -1),
                isset($payload['validity_days']) && (int) $payload['validity_days'] > 0 ? (int) $payload['validity_days'] : null,
            );
        } catch (AuthException|PackException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/packs/{id}', name: 'admin_pack_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);

        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        if (!is_array($payload) || !array_key_exists('active', $payload)) {
            return $this->error('VALIDATION', 'Nada que actualizar (active).', 400);
        }
        if (!$this->packs->setActive($id, $user['account_id'], (bool) $payload['active'])) {
            return $this->error('NOT_FOUND', 'Bono no encontrado.', 404);
        }

        return $this->json(['ok' => true]);
    }

    /** Vende un bono a un cliente (recepción y admins). */
    #[Route('/api/v1/admin/customers/{id}/packs', name: 'admin_customer_pack_sell', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sell(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);

        try {
            $this->auth->assertRole($user, self::SELL_ROLES);
            $soldId = $this->packs->sell(
                $id,
                is_array($payload) ? (int) ($payload['pack_id'] ?? 0) : 0,
                $user['account_id'],
                $user['id'],
            );
        } catch (AuthException|PackException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json(['id' => $soldId], 201);
    }

    /** Bonos de un cliente (para la ficha). */
    #[Route('/api/v1/admin/customers/{id}/packs', name: 'admin_customer_pack_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function customerPacks(int $id, Request $request): JsonResponse
    {
        $owned = $this->db->fetchOne(
            'SELECT 1 FROM customer WHERE id = ? AND account_id = ?',
            [$id, self::user($request)['account_id']]
        );
        if ($owned === false) {
            return $this->error('NOT_FOUND', 'Cliente no encontrado.', 404);
        }

        return $this->json(['packs' => $this->packs->forCustomer($id)]);
    }
}
