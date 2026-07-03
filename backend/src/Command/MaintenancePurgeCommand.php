<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purga de datos técnicos caducados (doc 09: limitación del plazo de
 * conservación). Pensado para cron diario:
 *
 *   php bin/console app:maintenance:purge
 *
 * Borra:
 *  - password_reset usados o caducados (el token ya no sirve de nada);
 *  - audit_log con más antigüedad que la retención (por defecto 365 días);
 *  - wa_processed_message viejos (Meta solo reintenta durante horas/días);
 *  - idempotency_key antiguas (la ventana de reintento del cliente es corta).
 *
 * No toca datos de negocio (citas, clientes): eso es la anonimización RGPD.
 */
#[AsCommand(
    name: 'app:maintenance:purge',
    description: 'Borra datos técnicos caducados (tokens de reset, auditoría antigua, dedupe de WhatsApp).',
)]
final class MaintenancePurgeCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('audit-days', null, InputOption::VALUE_REQUIRED, 'Días de retención del registro de auditoría', '365')
            ->addOption('wa-days', null, InputOption::VALUE_REQUIRED, 'Días de retención del dedupe de mensajes de WhatsApp', '30')
            ->addOption('idem-days', null, InputOption::VALUE_REQUIRED, 'Días de retención de claves de idempotencia', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Cuenta lo que se borraría sin borrar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $auditDays = max(1, (int) $input->getOption('audit-days'));
        $waDays = max(1, (int) $input->getOption('wa-days'));
        $idemDays = max(1, (int) $input->getOption('idem-days'));
        $dryRun = (bool) $input->getOption('dry-run');

        $targets = [
            'password_reset (usados o caducados)' => [
                'DELETE FROM password_reset WHERE used_at IS NOT NULL OR expires_at < now()',
                'SELECT COUNT(*) FROM password_reset WHERE used_at IS NOT NULL OR expires_at < now()',
                [],
            ],
            "audit_log (> {$auditDays} días)" => [
                "DELETE FROM audit_log WHERE created_at < now() - make_interval(days => ?)",
                "SELECT COUNT(*) FROM audit_log WHERE created_at < now() - make_interval(days => ?)",
                [$auditDays],
            ],
            "wa_processed_message (> {$waDays} días)" => [
                'DELETE FROM wa_processed_message WHERE received_at < now() - make_interval(days => ?)',
                'SELECT COUNT(*) FROM wa_processed_message WHERE received_at < now() - make_interval(days => ?)',
                [$waDays],
            ],
            "idempotency_key (> {$idemDays} días)" => [
                'DELETE FROM idempotency_key WHERE created_at < now() - make_interval(days => ?)',
                'SELECT COUNT(*) FROM idempotency_key WHERE created_at < now() - make_interval(days => ?)',
                [$idemDays],
            ],
        ];

        $totalDeleted = 0;
        foreach ($targets as $label => [$deleteSql, $countSql, $params]) {
            if ($dryRun) {
                $count = (int) $this->db->fetchOne($countSql, $params);
                $io->writeln(sprintf('[DRY] %s: %d fila(s)', $label, $count));
                $totalDeleted += $count;

                continue;
            }

            $deleted = (int) $this->db->executeStatement($deleteSql, $params);
            $io->writeln(sprintf('%s: %d fila(s) borradas', $label, $deleted));
            $totalDeleted += $deleted;
        }

        $io->success(sprintf('%s%d fila(s) en total.', $dryRun ? '[DRY] Se borrarían ' : 'Borradas ', $totalDeleted));

        return Command::SUCCESS;
    }
}
