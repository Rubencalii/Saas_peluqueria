<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use App\Service\Auth\AuthService;
use App\Service\Email\EmailSender;
use Doctrine\DBAL\Connection;

/**
 * Alta de un salón en el SaaS (multi-tenant Fase 6, doc 15).
 *
 * Crea de forma atómica la cuenta (en `trial`), su suscripción inicial (plan
 * `free`), la primera sede y el usuario administrador (`admin_cadena`). El email
 * es único global (decisión de producto: un email = un usuario = una cuenta).
 * Devuelve un token de sesión para entrar directamente al panel.
 */
final class SignupService
{
    private const SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
        private readonly EmailSender $email,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{token: string, expires_at: string, user: array<string, mixed>, account: array{id: int, slug: string}}
     *
     * @throws SignupException
     */
    public function signup(array $input): array
    {
        $businessName = trim((string) ($input['business_name'] ?? ''));
        $slug = mb_strtolower(trim((string) ($input['slug'] ?? '')));

        $admin = is_array($input['admin'] ?? null) ? $input['admin'] : [];
        $adminName = trim((string) ($admin['name'] ?? ''));
        $adminEmail = mb_strtolower(trim((string) ($admin['email'] ?? '')));
        $password = (string) ($admin['password'] ?? '');

        $loc = is_array($input['location'] ?? null) ? $input['location'] : [];
        $locName = trim((string) ($loc['name'] ?? ''));
        $locSlug = mb_strtolower(trim((string) ($loc['slug'] ?? ''))) ?: 'principal';
        $tz = trim((string) ($loc['timezone'] ?? '')) ?: 'Europe/Madrid';

        if ($businessName === '' || $adminName === '' || $adminEmail === '' || $locName === '') {
            throw new SignupException('VALIDATION', 'Faltan datos: nombre del negocio, administrador y sede.');
        }
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new SignupException('VALIDATION', 'El slug debe tener 3-40 caracteres: letras minúsculas, números o guiones.');
        }
        if (preg_match(self::SLUG_RE, $locSlug) !== 1) {
            throw new SignupException('VALIDATION', 'El slug de la sede no es válido.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new SignupException('VALIDATION', 'El email del administrador no es válido.');
        }
        if (strlen($password) < 8) {
            throw new SignupException('VALIDATION', 'La contraseña debe tener al menos 8 caracteres.');
        }
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            throw new SignupException('VALIDATION', 'Zona horaria no válida.');
        }

        // El email es único global y el slug de cuenta también.
        if ($this->db->fetchOne('SELECT 1 FROM app_user WHERE email = ?', [$adminEmail]) !== false) {
            throw new SignupException('EMAIL_TAKEN', 'Ya existe una cuenta con ese email.', 409);
        }
        if ($this->db->fetchOne('SELECT 1 FROM account WHERE slug = ?', [$slug]) !== false) {
            throw new SignupException('SLUG_TAKEN', 'Ese identificador de salón ya está en uso.', 409);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $accountId = $this->db->transactional(function (Connection $tx) use (
            $businessName, $slug, $adminName, $adminEmail, $passwordHash, $locName, $locSlug, $tz
        ): int {
            $accountId = (int) $tx->fetchOne(
                "INSERT INTO account (name, slug, status) VALUES (?, ?, 'trial') RETURNING id",
                [$businessName, $slug]
            );
            $tx->executeStatement(
                "INSERT INTO subscription (account_id, plan_code, status) VALUES (?, 'free', 'trialing')",
                [$accountId]
            );
            $tx->executeStatement(
                'INSERT INTO location (account_id, name, slug, timezone, active) VALUES (?, ?, ?, ?, TRUE)',
                [$accountId, $locName, $locSlug, $tz]
            );
            $tx->executeStatement(
                "INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
                 VALUES (?, ?, ?, ?, 'admin_cadena', NULL, TRUE)",
                [$accountId, $adminName, $adminEmail, $passwordHash]
            );

            return $accountId;
        });

        // Email de bienvenida (best-effort: degrada a log sin transporte real).
        $this->email->send(
            $adminEmail,
            'Tu salón está listo en ' . $businessName,
            "¡Hola {$adminName}!\n\nHemos creado tu cuenta. Ya puedes entrar al panel con este email.\n\nUn saludo."
        );

        $session = $this->auth->login($adminEmail, $password);

        return [
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'user' => $session['user'],
            'account' => ['id' => $accountId, 'slug' => $slug],
        ];
    }
}
