<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Doctrine\DBAL\Connection;

/**
 * Autenticación y autorización del panel interno (docs/06 §1, §5).
 *
 * Emite y valida un JWT propio firmado con HMAC-SHA256 (HS256). Se implementa
 * a mano para no añadir bundles de seguridad/JWT: el proyecto usa Doctrine DBAL
 * directo y mantiene las dependencias al mínimo. El secreto es APP_SECRET.
 *
 * Las contraseñas del seed son bcrypt (`crypt(..., gen_salt('bf'))` → `$2a$`),
 * así que `password_verify` las valida de forma nativa sin más configuración.
 *
 * El token transporta: sub (id de usuario), role, loc (location_id o null),
 * name, exp. La autorización por sede se centraliza en assertLocation().
 */
final class AuthService
{
    /** Validez del token en segundos (8 h: una jornada del salón). */
    private const TTL_SECONDS = 8 * 3600;

    /** Roles válidos (enum user_role en BD). */
    private const ROLES = ['recepcion', 'profesional', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly Connection $db,
        private readonly string $secret,
        private readonly TotpService $totp,
        bool $debug = true,
    ) {
        // Defensa anti-forja: en producción (sin debug) no se arranca con un
        // APP_SECRET vacío, corto o un placeholder conocido del repositorio. Sin
        // esto, quien conozca el secreto podría firmar tokens (incluido super-admin).
        if (!$debug && $this->secretIsInsecure()) {
            throw new \RuntimeException(
                'APP_SECRET inseguro o por defecto en producción. Configura un secreto único y aleatorio de ≥32 caracteres (variable de entorno o gestor de secretos).'
            );
        }
    }

    /**
     * ¿El secreto de firma es débil/por defecto? (vacío, corto o un placeholder
     * conocido). Lo usa también el AdminAuthListener para cortar el acceso al
     * panel desde un host NO local aunque el entorno no sea `prod` (despliegue
     * mal configurado como dev).
     */
    public function secretIsInsecure(): bool
    {
        return mb_strlen($this->secret) < 32
            || preg_match('/(placeholder|insecure|change.?me|example|secret|test)/i', $this->secret) === 1;
    }

    /**
     * Valida credenciales (y el código TOTP si el usuario tiene 2FA) y
     * devuelve token + datos del usuario.
     *
     * @return array{token: string, expires_at: string, user: array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool}}
     *
     * @throws AuthException
     */
    public function login(string $email, string $password, ?string $totpCode = null): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '') {
            throw new AuthException('VALIDATION', 'Email y contraseña son obligatorios.', 400);
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, password_hash, role, location_id, account_id, token_version, is_superadmin, active, totp_secret
               FROM app_user WHERE email = ?',
            [$email]
        );

        // Mensaje uniforme y verificación siempre ejecutada para no filtrar qué
        // emails existen ni dar pistas por tiempo de respuesta.
        $hash = $user !== false ? (string) $user['password_hash'] : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok = password_verify($password, $hash);

        if ($user === false || !$ok || !$user['active']) {
            throw new AuthException('INVALID_CREDENTIALS', 'Email o contraseña incorrectos.', 401);
        }

        // Doble factor: con 2FA activo, la contraseña sola no basta. El código
        // solo se pide una vez validada la contraseña (no filtra quién tiene 2FA
        // sin credenciales correctas).
        if ($user['totp_secret'] !== null) {
            if ($totpCode === null || trim($totpCode) === '') {
                throw new AuthException('TOTP_REQUIRED', 'Introduce el código de tu app de autenticación.', 401);
            }
            if (!$this->totp->verify((string) $user['totp_secret'], $totpCode)) {
                throw new AuthException('TOTP_INVALID', 'Código de verificación incorrecto.', 401);
            }
        }

        $context = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'location_id' => $user['location_id'] !== null ? (int) $user['location_id'] : null,
            'account_id' => (int) $user['account_id'],
            'is_superadmin' => (bool) $user['is_superadmin'],
        ];

        $exp = time() + self::TTL_SECONDS;

        return [
            'token' => $this->issue($context, $exp, (int) $user['token_version']),
            'expires_at' => (new \DateTimeImmutable('@' . $exp))->format('c'),
            'user' => $context,
        ];
    }

    /**
     * Decodifica y verifica un token. Devuelve el contexto del usuario.
     *
     * @return array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool}
     *
     * @throws AuthException
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthException('INVALID_TOKEN', 'Token mal formado.', 401);
        }
        [$h, $p, $sig] = $parts;

        $expected = $this->sign($h . '.' . $p);
        if (!hash_equals($expected, $sig)) {
            throw new AuthException('INVALID_TOKEN', 'Firma del token inválida.', 401);
        }

        $claims = json_decode($this->b64UrlDecode($p), true);
        if (!is_array($claims)) {
            throw new AuthException('INVALID_TOKEN', 'Token ilegible.', 401);
        }
        if ((int) ($claims['exp'] ?? 0) < time()) {
            throw new AuthException('TOKEN_EXPIRED', 'La sesión ha caducado, vuelve a iniciar sesión.', 401);
        }
        if (!in_array($claims['role'] ?? null, self::ROLES, true)) {
            throw new AuthException('INVALID_TOKEN', 'Rol inválido en el token.', 401);
        }

        // Revocación: si la versión de token del usuario avanzó respecto a la del
        // token, la sesión fue cerrada (logout / cambio de contraseña).
        $currentVersion = $this->db->fetchOne(
            'SELECT token_version FROM app_user WHERE id = ?',
            [(int) ($claims['sub'] ?? 0)]
        );
        if ($currentVersion !== false && (int) ($claims['tv'] ?? 0) < (int) $currentVersion) {
            throw new AuthException('TOKEN_REVOKED', 'La sesión ha sido cerrada, vuelve a iniciar sesión.', 401);
        }

        return [
            'id' => (int) ($claims['sub'] ?? 0),
            'name' => (string) ($claims['name'] ?? ''),
            'email' => (string) ($claims['email'] ?? ''),
            'role' => (string) $claims['role'],
            'location_id' => isset($claims['loc']) ? (int) $claims['loc'] : null,
            'account_id' => (int) ($claims['acc'] ?? 0),
            'is_superadmin' => (bool) ($claims['sa'] ?? false),
        ];
    }

    /**
     * Renueva la sesión: a partir de un token válido emite otro fresco (TTL
     * completo). El refresco respeta la revocación (verify ya la comprueba).
     *
     * @return array{token: string, expires_at: string, user: array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool}}
     *
     * @throws AuthException
     */
    public function refresh(string $token): array
    {
        $context = $this->verify($token); // valida firma, expiración y revocación
        $version = (int) $this->db->fetchOne('SELECT token_version FROM app_user WHERE id = ?', [$context['id']]);
        $exp = time() + self::TTL_SECONDS;

        return [
            'token' => $this->issue($context, $exp, $version),
            'expires_at' => (new \DateTimeImmutable('@' . $exp))->format('c'),
            'user' => $context,
        ];
    }

    /**
     * Emite una sesión para OTRO usuario (impersonación del superadmin para
     * dar soporte). El permiso lo comprueba quien llama; aquí solo se valida
     * que el usuario exista y esté activo. La petición queda registrada en el
     * audit_log a nombre del superadmin (AuditListener).
     *
     * @return array{token: string, expires_at: string, user: array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool}}
     *
     * @throws AuthException
     */
    public function impersonate(int $userId): array
    {
        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, role, location_id, account_id, token_version, is_superadmin, active
               FROM app_user WHERE id = ?',
            [$userId]
        );
        if ($user === false || !$user['active']) {
            throw new AuthException('NOT_FOUND', 'Usuario no disponible.', 404);
        }

        $context = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'location_id' => $user['location_id'] !== null ? (int) $user['location_id'] : null,
            'account_id' => (int) $user['account_id'],
            'is_superadmin' => (bool) $user['is_superadmin'],
        ];
        $exp = time() + self::TTL_SECONDS;

        return [
            'token' => $this->issue($context, $exp, (int) $user['token_version']),
            'expires_at' => (new \DateTimeImmutable('@' . $exp))->format('c'),
            'user' => $context,
        ];
    }

    /**
     * Revoca todas las sesiones del usuario: los tokens emitidos hasta ahora
     * dejan de ser válidos (logout en todos los dispositivos).
     */
    public function revokeSessions(int $userId): void
    {
        $this->db->executeStatement('UPDATE app_user SET token_version = token_version + 1 WHERE id = ?', [$userId]);
    }

    /**
     * Comprueba que el usuario puede operar sobre una sede concreta.
     * admin_cadena accede a todas; el resto solo a la suya.
     *
     * @param array{role: string, location_id: int|null} $user
     *
     * @throws AuthException
     */
    public function assertLocation(array $user, int $locationId): void
    {
        if ($user['role'] === 'admin_cadena') {
            return;
        }
        if ($user['location_id'] !== $locationId) {
            throw new AuthException('FORBIDDEN', 'No tienes acceso a esa sede.', 403);
        }
    }

    /**
     * Multi-tenant: verifica que la sede pertenece a la cuenta del usuario.
     * Se usa además de assertLocation() porque admin_cadena pasa esa comprobación
     * para cualquier sede, pero nunca debe alcanzar la de OTRA cuenta. Devuelve
     * NOT_FOUND (no FORBIDDEN) para no revelar la existencia de sedes ajenas.
     *
     * @param array{account_id: int} $user
     *
     * @throws AuthException
     */
    public function assertLocationAccount(array $user, int $locationId): void
    {
        $accountId = $this->db->fetchOne('SELECT account_id FROM location WHERE id = ?', [$locationId]);
        if ($accountId === false || (int) $accountId !== $user['account_id']) {
            throw new AuthException('NOT_FOUND', 'Sede no encontrada.', 404);
        }
    }

    /**
     * Exige que el usuario tenga uno de los roles indicados.
     *
     * @param array{role: string} $user
     * @param list<string>        $roles
     *
     * @throws AuthException
     */
    public function assertRole(array $user, array $roles): void
    {
        if (!in_array($user['role'], $roles, true)) {
            throw new AuthException('FORBIDDEN', 'No tienes permiso para esta acción.', 403);
        }
    }

    /**
     * Sede efectiva para una consulta: la pedida (validada) o, si no se indica,
     * la del usuario. admin_cadena puede no tener sede → puede devolver null
     * (todas las sedes).
     *
     * @param array{role: string, location_id: int|null} $user
     *
     * @throws AuthException
     */
    public function resolveLocation(array $user, ?int $requested): ?int
    {
        if ($requested !== null) {
            $this->assertLocation($user, $requested);

            return $requested;
        }

        return $user['location_id'];
    }

    /**
     * @param array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool} $ctx
     */
    private function issue(array $ctx, int $exp, int $tokenVersion): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'sub' => $ctx['id'],
            'name' => $ctx['name'],
            'email' => $ctx['email'],
            'role' => $ctx['role'],
            'loc' => $ctx['location_id'],
            'acc' => $ctx['account_id'],
            'sa' => $ctx['is_superadmin'],
            'tv' => $tokenVersion,
            'iat' => time(),
            'exp' => $exp,
        ];

        $segments = $this->b64UrlEncode((string) json_encode($header, JSON_UNESCAPED_UNICODE))
            . '.' . $this->b64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $segments . '.' . $this->sign($segments);
    }

    private function sign(string $data): string
    {
        return $this->b64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }

    private function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
