<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use App\Service\Email\EmailSender;
use Doctrine\DBAL\Connection;

/**
 * Verificación del email del administrador tras el alta de salón (doc 15, Fase 6).
 * Genera un token de un solo uso (se guarda su hash), envía el enlace por email
 * y lo valida. Reduce el alta de cuentas con emails falsos.
 */
final class EmailVerificationService
{
    private const TTL_HOURS = 48;

    public function __construct(
        private readonly Connection $db,
        private readonly EmailSender $email,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Genera y "envía" un enlace de verificación para el usuario. Devuelve el
     * token en claro (para logging/tests); el controlador no lo expone.
     */
    public function issueFor(int $userId, string $email, string $name): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('now'))->modify('+' . self::TTL_HOURS . ' hours');
        $this->db->executeStatement(
            'UPDATE app_user SET email_verify_hash = ?, email_verify_expires = ? WHERE id = ?',
            [hash('sha256', $token), $expires->format('c'), $userId]
        );

        $link = rtrim($this->appUrl, '/') . '/verificar?token=' . $token;
        $this->email->send(
            $email,
            'Verifica tu email',
            "¡Hola {$name}!\n\nConfirma tu email para activar tu cuenta (enlace válido "
            . self::TTL_HOURS . " h):\n{$link}\n\nSi no fuiste tú, ignora este correo."
        );

        return $token;
    }

    /**
     * Verifica un token. Marca el email como verificado y consume el token.
     * Devuelve true si el token era válido.
     */
    public function verify(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $row = $this->db->fetchAssociative(
            'SELECT id, email_verify_expires FROM app_user WHERE email_verify_hash = ?',
            [hash('sha256', $token)]
        );
        if ($row === false) {
            return false;
        }
        if (new \DateTimeImmutable((string) $row['email_verify_expires']) < new \DateTimeImmutable('now')) {
            return false;
        }

        $this->db->executeStatement(
            'UPDATE app_user SET email_verified_at = now(), email_verify_hash = NULL, email_verify_expires = NULL WHERE id = ?',
            [(int) $row['id']]
        );

        return true;
    }

    public function isVerified(int $userId): bool
    {
        return $this->db->fetchOne('SELECT email_verified_at FROM app_user WHERE id = ?', [$userId]) !== null;
    }
}
