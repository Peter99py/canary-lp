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

		$result = market_seeder_run((bool)$input->getOption('force'));
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
