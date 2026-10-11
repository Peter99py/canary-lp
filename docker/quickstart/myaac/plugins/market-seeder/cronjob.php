<?php
/**
 * market-seeder - cronjob
 *
 * Registra a rotina no cron nativo do MyAAC (HOOK_CRONJOB, disparado por
 * `php aac cronjob`). So tem efeito se o cron do sistema estiver configurado
 * (`php aac cronjob:install` + um cron daemon). O hook de web (hook.php) ja
 * cobre o caso comum; ambos compartilham a mesma guarda diaria, sem duplicar.
 */

defined('MYAAC') or die('Direct access not allowed!');

if (isset($scheduler) && $scheduler instanceof \GO\Scheduler) {
	$scheduler->call(function () {
		require_once PLUGINS . 'market-seeder/lib.php';
		try {
			market_seeder_maybe_run_daily();
		} catch (\Throwable $e) {
			if (function_exists('log_append')) {
				log_append('error.log', '[market-seeder] ' . $e->getMessage());
			}
		}
	})->daily();
}
