<?php
/**
 * market-seeder - comando de CLI
 *
 * Uso:
 *   php aac market-seeder:run
 *   php aac market-seeder:run --force
 *
 * Registrado automaticamente pelo autoload de comandos de plugins do MyAAC.
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

$command = new class extends Command {
	protected function configure(): void
	{
		$this
			->setName('market-seeder:run')
			->setDescription('Executa o Market Seeder agora (popula o market com as ofertas configuradas).')
			->addOption('force', null, InputOption::VALUE_NONE, 'Ignora a checagem de ofertas de outros jogadores.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		require SYSTEM . 'init.php';
		require_once PLUGINS . 'market-seeder/lib.php';

		$io = new SymfonyStyle($input, $output);
		$force = (bool)$input->getOption('force');

		if ($force) {
			$result = market_seeder_run(true);
		} else {
			// Caminho do cron: respeita a guarda diaria (horario + data + lock),
			// para nao competir com o fallback por visita.
			$before = market_seeder_state_get('last_run');
			market_seeder_maybe_run_daily();
			if (market_seeder_state_get('last_run') === $before) {
				$io->info('Nada executado (a rotina diaria ja rodou hoje ou esta fora do horario das 03:00).');
				return Command::SUCCESS;
			}

			$raw = market_seeder_state_get('last_result');
			$result = $raw ? json_decode($raw, true) : null;
			if (!is_array($result)) {
				$io->info('Nada executado.');
				return Command::SUCCESS;
			}
		}

		if (empty($result['ok'])) {
			$io->warning($result['message'] ?? 'Nada foi feito.');
			return Command::SUCCESS;
		}

		if (!empty($result['skipped'])) {
			$io->info($result['message'] ?? 'Ignorado.');
			return Command::SUCCESS;
		}

		$io->success(sprintf(
			'Inseridas: %d, atualizadas: %d, removidas: %d.',
			$result['inserted'] ?? 0,
			$result['updated'] ?? 0,
			$result['deleted'] ?? 0
		));
		return Command::SUCCESS;
	}
};

return $command;
