<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AvailabilityService;
use App\Service\WhatsApp\WhatsAppMessenger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Avisos de lista de espera (doc 13 §2.4).
 *
 * Pensado para cron (p. ej. cada pocos minutos). Recorre las entradas que
 * siguen 'esperando' y, usando el MISMO algoritmo de disponibilidad que la
 * reserva, comprueba si ya hay hueco para su servicio/profesional/día. Si lo
 * hay, avisa al cliente por WhatsApp y marca la entrada como 'avisado' (no se
 * vuelve a avisar). Cubre cualquier forma de liberar hueco: cancelación,
 * reprogramación o cambio de agenda.
 *
 *   php bin/console app:waitlist:notify
 */
#[AsCommand(
    name: 'app:waitlist:notify',
    description: 'Avisa a la lista de espera cuando se libera un hueco que les encaja.',
)]
final class WaitlistNotifyCommand extends Command
{
    private const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    /** Días por delante a explorar cuando el cliente no fijó una fecha concreta. */
    private const LOOKAHEAD_DAYS = 14;

    public function __construct(
        private readonly Connection $db,
        private readonly AvailabilityService $availability,
        private readonly WhatsAppMessenger $wa,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de entradas por ejecución', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra a quién se avisaría sin enviar ni marcar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $entries = $this->db->fetchAllAssociative(
            "SELECT w.id, w.location_id, w.service_id, w.staff_id, w.desired_date,
                    c.name AS customer_name, c.phone, c.wa_consent,
                    s.name AS service_name, l.name AS location_name, l.timezone
               FROM waitlist w
               JOIN customer c ON c.id = w.customer_id
               JOIN service  s ON s.id = w.service_id
               JOIN location l ON l.id = w.location_id
              WHERE w.status = 'esperando'
              ORDER BY w.created_at
              LIMIT ?",
            [$limit]
        );

        if ($entries === []) {
            $io->success('No hay nadie esperando.');

            return Command::SUCCESS;
        }

        $notified = 0;
        foreach ($entries as $e) {
            $slotDate = $this->firstDateWithSlot($e);
            if ($slotDate === null) {
                continue; // sigue sin hueco: permanece esperando
            }

            if ($dryRun) {
                $io->writeln(sprintf('[DRY] #%d %s → %s (%s)', (int) $e['id'], (string) $e['customer_name'], (string) $e['service_name'], $slotDate));
                ++$notified;

                continue;
            }

            // Aviso transaccional (el cliente pidió que le avisáramos). Si no hay
            // teléfono no se puede contactar: se deja esperando.
            if ((string) $e['phone'] === '') {
                continue;
            }
            $this->wa->sendText(ltrim((string) $e['phone'], '+'), $this->message($e, $slotDate));
            $this->db->executeStatement(
                "UPDATE waitlist SET status = 'avisado', notified_at = now() WHERE id = ?",
                [(int) $e['id']]
            );
            ++$notified;
        }

        $io->success(sprintf('%s %d aviso(s) de %d en espera.', $dryRun ? '[DRY]' : 'Enviados', $notified, count($entries)));

        return Command::SUCCESS;
    }

    /**
     * Primera fecha (entre la deseada o los próximos días) con hueco disponible.
     *
     * @param array<string, mixed> $entry
     */
    private function firstDateWithSlot(array $entry): ?string
    {
        $staffId = $entry['staff_id'] !== null ? (int) $entry['staff_id'] : null;
        $tz = new \DateTimeZone((string) $entry['timezone']);
        $today = new \DateTimeImmutable('today', $tz);

        $dates = [];
        if ($entry['desired_date'] !== null) {
            $desired = new \DateTimeImmutable((string) $entry['desired_date'], $tz);
            if ($desired >= $today) {
                $dates[] = $desired->format('Y-m-d');
            }
        } else {
            for ($i = 0; $i < self::LOOKAHEAD_DAYS; ++$i) {
                $dates[] = $today->modify("+{$i} days")->format('Y-m-d');
            }
        }

        foreach ($dates as $date) {
            try {
                $offer = $this->availability->find((int) $entry['location_id'], (int) $entry['service_id'], $staffId, $date);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($offer['slots'] !== []) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function message(array $entry, string $date): string
    {
        $dt = new \DateTimeImmutable($date);
        $dia = self::DAYS[((int) $dt->format('N')) - 1];
        $cuando = sprintf('%s %s', $dia, $dt->format('d/m'));

        return "¡Hola {$entry['customer_name']}! 🔔 Se ha liberado hueco para "
            . "{$entry['service_name']} en {$entry['location_name']} el {$cuando}.\n"
            . 'Escríbenos "menú" para reservarlo antes de que vuele. ✂️';
    }
}
