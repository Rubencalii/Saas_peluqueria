<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use Doctrine\DBAL\Connection;

/**
 * Marca (white-label) por cuenta: nombre visible, color de marca, color de
 * acento y logo (doc 08). Se aplica a la web de reserva y al panel. Campos
 * nullable → null = tema por defecto.
 */
final class BrandingService
{
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Marca de una cuenta. `name` es el nombre real de la cuenta (fallback de
     * `display_name`).
     *
     * @return array{name: string, display_name: string|null, brand_color: string|null, accent_color: string|null, logo_url: string|null}|null
     */
    public function get(int $accountId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT name, display_name, brand_color, accent_color, logo_url FROM account WHERE id = ?',
            [$accountId]
        );
        if ($row === false) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'brand_color' => $row['brand_color'] !== null ? (string) $row['brand_color'] : null,
            'accent_color' => $row['accent_color'] !== null ? (string) $row['accent_color'] : null,
            'logo_url' => $row['logo_url'] !== null ? (string) $row['logo_url'] : null,
        ];
    }

    /**
     * Actualiza la marca de la cuenta. Sólo toca los campos presentes en $input.
     * Cada campo admite su valor o null (para restablecer al tema por defecto).
     *
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException
     */
    public function update(int $accountId, array $input): void
    {
        $sets = [];
        $params = [];

        if (array_key_exists('display_name', $input)) {
            $v = $input['display_name'];
            $name = $v === null ? null : trim((string) $v);
            if ($name !== null && ($name === '' || mb_strlen($name) > 60)) {
                throw new \InvalidArgumentException('El nombre debe tener entre 1 y 60 caracteres.');
            }
            $sets[] = 'display_name = ?';
            $params[] = $name;
        }

        foreach (['brand_color', 'accent_color'] as $field) {
            if (array_key_exists($field, $input)) {
                $v = $input[$field];
                $color = $v === null || $v === '' ? null : (string) $v;
                if ($color !== null && preg_match(self::HEX, $color) !== 1) {
                    throw new \InvalidArgumentException('Los colores deben ser hexadecimales (#rrggbb).');
                }
                $sets[] = "$field = ?";
                $params[] = $color;
            }
        }

        if (array_key_exists('logo_url', $input)) {
            $v = $input['logo_url'];
            $url = $v === null || $v === '' ? null : trim((string) $v);
            if ($url !== null && !$this->validLogo($url)) {
                throw new \InvalidArgumentException('El logo debe ser una imagen (subida) o una URL http(s) válida.');
            }
            $sets[] = 'logo_url = ?';
            $params[] = $url;
        }

        if ($sets === []) {
            throw new \InvalidArgumentException('Nada que actualizar.');
        }

        $params[] = $accountId;
        $this->db->executeStatement('UPDATE account SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /**
     * El logo puede ser una URL http(s) (≤500) o una imagen subida como data-URL
     * en base64 (png/jpeg/webp/svg, hasta ~450 KB de payload).
     */
    private function validLogo(string $url): bool
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return mb_strlen($url) <= 500;
        }
        if (preg_match('#^data:image/(png|jpe?g|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$#i', $url) === 1) {
            return mb_strlen($url) <= 620000;
        }

        return false;
    }
}
