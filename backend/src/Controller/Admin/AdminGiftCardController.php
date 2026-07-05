<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\GiftCard\GiftCardException;
use App\Service\GiftCard\GiftCardService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tarjetas regalo en el panel (doc 13 §2): recepción y admins venden,
 * consultan por código y canjean en caja.
 */
final class AdminGiftCardController extends AdminController
{
    private const ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly GiftCardService $cards,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/gift-cards', name: 'admin_gift_card_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json(['gift_cards' => $this->cards->recent($user['account_id'])]);
    }

    #[Route('/api/v1/admin/gift-cards', name: 'admin_gift_card_sell', methods: ['POST'])]
    public function sell(Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        try {
            $this->auth->assertRole($user, self::ROLES);
            $result = $this->cards->sell(
                $user['account_id'],
                (float) ($payload['amount'] ?? 0),
                is_string($payload['recipient_name'] ?? null) ? $payload['recipient_name'] : null,
                isset($payload['validity_days']) && (int) $payload['validity_days'] > 0 ? (int) $payload['validity_days'] : null,
                $user['id'],
            );
        } catch (AuthException|GiftCardException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result, 201);
    }

    #[Route('/api/v1/admin/gift-cards/{code}', name: 'admin_gift_card_detail', methods: ['GET'])]
    public function detail(string $code, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $card = $this->cards->findByCode($code, $user['account_id']);
        if ($card === null) {
            return $this->error('NOT_FOUND', 'Tarjeta no encontrada.', 404);
        }

        return $this->json(['gift_card' => $card]);
    }

    #[Route('/api/v1/admin/gift-cards/{code}/redeem', name: 'admin_gift_card_redeem', methods: ['POST'])]
    public function redeem(string $code, Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);

        try {
            $this->auth->assertRole($user, self::ROLES);
            $balance = $this->cards->redeem(
                $code,
                $user['account_id'],
                is_array($payload) ? (float) ($payload['amount'] ?? 0) : 0,
                $user['id'],
            );
        } catch (AuthException|GiftCardException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json(['ok' => true, 'balance' => $balance]);
    }
}
