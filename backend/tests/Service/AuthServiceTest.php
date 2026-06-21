<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Auth del panel: login contra los usuarios reales del seed, emisión/verificación
 * del JWT propio y autorización por rol/sede. Usa el AuthService del contenedor
 * (con el APP_SECRET configurado) en vez de mockear la conexión.
 *
 * Usuarios del seed: admin@salon.es/admin1234 (admin_cadena, sin sede) y
 * recepcion@salon.es/recepcion1234 (recepcion, sede 1).
 */
final class AuthServiceTest extends KernelTestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var AuthService $svc */
        $svc = static::getContainer()->get(AuthService::class);
        $this->auth = $svc;
    }

    public function testLoginCorrectoEmiteTokenVerificable(): void
    {
        $result = $this->auth->login('recepcion@salon.es', 'recepcion1234');

        self::assertArrayHasKey('token', $result);
        self::assertSame('recepcion', $result['user']['role']);
        self::assertSame(1, $result['user']['location_id']);

        // El token emitido debe verificar y devolver el mismo contexto.
        $claims = $this->auth->verify($result['token']);
        self::assertSame($result['user']['id'], $claims['id']);
        self::assertSame('recepcion', $claims['role']);
        self::assertSame(1, $claims['location_id']);
    }

    public function testLoginAdminCadenaSinSede(): void
    {
        $result = $this->auth->login('admin@salon.es', 'admin1234');

        self::assertSame('admin_cadena', $result['user']['role']);
        self::assertNull($result['user']['location_id']);
    }

    public function testLoginContrasenaIncorrectaLanza401(): void
    {
        $this->expectException(AuthException::class);
        $this->auth->login('admin@salon.es', 'incorrecta');
    }

    public function testLoginUsuarioInexistenteLanza401(): void
    {
        $this->expectException(AuthException::class);
        $this->auth->login('nadie@salon.es', 'lo-que-sea');
    }

    public function testVerifyRechazaTokenManipulado(): void
    {
        $token = $this->auth->login('admin@salon.es', 'admin1234')['token'];

        $this->expectException(AuthException::class);
        $this->auth->verify($token . 'tampered');
    }

    public function testVerifyRechazaTokenMalFormado(): void
    {
        $this->expectException(AuthException::class);
        $this->auth->verify('esto-no-es-un-jwt');
    }

    public function testAssertLocationCadenaAccedeATodas(): void
    {
        $this->auth->assertLocation(['role' => 'admin_cadena', 'location_id' => null], 99);
        $this->expectNotToPerformAssertions();
    }

    public function testAssertLocationOtraSedeProhibida(): void
    {
        $this->expectException(AuthException::class);
        $this->auth->assertLocation(['role' => 'admin_sede', 'location_id' => 1], 2);
    }

    public function testResolveLocationUsaLaDelUsuarioSiNoSeIndica(): void
    {
        self::assertSame(1, $this->auth->resolveLocation(['role' => 'recepcion', 'location_id' => 1], null));
        self::assertNull($this->auth->resolveLocation(['role' => 'admin_cadena', 'location_id' => null], null));
    }

    public function testAssertRoleRechazaRolNoAutorizado(): void
    {
        $this->expectException(AuthException::class);
        $this->auth->assertRole(['role' => 'recepcion'], ['admin_cadena']);
    }
}
