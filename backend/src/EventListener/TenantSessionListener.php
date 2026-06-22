<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Tenant\TenantResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fija `app.account_id` en la sesión de BD de cada petición de API, para que las
 * políticas de Row-Level Security (migración 0017, doc 15 Fase 4) acoten las
 * filas a la cuenta. El tenant sale del JWT en el panel y del subdominio en la
 * web pública.
 *
 * Prioridad 4: después del AdminAuthListener (8), para leer ya el `auth_user`.
 *
 * Es INOCUO mientras la app se conecte como OWNER de la BD (que ignora RLS): el
 * `SET` simplemente no tiene efecto. RLS se activa al conectar como el rol
 * `peluqueria_app` (DATABASE_URL). Nunca rompe la petición: ante cualquier fallo
 * (BD no lista, etc.) se ignora.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class TenantSessionListener
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantResolver $tenant,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getResponse() !== null) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        try {
            $user = $request->attributes->get(AdminAuthListener::ATTR);
            if (is_array($user) && isset($user['account_id'])) {
                $accountId = (int) $user['account_id'];
            } elseif (!str_starts_with($path, '/api/v1/admin')) {
                $accountId = $this->tenant->accountId($request); // web pública por subdominio
            } else {
                return; // ruta admin sin sesión válida: el auth listener ya respondió
            }

            $this->db->executeStatement("SELECT set_config('app.account_id', ?, false)", [(string) $accountId]);
        } catch (\Throwable) {
            // Defensa: jamás bloquea la petición (bajo el owner RLS se ignora de todos modos).
        }
    }
}
