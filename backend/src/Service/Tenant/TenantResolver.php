<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resuelve la cuenta (tenant) de una petición PÚBLICA (web/cliente) — multi-tenant
 * Fase 3, doc 15. La estrategia decidida es por **subdominio**: cada cuenta tiene
 * su `slug` y se sirve en `<slug>.dominio.tld`.
 *
 * Si no hay subdominio reconocible (apex, `localhost`, IP, `www`, o un subdominio
 * que no casa con ninguna cuenta), se cae en la **cuenta principal** (la de menor
 * id, creada en la Fase 1). Así el comportamiento mono-cadena actual y los tests
 * en `localhost` siguen funcionando sin cambios.
 *
 * El panel NO usa esto: allí el tenant viaja en el JWT (`account_id`).
 */
final class TenantResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Cuenta de la petición pública. Siempre devuelve una cuenta válida.
     */
    public function accountId(Request $request): int
    {
        $slug = $this->subdomain($request->getHost());
        if ($slug !== null) {
            $id = $this->db->fetchOne(
                "SELECT id FROM account WHERE slug = ? AND status <> 'cancelled'",
                [$slug]
            );
            if ($id !== false) {
                return (int) $id;
            }
        }

        return (int) $this->db->fetchOne('SELECT id FROM account ORDER BY id LIMIT 1');
    }

    /**
     * ¿La sede (activa) pertenece a la cuenta de la petición? Guarda de
     * aislamiento para los endpoints públicos que reciben un `location_id`.
     */
    public function locationInAccount(Request $request, int $locationId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM location WHERE id = ? AND account_id = ? AND active',
            [$locationId, $this->accountId($request)]
        ) !== false;
    }

    /**
     * Subdominio (primera etiqueta) cuando el host tiene dominio + TLD detrás
     * (`centro.reservas.app`). En apex, `localhost`, IP o `www` no hay subdominio.
     */
    private function subdomain(string $host): ?string
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $labels = explode('.', $host);
        if (count($labels) < 3) {
            return null;
        }
        $first = $labels[0];

        return ($first === '' || $first === 'www') ? null : $first;
    }
}
