<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Notification\NotificationService;
use App\Service\WhatsApp\WhatsAppMessenger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Despacha las notificaciones vencidas (docs/07 §5 "worker de jobs").
 *
 * Pensado para ejecutarse periódicamente por cron (p. ej. cada minuto):
 *   php bin/console app:notifications:dispatch
 *
 * Coge las filas `programada` con scheduled_at <= ahora, las envía por WhatsApp
 * (requiere consentimiento del cliente) y marca el resultado (enviada/fallida).
 * Las notificaciones obsoletas (recordatorio de una cita ya cancelada) se
 * descartan en silencio.
 */
#[AsCommand(
    name: 'app:notifications:dispatch',
    description: 'Envía las notificaciones programadas que ya han vencido.',
)]
final class NotificationDispatchCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly NotificationService $notifications,
        private readonly WhatsAppMessenger $wa,
        private readonly \App\Service\Email\EmailSender $email,
    ) {
        parent::__construct();
    }

    /** Asunto del email según el tipo de notificación. */
    private const SUBJECTS = [
        'confirmacion' => 'Tu cita está confirmada',
        'recordatorio' => 'Recordatorio de tu cita',
        'cambio' => 'Novedad sobre tu cita',
        'seguimiento' => '¡Gracias por tu visita!',
    ];

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de notificaciones por ejecución', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra qué se enviaría sin enviar ni marcar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $due = $this->db->fetchAllAssociative(
            "SELECT n.id, n.type, n.template_name, a.status AS appointment_status, a.start_at,
                    c.name, c.phone, c.email, c.wa_consent,
                    s.name AS service_name, l.name AS location_name, l.timezone, ac.locale
               FROM notification n
               JOIN appointment a ON a.id = n.appointment_id
               JOIN customer c    ON c.id = a.customer_id
               JOIN service  s    ON s.id = a.service_id
               JOIN location l    ON l.id = a.location_id
               JOIN account  ac   ON ac.id = l.account_id
              WHERE n.status = 'programada' AND n.scheduled_at <= now()
              ORDER BY n.scheduled_at
              LIMIT ?",
            [$limit]
        );

        if ($due === []) {
            $io->success('No hay notificaciones pendientes.');

            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($due as $n) {
            $id = (int) $n['id'];
            $type = (string) $n['type'];

            if (!$this->isRelevant($type, (string) $n['appointment_status'])) {
                // Obsoleta (p. ej. recordatorio de una cita cancelada): descartar.
                if (!$dryRun) {
                    $this->db->executeStatement('DELETE FROM notification WHERE id = ?', [$id]);
                }
                ++$skipped;

                continue;
            }

            $body = $this->notifications->render([
                'type' => $type,
                'template' => $n['template_name'] !== null ? (string) $n['template_name'] : '',
                'status' => (string) $n['appointment_status'],
                'name' => (string) $n['name'],
                'location_name' => (string) $n['location_name'],
                'start_at' => (string) $n['start_at'],
                'service_name' => (string) $n['service_name'],
                'timezone' => (string) $n['timezone'],
                'locale' => (string) $n['locale'],
            ]);

            $phone = (string) $n['phone'];
            $emailAddr = $n['email'] !== null ? (string) $n['email'] : '';
            $canWhatsApp = (bool) $n['wa_consent'] && $phone !== '';

            if ($dryRun) {
                $via = $canWhatsApp ? 'whatsapp' : ($emailAddr !== '' ? 'email' : 'sin canal');
                $io->writeln(sprintf('[DRY] #%d %s → %s', $id, $type, $via));
                ++$sent;

                continue;
            }

            // WhatsApp como canal principal (requiere consentimiento); email de respaldo.
            $ok = $canWhatsApp && $this->wa->sendText(ltrim($phone, '+'), $body);
            if (!$ok && $emailAddr !== '') {
                $subject = self::SUBJECTS[$type] ?? 'Información de tu cita';
                $ok = $this->email->send($emailAddr, $subject, $body);
            }

            $this->db->executeStatement(
                'UPDATE notification SET status = ?, sent_at = CASE WHEN ? THEN now() ELSE sent_at END WHERE id = ?',
                [$ok ? 'enviada' : 'fallida', $ok, $id],
                [1 => \Doctrine\DBAL\ParameterType::BOOLEAN]
            );
            $ok ? $sent++ : $failed++;
        }

        $io->success(sprintf('Procesadas %d · enviadas %d · fallidas %d · descartadas %d', count($due), $sent, $failed, $skipped));

        return Command::SUCCESS;
    }

    /**
     * ¿La notificación sigue teniendo sentido según el estado de la cita?
     * - confirmación/recordatorio: la cita debe seguir activa.
     * - seguimiento: la cita debe estar completada.
     * - cambio (incluye cancelación): siempre se envía.
     */
    private function isRelevant(string $type, string $appointmentStatus): bool
    {
        return match ($type) {
            'confirmacion', 'recordatorio' => in_array($appointmentStatus, ['pendiente', 'confirmada'], true),
            'seguimiento' => $appointmentStatus === 'completada',
            default => true,
        };
    }
}
