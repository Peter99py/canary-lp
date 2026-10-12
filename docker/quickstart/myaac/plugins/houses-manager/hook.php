<?php
/**
 * houses-manager - hook
 *
 * Executado em HOOK_ADMIN_BEFORE_PAGE. Registra a pagina no menu do admin
 * (tabela myaac_admin_menu), se ainda nao existir. Nao altera o core do MyAAC.
 *
 * Retorna sempre true para nao bloquear o carregamento da pagina do admin.
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'houses-manager/lib.php';

try {
	houses_manager_ensure_admin_menu();
} catch (\Throwable $e) {
	if (function_exists('log_append')) {
		log_append('error.log', '[houses-manager] ' . $e->getMessage());
	}
}

return true;
