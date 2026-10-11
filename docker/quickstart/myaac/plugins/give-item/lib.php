<?php
/**
 * give-item - biblioteca comum
 *
 * Cria a tabela de pedidos e expoe helpers para o admin. Nao entrega itens por
 * conta propria: o MyAAC nao controla o inventario em memoria do servidor. Os
 * pedidos ficam em `myaac_item_delivery` (mesmo banco do servidor) e o script
 * Lua data/scripts/globalevents/myaac_item_delivery.lua entrega de fato, usando
 * o mesmo caminho do comando /i. Ver aquele arquivo para o contrato da tabela.
 */

defined('MYAAC') or die('Direct access not allowed!');

/** Nome da tabela de pedidos. Precisa bater com a constante do script Lua. */
function give_item_table(): string
{
	return TABLE_PREFIX . 'item_delivery';
}

/** Cria a tabela de pedidos, se ainda nao existir. */
function give_item_ensure_tables(): void
{
	// Uma vez por requisicao: evita repetir hasTable e o cache do MyAAC.
	static $done = false;
	if ($done) {
		return;
	}
	$done = true;

	global $db;

	if ($db->hasTable(give_item_table())) {
		return;
	}

	$db->exec(
		'CREATE TABLE IF NOT EXISTS `' . give_item_table() . '` (' .
		'`id` INT(11) NOT NULL AUTO_INCREMENT,' .
		'`player_id` INT(11) NOT NULL,' .
		'`player_name` VARCHAR(255) NOT NULL DEFAULT \'\',' .
		'`item_id` INT(11) NOT NULL,' .
		'`item_name` VARCHAR(100) NOT NULL DEFAULT \'\',' .
		'`count` INT(11) NOT NULL DEFAULT 1,' .
		'`tier` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,' .
		'`target` VARCHAR(20) NOT NULL DEFAULT \'backpack\',' .
		'`status` VARCHAR(10) NOT NULL DEFAULT \'pending\',' .
		'`message` VARCHAR(255) NOT NULL DEFAULT \'\',' .
		'`created_at` INT(11) NOT NULL DEFAULT 0,' .
		'`processed_at` INT(11) NOT NULL DEFAULT 0,' .
		'PRIMARY KEY (`id`), KEY `status` (`status`), KEY `player_id` (`player_id`)' .
		') ENGINE=InnoDB DEFAULT CHARSET=utf8;'
	);
}

/**
 * Registra a pagina do plugin no menu do admin (tabela myaac_admin_menu),
 * se ainda nao existir.
 */
function give_item_ensure_admin_menu(): void
{
	global $db;
	if (!$db->hasTable(TABLE_PREFIX . 'admin_menu')) {
		return;
	}

	$exists = $db->query(
		"SELECT 1 FROM `" . TABLE_PREFIX . "admin_menu` WHERE `page` = 'give-item' LIMIT 1"
	)->fetchColumn();
	if (!$exists) {
		$db->query(
			"INSERT INTO `" . TABLE_PREFIX . "admin_menu` (`name`,`page`,`ordering`,`flags`,`enabled`) " .
			"VALUES ('Give Item','give-item',66,0,1)"
		);
	}
}

/* ------------------------------------------------------------------ */
/* Personagens                                                         */
/* ------------------------------------------------------------------ */

/**
 * Personagem pelo nome (busca exata; a coluna `name` normalmente tem collation
 * case-insensitive, entao 'adm1' tambem encontra 'ADM1').
 * @return array{0:int,1:string} [id, nomeComoNoBanco]
 */
function give_item_player(string $name): array
{
	global $db;
	$row = $db->query(
		'SELECT `id`, `name` FROM `players` WHERE `name` = ' . $db->quote($name) . ' LIMIT 1'
	)->fetch(PDO::FETCH_ASSOC);

	return $row ? [(int)$row['id'], (string)$row['name']] : [0, ''];
}

/* ------------------------------------------------------------------ */
/* Resolucao de itens (nome <-> id) a partir de data/items/items.xml   */
/* ------------------------------------------------------------------ */

/**
 * Mapa nome (minusculo) -> id, lido direto de items.xml.
 * Nao depende do cache do MyAAC; funciona mesmo sem "Server Data" carregado.
 */
function give_item_name_map(): array
{
	static $map = null;
	if ($map !== null) {
		return $map;
	}

	$map = [];
	global $config;
	$file = ($config['data_path'] ?? '') . 'items/items.xml';
	if (!is_file($file)) {
		return $map;
	}

	$xml = (string)@file_get_contents($file);
	$xml = preg_replace('/<!--.*?-->/s', '', $xml);
	if ($xml === '' || !preg_match_all('/<item\b[^>]*>/i', $xml, $tags)) {
		return $map;
	}

	foreach ($tags[0] as $tag) {
		if (!preg_match('/\bname="([^"]*)"/i', $tag, $nameMatch)) {
			continue;
		}
		$name = strtolower(trim($nameMatch[1]));
		if ($name === '') {
			continue;
		}

		if (preg_match('/\bid="(\d+)"/i', $tag, $idMatch)) {
			$map[$name] = (int)$idMatch[1];
		} elseif (preg_match('/\bfromid="(\d+)"/i', $tag, $fromMatch)) {
			$map[$name] = (int)$fromMatch[1];
		}
	}

	return $map;
}

/** Nome do item a partir do id (string vazia se desconhecido). */
function give_item_item_name(int $id): string
{
	foreach (give_item_name_map() as $name => $mappedId) {
		if ($mappedId === $id) {
			return $name;
		}
	}

	return '';
}

/**
 * Aceita um id numerico ou um nome de item.
 * @return array{0:int,1:string} [id, nomeInformado]
 */
function give_item_resolve_item(string $input): array
{
	$input = trim($input);
	if ($input === '') {
		return [0, ''];
	}

	if (ctype_digit($input)) {
		return [(int)$input, ''];
	}

	$map = give_item_name_map();
	$key = strtolower($input);
	if (isset($map[$key])) {
		return [$map[$key], $input];
	}

	return [0, $input];
}

/* ------------------------------------------------------------------ */
/* Pedidos                                                             */
/* ------------------------------------------------------------------ */

/**
 * Enfileira um pedido de item. Retorna o id do pedido (0 em falha).
 */
function give_item_enqueue(int $playerId, string $playerName, int $itemId, string $itemName, int $count, int $tier, string $target): int
{
	global $db;
	give_item_ensure_tables();

	$target = ($target === 'store_inbox') ? 'store_inbox' : 'backpack';

	$ok = $db->query(
		'INSERT INTO `' . give_item_table() . '` ' .
		'(`player_id`,`player_name`,`item_id`,`item_name`,`count`,`tier`,`target`,`status`,`message`,`created_at`,`processed_at`) VALUES (' .
		(int)$playerId . ',' .
		$db->quote($playerName) . ',' .
		(int)$itemId . ',' .
		$db->quote($itemName) . ',' .
		max(1, $count) . ',' .
		max(0, $tier) . ',' .
		$db->quote($target) . ",'pending',''," . time() . ',0)'
	);

	if (!$ok) {
		return 0;
	}

	return (int)$db->lastInsertId();
}

/** Contagem por status. @return array{pending:int,done:int,failed:int} */
function give_item_counts(): array
{
	global $db;
	give_item_ensure_tables();

	$counts = ['pending' => 0, 'done' => 0, 'failed' => 0];
	$rows = $db->query(
		'SELECT `status`, COUNT(*) AS `total` FROM `' . give_item_table() . '` GROUP BY `status`'
	)->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		$status = (string)$row['status'];
		if (isset($counts[$status])) {
			$counts[$status] = (int)$row['total'];
		}
	}

	return $counts;
}

/** Ultimos pedidos (mais recentes primeiro). */
function give_item_recent(int $limit = 100): array
{
	global $db;
	give_item_ensure_tables();

	return $db->query(
		'SELECT * FROM `' . give_item_table() . '` ORDER BY `id` DESC LIMIT ' . max(1, $limit)
	)->fetchAll(PDO::FETCH_ASSOC);
}
