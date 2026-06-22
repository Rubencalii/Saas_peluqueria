<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Reset de contraseña del panel (doc 14 §9).
 *
 * Flujo: el usuario pide un enlace por email → se genera un token de un solo
 * uso (se guarda su hash, con caducidad) → el usuario envía token + contraseña
 * nueva. Sin transporte de email configurado, el enlace se registra en el log
 * (degradación de desarrollo, como WhatsApp/IA/Stripe).
 */
final class PasswordResetService
{
    /** Validez del token. */
    private const TTL_MINUTES = 60;

    /** Longitud mínima de la nueva contraseña. */
    private const MIN_PASSWORD = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Genera un token de reset para el email (si existe un usuario activo) y
     * "envía" el enlace. Devuelve el token en claro para uso interno
     * (logging/email/tests); el controlador NO lo expone al cliente.
     *
     * Es silenciosa por diseño: no revela si el email existe.
     */
    public function request(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name FROM app_user WHERE email = ? AND active',
            [$email]
        );
        if ($user === false) {
            return null; // no filtramos la existencia del email
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . self::TTL_MINUTES . ' minutes');

        // Invalida tokens previos sin usar del mismo usuario y crea el nuevo.
        $this->db->transactional(function (Connection $tx) use ($user, $token, $expiresAt): void {
            $tx->executeStatement('DELETE FROM password_reset WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
            $tx->executeStatement(
                'INSERT INTO password_reset (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
                [(int) $user['id'], hash('sha256', $token), $expiresAt->format('c')]
            );
        });

        $link = rtrim($this->appUrl, '/') . '/restablecer-contrasena?token=' . $token;
        $this->logger->info('[PasswordReset] enlace para {email}: {link}', ['email' => $email, 'link' => $link]);

        return $token;
    }

    /**
     * Restablece la contraseña a partir de un token válido.
     *
     * @throws AuthException
     */
    public function reset(string $token, string $newPassword): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new AuthException('VALIDATION', 'Falta el token.', 400);
        }
        if (strlen($newPassword) < self::MIN_PASSWORD) {
            throw new AuthException('WEAK_PASSWORD', 'La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres.', 400);
        }

        $row = $this->db->fetchAssociative(
            'SELECT id, user_id, expires_at, used_at FROM password_reset WHERE token_hash = ?',
            [hash('sha256', $token)]
        );
        if ($row === false || $row['used_at'] !== null) {
            throw new AuthException('INVALID_TOKEN', 'El enlace no es válido o ya se usó.', 400);
        }
        if (new \DateTimeImmutable($row['expires_at']) < new \DateTimeImmutable('now')) {
            throw new AuthException('TOKEN_EXPIRED', 'El enlace ha caducado, pide otro.', 400);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        $this->db->transactional(function (Connection $tx) use ($row, $hash): void {
            $tx->executeStatement('UPDATE app_user SET password_hash = ? WHERE id = ?', [$hash, (int) $row['user_id']]);
            $tx->executeStatement('UPDATE password_reset SET used_at = now() WHERE id = ?', [(int) $row['id']]);
        });
    }
}
