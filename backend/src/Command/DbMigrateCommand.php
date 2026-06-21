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
 * Aplica las migraciones SQL versionadas de `db/migrations` que aún no se hayan
 * ejecutado, registrándolas en `schema_migration`.
 *
 * El proyecto usa SQL plano como fuente de verdad (no el ORM de Doctrine); este
 * comando sustituye a aplicarlas a mano: idempotente, ordenado y trazable.
 *
 *   php bin/console app:db:migrate            # aplica las pendientes
 *   php bin/console app:db:migrate --status   # solo muestra el estado
 */
#[AsCommand(
    name: 'app:db:migrate',
    description: 'Aplica las migraciones SQL pendientes de db/migrations.',
)]
final class DbMigrateCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_NONE, 'Muestra qué migraciones están aplicadas/pendientes y termina')
            ->addOption('baseline', null, InputOption::VALUE_NONE, 'Marca todas las migraciones como aplicadas SIN ejecutarlas (para una BD ya provisionada a mano)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->ensureRegistryTable();

        $dir = $this->projectDir . '/../db/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        if ($files === []) {
            $io->warning("No se encontraron migraciones en $dir");

            return Command::SUCCESS;
        }

        $applied = array_flip($this->db->fetchFirstColumn('SELECT version FROM schema_migration'));

        if ($input->getOption('status')) {
            foreach ($files as $f) {
                $v = basename($f);
                $io->writeln((isset($applied[$v]) ? '<info>[✓]</info> ' : '<comment>[ ]</comment> ') . $v);
            }

            return Command::SUCCESS;
        }

        $pending = array_values(array_filter($files, static fn (string $f): bool => !isset($applied[basename($f)])));
        if ($pending === []) {
            $io->success('La base de datos ya está al día.');

            return Command::SUCCESS;
        }

        if ($input->getOption('baseline')) {
            foreach ($pending as $file) {
                $this->db->executeStatement(
                    'INSERT INTO schema_migration (version) VALUES (?) ON CONFLICT DO NOTHING',
                    [basename($file)]
                );
            }
            $io->success(sprintf('%d migración(es) marcada(s) como aplicadas (baseline), sin ejecutar.', count($pending)));

            return Command::SUCCESS;
        }

        foreach ($pending as $file) {
            $version = basename($file);
            $sql = (string) file_get_contents($file);
            try {
                $this->runMigration($version, $sql);
                $io->writeln("<info>Aplicada</info> $version");
            } catch (\Throwable $e) {
                $io->error("Falló $version: " . $e->getMessage());

                return Command::FAILURE;
            }
        }

        $io->success(sprintf('%d migración(es) aplicada(s).', count($pending)));

        return Command::SUCCESS;
    }

    private function ensureRegistryTable(): void
    {
        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS schema_migration (
                version    TEXT PRIMARY KEY,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )'
        );
    }

    /**
     * Ejecuta el SQL completo de una migración y la registra, todo en una
     * transacción (el DDL de Postgres es transaccional). Usa la conexión nativa
     * porque un fichero contiene varias sentencias.
     */
    private function runMigration(string $version, string $sql): void
    {
        $this->db->beginTransaction();
        try {
            $native = $this->db->getNativeConnection();
            if ($native instanceof \PDO) {
                $native->exec($sql);
            } else {
                $this->db->executeStatement($sql);
            }
            $this->db->executeStatement('INSERT INTO schema_migration (version) VALUES (?)', [$version]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }
}
