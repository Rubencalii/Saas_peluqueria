<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WhatsApp\WhatsAppMessenger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Felicitación de cumpleaños por WhatsApp (cron diario):
 *
 *   php bin/console app:notifications:birthdays
 *
 * Felicita a los clientes que cumplen años HOY, con consentimiento de
 * WhatsApp y teléfono. Idempotente por año (birthday_greeted_on): repetir la
 * ejecución el mismo día no duplica mensajes. Un toque personal que trae
 * repetición sin trabajo del salón.
 */
#[AsCommand(
    name: 'app:notifications:birthdays',
    description: 'Felicita por WhatsApp a los clientes que cumplen años hoy.',
)]
final class BirthdayGreetingsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly WhatsAppMessenger $wa,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra a quién se felicitaría sin enviar ni marcar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Cumplen hoy, con consentimiento y sin felicitar todavía este año.
        $due = $this->db->fetchAllAssociative(
            "SELECT c.id, c.name, c.phone, a.name AS account_name
               FROM customer c
               JOIN account a ON a.id = c.account_id
              WHERE c.birthday IS NOT NULL
                AND EXTRACT(MONTH FROM c.birthday) = EXTRACT(MONTH FROM current_date)
                AND EXTRACT(DAY FROM c.birthday) = EXTRACT(DAY FROM current_date)
                AND c.wa_consent AND c.phone <> ''
                AND (c.birthday_greeted_on IS NULL
                     OR EXTRACT(YEAR FROM c.birthday_greeted_on) < EXTRACT(YEAR FROM current_date))
              ORDER BY c.id"
        );

        if ($due === []) {
            $io->success('Hoy no cumple años ningún cliente pendiente de felicitar.');

            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($due as $c) {
            if ($dryRun) {
                $io->writeln(sprintf('[DRY] #%d %s (%s)', (int) $c['id'], (string) $c['name'], (string) $c['account_name']));
                ++$sent;

                continue;
            }

            $message = sprintf(
                "¡Feliz cumpleaños, %s! 🎂✨\nDe parte de todo el equipo de %s. "
                . 'Si te apetece celebrarlo con un cambio de look, escríbenos "menú" y te buscamos hueco. 💇',
                (string) $c['name'],
                (string) $c['account_name'],
            );
            if ($this->wa->sendText(ltrim((string) $c['phone'], '+'), $message)) {
                $this->db->executeStatement(
                    'UPDATE customer SET birthday_greeted_on = current_date WHERE id = ?',
                    [(int) $c['id']]
                );
                ++$sent;
            }
        }

        $io->success(sprintf('%s%d felicitación(es) de %d cumpleaños de hoy.', $dryRun ? '[DRY] ' : '', $sent, count($due)));

        return Command::SUCCESS;
    }
}
