<?php
/**
 * market-seeder - hook
 *
 * Executado em HOOK_STARTUP (site) e HOOK_ADMIN_BEFORE_PAGE (admin). Em ambos os
 * casos o MyAAC ja inicializou o banco. O trabalho real so acontece uma vez por
 * dia (guarda por data + lock), entao nas demais requisicoes e apenas um SELECT.
 *
 * Retorna sempre true para nao bloquear o carregamento da pagina do admin.
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'market-seeder/lib.php';

try {
	if (defined('MYAAC_ADMIN')) {
		market_seeder_ensure_admin_menu();
	}
	market_seeder_maybe_run_daily();
} catch (\Throwable $e) {
	if (function_exists('log_append')) {
		log_append('error.log', '[market-seeder] ' . $e->getMessage());
	}
}

return true;
