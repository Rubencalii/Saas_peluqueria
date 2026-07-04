<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registro de actividad del panel (doc 09 §6): traza las acciones de escritura
 * sobre `/api/v1/admin` y `/api/v1/superadmin` (quién, método, ruta, resultado).
 * Las de plataforma (suspender cuentas, cambiar planes, impersonar) son las más
 * sensibles del sistema: siempre dejan rastro.
 *
 * Se ejecuta en `kernel.terminate` (después de responder), así que no añade
 * latencia, y nunca rompe la petición (todo va en try/catch).
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class AuditListener
{
    private const WRITE_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $method = $request->getMethod();

        $path = $request->getPathInfo();
        $audited = str_starts_with($path, '/api/v1/admin') || str_starts_with($path, '/api/v1/superadmin');
        if (!in_array($method, self::WRITE_METHODS, true) || !$audited) {
            return;
        }

        /** @var array{id?: int, email?: string} $user */
        $user = $request->attributes->get(AdminAuthListener::ATTR, []);

        try {
            $this->db->executeStatement(
                'INSERT INTO audit_log (user_id, user_email, method, path, status_code) VALUES (?, ?, ?, ?, ?)',
                [
                    $user['id'] ?? null,
                    $user['email'] ?? null,
                    $method,
                    $request->getPathInfo(),
                    $event->getResponse()->getStatusCode(),
                ]
            );
        } catch (\Throwable) {
            // La auditoría nunca debe afectar a la petición.
        }
    }
}
