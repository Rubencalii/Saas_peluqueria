<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\TotpService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Doble factor (TOTP) del propio usuario del panel. Flujo de alta en dos
 * pasos para no activar un 2FA que el usuario no llegó a configurar:
 * setup genera el secreto (sin persistir) → el usuario lo mete en su app →
 * enable lo confirma con un código válido y solo entonces se guarda.
 */
final class AdminTwoFactorController extends AdminController
{
    public function __construct(
        private readonly Connection $db,
        private readonly TotpService $totp,
    ) {
    }

    #[Route('/api/v1/admin/2fa', name: 'admin_2fa_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $enabled = $this->db->fetchOne(
            'SELECT totp_secret IS NOT NULL FROM app_user WHERE id = ?',
            [self::user($request)['id']]
        );

        return $this->json(['enabled' => (bool) $enabled]);
    }

    #[Route('/api/v1/admin/2fa/setup', name: 'admin_2fa_setup', methods: ['POST'])]
    public function setup(Request $request): JsonResponse
    {
        $user = self::user($request);
        $secret = $this->totp->generateSecret();

        return $this->json([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->otpauthUri($secret, $user['email']),
        ]);
    }

    #[Route('/api/v1/admin/2fa/enable', name: 'admin_2fa_enable', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);
        $secret = is_array($payload) && is_string($payload['secret'] ?? null) ? $payload['secret'] : '';
        $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : '';

        if ($secret === '' || !$this->totp->verify($secret, $code)) {
            return $this->error('TOTP_INVALID', 'El código no coincide. Comprueba la app y vuelve a intentarlo.', 400);
        }

        $this->db->executeStatement(
            'UPDATE app_user SET totp_secret = ? WHERE id = ?',
            [$secret, $user['id']]
        );

        return $this->json(['ok' => true, 'enabled' => true]);
    }

    #[Route('/api/v1/admin/2fa/disable', name: 'admin_2fa_disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);
        $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : '';

        $secret = $this->db->fetchOne('SELECT totp_secret FROM app_user WHERE id = ?', [$user['id']]);
        if ($secret === false || $secret === null) {
            return $this->json(['ok' => true, 'enabled' => false]); // ya estaba desactivado
        }
        // Desactivar exige un código válido: si te roban la sesión, no pueden
        // quitarte el 2FA sin tener también tu app de autenticación.
        if (!$this->totp->verify((string) $secret, $code)) {
            return $this->error('TOTP_INVALID', 'Código incorrecto: el 2FA sigue activo.', 400);
        }

        $this->db->executeStatement('UPDATE app_user SET totp_secret = NULL WHERE id = ?', [$user['id']]);

        return $this->json(['ok' => true, 'enabled' => false]);
    }
}
