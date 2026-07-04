<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Auth\TotpService;
use PHPUnit\Framework\TestCase;

/**
 * TOTP contra los vectores de prueba del RFC 6238 (secreto ASCII
 * "12345678901234567890" = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ en base32;
 * los códigos de 6 dígitos son el sufijo de los de 8 del RFC).
 */
final class TotpServiceTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TotpService $totp;

    protected function setUp(): void
    {
        $this->totp = new TotpService();
    }

    public function testVectoresDelRfc6238(): void
    {
        // (timestamp, código de 6 dígitos)
        $vectors = [
            [59, '287082'],
            [1111111109, '081804'],
            [1234567890, '005924'],
            [2000000000, '279037'],
        ];
        foreach ($vectors as [$time, $code]) {
            self::assertTrue($this->totp->verify(self::RFC_SECRET, $code, $time), "t={$time}");
        }
    }

    public function testRechazaCodigosIncorrectosOMalformados(): void
    {
        self::assertFalse($this->totp->verify(self::RFC_SECRET, '000000', 59));
        self::assertFalse($this->totp->verify(self::RFC_SECRET, '28708', 59)); // 5 dígitos
        self::assertFalse($this->totp->verify(self::RFC_SECRET, 'abcdef', 59));
        self::assertFalse($this->totp->verify('secreto-invalido!', '287082', 59));
    }

    public function testToleraUnPasoDeDesfaseDeReloj(): void
    {
        // El código de t=59 sigue valiendo 30 s después (ventana ±1)…
        self::assertTrue($this->totp->verify(self::RFC_SECRET, '287082', 59 + 30));
        // …pero no 2 pasos después.
        self::assertFalse($this->totp->verify(self::RFC_SECRET, '287082', 59 + 61));
    }

    public function testGeneraSecretosBase32Validos(): void
    {
        $a = $this->totp->generateSecret();
        $b = $this->totp->generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a); // 20 bytes → 32 chars
        self::assertNotSame($a, $b);

        // Un secreto recién generado funciona de punta a punta con su URI.
        $uri = $this->totp->otpauthUri($a, 'dueña@salon.es');
        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=' . $a, $uri);
    }
}
