<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Recurring\RecurringService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Genera las próximas citas de las recurrencias activas (doc 13). Para cron
 * (p. ej. diario). Idempotente: no duplica citas ya generadas.
 *
 *   php bin/console app:recurring:generate
 */
#[AsCommand(
    name: 'app:recurring:generate',
    description: 'Crea la próxima cita de cada recurrencia activa que toque.',
)]
final class RecurringGenerateCommand extends Command
{
    public function __construct(private readonly RecurringService $recurring)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Cuenta sin crear nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $r = $this->recurring->generateDue((bool) $input->getOption('dry-run'));

        $io->success(sprintf(
            '%s %d cita(s); %d sin generar (fuera de plazo o sin hueco).',
            $input->getOption('dry-run') ? '[DRY] se crearían' : 'Creadas',
            $r['created'],
            $r['skipped']
        ));

        return Command::SUCCESS;
    }
}
