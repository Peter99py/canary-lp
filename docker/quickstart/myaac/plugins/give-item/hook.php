<?php
/**
 * give-item - hook
 *
 * Executado em HOOK_ADMIN_BEFORE_PAGE. Garante a tabela de pedidos e registra a
 * pagina no menu do admin. Nao entrega nada: quem entrega e o servidor (script
 * Lua), lendo a tabela.
 *
 * Retorna sempre true para nao bloquear o carregamento da pagina do admin.
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'give-item/lib.php';

try {
	give_item_ensure_tables();
	give_item_ensure_admin_menu();
} catch (\Throwable $e) {
	if (function_exists('log_append')) {
		log_append('error.log', '[give-item] ' . $e->getMessage());
	}
}

return true;
