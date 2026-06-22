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
    ) {
    }

    /**
     * Valida credenciales y devuelve token + datos del usuario.
     *
     * @return array{token: string, expires_at: string, user: array{id: int, name: string, email: string, role: string, location_id: int|null}}
     *
     * @throws AuthException
     */
    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '') {
            throw new AuthException('VALIDATION', 'Email y contraseña son obligatorios.', 400);
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, password_hash, role, location_id, active
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

        $context = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'location_id' => $user['location_id'] !== null ? (int) $user['location_id'] : null,
        ];

        $exp = time() + self::TTL_SECONDS;

        return [
            'token' => $this->issue($context, $exp),
            'expires_at' => (new \DateTimeImmutable('@' . $exp))->format('c'),
            'user' => $context,
        ];
    }

    /**
     * Decodifica y verifica un token. Devuelve el contexto del usuario.
     *
     * @return array{id: int, name: string, email: string, role: string, location_id: int|null}
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

        return [
            'id' => (int) ($claims['sub'] ?? 0),
            'name' => (string) ($claims['name'] ?? ''),
            'email' => (string) ($claims['email'] ?? ''),
            'role' => (string) $claims['role'],
            'location_id' => isset($claims['loc']) ? (int) $claims['loc'] : null,
        ];
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
     * @param array{id: int, name: string, email: string, role: string, location_id: int|null} $ctx
     */
    private function issue(array $ctx, int $exp): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'sub' => $ctx['id'],
            'name' => $ctx['name'],
            'email' => $ctx['email'],
            'role' => $ctx['role'],
            'loc' => $ctx['location_id'],
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
