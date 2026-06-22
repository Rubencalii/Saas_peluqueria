<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use Doctrine\DBAL\Connection;

/**
 * Límites de plan por cuenta (multi-tenant Fase 5, doc 15). Cada plan define un
 * máximo de sedes/profesionales (NULL = ilimitado); estos guards los aplican los
 * controladores del panel antes de crear, devolviendo 402 al superarlos.
 */
final class PlanLimitService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** ¿La cuenta ya alcanzó el máximo de sedes de su plan? */
    public function locationLimitReached(int $accountId): bool
    {
        return $this->limitReached($accountId, 'max_locations', 'location');
    }

    /** ¿La cuenta ya alcanzó el máximo de profesionales de su plan? */
    public function staffLimitReached(int $accountId): bool
    {
        return $this->limitReached($accountId, 'max_staff', 'staff');
    }

    private function limitReached(int $accountId, string $limitColumn, string $table): bool
    {
        $max = $this->db->fetchOne(
            "SELECT p.$limitColumn FROM subscription s JOIN plan p ON p.code = s.plan_code WHERE s.account_id = ?",
            [$accountId]
        );
        // Sin suscripción o límite NULL = ilimitado.
        if ($max === false || $max === null) {
            return false;
        }

        $count = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $table WHERE account_id = ?", [$accountId]);

        return $count >= (int) $max;
    }
}
