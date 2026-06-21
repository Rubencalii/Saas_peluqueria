<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recordatorio de retorno "te toca volver" (doc 13 §2.3).
 *
 * Pensado para cron (p. ej. diario). Busca clientes cuya ÚLTIMA cita fue una
 * visita completada hace ~N semanas (sin ninguna cita posterior), con
 * consentimiento de marketing, y les programa una notificación de retención.
 * La ventana evita reenviar a clientes lapsados desde hace mucho y la
 * comprobación de duplicados evita programar dos veces la misma.
 *
 *   php bin/console app:notifications:return-reminders --weeks=6
 */
#[AsCommand(
    name: 'app:notifications:return-reminders',
    description: 'Programa recordatorios de retorno para clientes que no vuelven hace ~N semanas.',
)]
final class ReturnReminderCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('weeks', null, InputOption::VALUE_REQUIRED, 'Semanas desde la última visita', '6')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Ventana en días para captar al cliente', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra a quién se avisaría sin programar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $weeks = max(1, (int) $input->getOption('weeks'));
        $window = max(1, (int) $input->getOption('window'));
        $dryRun = (bool) $input->getOption('dry-run');

        // Última cita por cliente; nos quedamos con quienes su última cita fue
        // completada dentro de la ventana [ahora-(semanas+ventana), ahora-semanas].
        $candidates = $this->db->fetchAllAssociative(
            "WITH last_appt AS (
                SELECT DISTINCT ON (a.customer_id)
                       a.id, a.customer_id, a.start_at, a.status
                  FROM appointment a
                 ORDER BY a.customer_id, a.start_at DESC
             )
             SELECT la.id AS appointment_id, c.name
               FROM last_appt la
               JOIN customer c ON c.id = la.customer_id
              WHERE la.status = 'completada'
                AND c.wa_consent
                AND la.start_at <= now() - make_interval(weeks => ?)
                AND la.start_at >  now() - make_interval(weeks => ?) - make_interval(days => ?)
                AND NOT EXISTS (
                    SELECT 1 FROM notification n
                     WHERE n.appointment_id = la.id AND n.template_name = 'recordatorio_retorno'
                )
              ORDER BY la.start_at",
            [$weeks, $weeks, $window]
        );

        if ($candidates === []) {
            $io->success('No hay clientes a los que avisar.');

            return Command::SUCCESS;
        }

        foreach ($candidates as $row) {
            if ($dryRun) {
                $io->writeln(sprintf('[DRY] cita #%d · %s', (int) $row['appointment_id'], (string) $row['name']));

                continue;
            }
            $this->notifications->scheduleReturnReminder((int) $row['appointment_id']);
        }

        $io->success(sprintf(
            '%s %d recordatorio(s) de retorno (semanas=%d, ventana=%d días).',
            $dryRun ? '[DRY] se programarían' : 'Programados',
            count($candidates),
            $weeks,
            $window
        ));

        return Command::SUCCESS;
    }
}
