<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * TOTP (RFC 6238) sin dependencias: doble factor con cualquier app de
 * autenticación (Google Authenticator, Aegis, 1Password…). Códigos de 6
 * dígitos en pasos de 30 s, con ventana de ±1 paso para tolerar desfase
 * de reloj. Comparación en tiempo constante.
 */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32 RFC 4648
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1; // pasos de tolerancia a cada lado

    /** Secreto nuevo (20 bytes aleatorios) en base32, listo para la app. */
    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($value >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($value << (5 - $bits)) & 31];
        }

        return $out;
    }

    /** ¿Es válido el código para este secreto ahora (o en la ventana ±1)? */
    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return false;
        }
        $key = $this->base32Decode($secret);
        if ($key === null) {
            return false;
        }

        $step = intdiv($timestamp ?? time(), self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals($this->hotp($key, $step + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Código vigente para un secreto (el que mostraría la app ahora mismo).
     *
     * @throws \InvalidArgumentException si el secreto no es base32 válido
     */
    public function code(string $secret, ?int $timestamp = null): string
    {
        $key = $this->base32Decode($secret);
        if ($key === null) {
            throw new \InvalidArgumentException('Secreto TOTP inválido.');
        }

        return $this->hotp($key, intdiv($timestamp ?? time(), self::PERIOD));
    }

    /** URI otpauth:// para enlazar la app de autenticación. */
    public function otpauthUri(string $secret, string $email, string $issuer = 'Peluquería SaaS'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /** Código HOTP (RFC 4226) de 6 dígitos para un contador. */
    private function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $b32): ?string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        if ($b32 === '') {
            return null;
        }
        $bits = 0;
        $value = 0;
        $out = '';
        foreach (str_split($b32) as $char) {
            $idx = strpos(self::ALPHABET, $char);
            if ($idx === false) {
                return null;
            }
            $value = ($value << 5) | $idx;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($value >> $bits) & 255);
            }
        }

        return $out;
    }
}
