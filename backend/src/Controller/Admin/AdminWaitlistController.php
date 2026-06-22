<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Waitlist\WaitlistService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lista de espera en el panel (doc 13 §2.4): el salón ve quién espera hueco y
 * puede dar de baja una entrada. Acotado por sede según el rol.
 */
final class AdminWaitlistController extends AdminController
{
    private const ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/waitlist', name: 'admin_waitlist_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
            $requested = $request->query->get('location_id');
            $locationId = $this->auth->resolveLocation($user, $requested !== null && (int) $requested > 0 ? (int) $requested : null);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $status = (string) $request->query->get('status', 'esperando');
        if (!in_array($status, ['esperando', 'avisado', 'convertido', 'cancelado'], true)) {
            $status = 'esperando';
        }

        $pg = self::pagination($request);

        $accountId = $user['account_id'];

        return $this->json([
            'waitlist' => $this->waitlist->listForLocation($locationId, $accountId, $status, $pg['per_page'], $pg['offset']),
            'page' => $pg['page'],
            'per_page' => $pg['per_page'],
            'total' => $this->waitlist->countForLocation($locationId, $accountId, $status),
        ]);
    }

    #[Route('/api/v1/admin/waitlist/{id}', name: 'admin_waitlist_cancel', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $locationId = $this->waitlist->locationOf($id);
        if ($locationId === null) {
            return $this->error('NOT_FOUND', 'Entrada no encontrada.', 404);
        }
        try {
            $this->auth->assertLocationAccount($user, $locationId); // la sede debe ser de la cuenta
            $this->auth->assertLocation($user, $locationId);          // autoriza ANTES de cancelar
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $this->waitlist->markCancelled($id);

        return $this->json(['ok' => true]);
    }
}
