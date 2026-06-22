<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Auth\PasswordResetService;

/**
 * Reset de contraseña del panel (doc 14 §9). Usa el usuario recepción del seed;
 * el cambio se revierte por la transacción de DatabaseTestCase.
 */
final class PasswordResetServiceTest extends DatabaseTestCase
{
    private PasswordResetService $reset;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var PasswordResetService $r */
        $r = $this->service(PasswordResetService::class);
        /** @var AuthService $a */
        $a = $this->service(AuthService::class);
        $this->reset = $r;
        $this->auth = $a;
    }

    public function testEmailDesconocidoNoGeneraToken(): void
    {
        self::assertNull($this->reset->request('nadie@salon.es'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM password_reset'));
    }

    public function testFlujoCompletoCambiaLaContrasena(): void
    {
        $token = $this->reset->request('recepcion@salon.es');
        self::assertNotNull($token);

        $this->reset->reset($token, 'NuevaClave123');

        // La nueva contraseña funciona y el token queda inutilizable.
        $login = $this->auth->login('recepcion@salon.es', 'NuevaClave123');
        self::assertSame('recepcion', $login['user']['role']);

        $this->expectException(AuthException::class);
        $this->reset->reset($token, 'OtraClave123');
    }

    public function testContrasenaCortaLanza(): void
    {
        $token = $this->reset->request('recepcion@salon.es');
        self::assertNotNull($token);

        $this->expectException(AuthException::class);
        $this->reset->reset($token, '123');
    }

    public function testTokenInvalidoLanza(): void
    {
        $this->expectException(AuthException::class);
        $this->reset->reset('token-que-no-existe', 'NuevaClave123');
    }
}
