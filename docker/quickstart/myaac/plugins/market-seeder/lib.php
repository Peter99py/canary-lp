<?php
/**
 * market-seeder - biblioteca comum
 *
 * Toda a logica do plugin vive aqui: criacao das tabelas, leitura da lista de
 * itens, resolucao de nome -> id a partir de data/items/items.xml e a rotina
 * que escreve as ofertas anonimas no market.
 *
 * As ofertas sao gravadas diretamente em `market_offers` (mesmo banco do
 * servidor). Ver src/io/iomarket.cpp (createOffer) para o formato da tabela:
 * sale = 1 (oferta de venda), anonymous = 1, price = valor por unidade.
 */

defined('MYAAC') or die('Direct access not allowed!');

/** Nome da tabela auxiliar (com o prefixo do MyAAC). */
function market_seeder_table(string $suffix): string
{
	return TABLE_PREFIX . 'market_seeder_' . $suffix;
}

/** Cria as tabelas auxiliares do plugin, se ainda nao existirem. */
function market_seeder_ensure_tables(): void
{
	global $db;

	if (!$db->hasTable(market_seeder_table('items'))) {
		$db->exec(
			'CREATE TABLE IF NOT EXISTS `' . market_seeder_table('items') . '` (' .
			'`id` INT(11) NOT NULL AUTO_INCREMENT,' .
			'`item_id` INT(10) UNSIGNED NOT NULL,' .
			'`name` VARCHAR(80) NOT NULL DEFAULT \'\',' .
			'`amount` INT(11) NOT NULL DEFAULT 1,' .
			'`price` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,' .
			'`tier` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,' .
			'`days` INT(11) NOT NULL DEFAULT 30,' .
			'`enabled` TINYINT(1) NOT NULL DEFAULT 1,' .
			'PRIMARY KEY (`id`), KEY `item_id` (`item_id`), KEY `enabled` (`enabled`)' .
			') ENGINE=InnoDB DEFAULT CHARSET=utf8;'
		);
	}

	if (!$db->hasTable(market_seeder_table('state'))) {
		$db->exec(
			'CREATE TABLE IF NOT EXISTS `' . market_seeder_table('state') . '` (' .
			'`name` VARCHAR(40) NOT NULL,' .
			'`value` TEXT NULL,' .
			'PRIMARY KEY (`name`)' .
			') ENGINE=InnoDB DEFAULT CHARSET=utf8;'
		);
	}
}

/* ------------------------------------------------------------------ */
/* Estado de execucao (last_run, last_result, defaults_imported)        */
/* ------------------------------------------------------------------ */

function market_seeder_state_get(string $key, ?string $default = null): ?string
{
	global $db;
	market_seeder_ensure_tables();
	$row = $db->query(
		'SELECT `value` FROM `' . market_seeder_table('state') . '` WHERE `name` = ' . $db->quote($key) . ' LIMIT 1'
	)->fetch(PDO::FETCH_ASSOC);
	return $row ? (string)$row['value'] : $default;
}

function market_seeder_state_set(string $key, string $value): void
{
	global $db;
	market_seeder_ensure_tables();
	$db->query(
		'REPLACE INTO `' . market_seeder_table('state') . '` (`name`,`value`) VALUES (' .
		$db->quote($key) . ',' . $db->quote($value) . ')'
	);
}

/* ------------------------------------------------------------------ */
/* Configuracao declarada em settings.php                              */
/* ------------------------------------------------------------------ */

function market_seeder_setting(string $key, $default = null)
{
	$value = setting('market_seeder.' . $key);
	return $value === null ? $default : $value;
}

/* ------------------------------------------------------------------ */
/* Acesso ao servidor (config.lua / tabelas)                           */
/* ------------------------------------------------------------------ */

/** Duracao global das ofertas no market, em segundos (config.lua). */
function market_seeder_market_duration(): int
{
	global $config;
	$lua = array_change_key_case($config['lua'] ?? [], CASE_LOWER);
	$duration = (int)($lua['marketofferduration'] ?? 0);
	return $duration > 0 ? $duration : 30 * 24 * 60 * 60;
}

/**
 * Registra a pagina do plugin no menu do admin (tabela myaac_admin_menu),
 * se ainda nao existir. Chamado a partir do hook do admin.
 */
function market_seeder_ensure_admin_menu(): void
{
	global $db;
	if (!$db->hasTable(TABLE_PREFIX . 'admin_menu')) {
		return;
	}

	$exists = $db->query(
		"SELECT 1 FROM `" . TABLE_PREFIX . "admin_menu` WHERE `page` = 'market-seeder' LIMIT 1"
	)->fetchColumn();
	if (!$exists) {
		$db->query(
			"INSERT INTO `" . TABLE_PREFIX . "admin_menu` (`name`,`page`,`ordering`,`flags`,`enabled`) " .
			"VALUES ('Market Seeder','market-seeder',65,0,1)"
		);
	}
}

/** Id do personagem pelo nome (0 se nao existir). */
function market_seeder_player_id(string $name): int
{
	global $db;
	$row = $db->query('SELECT `id` FROM `players` WHERE `name` = ' . $db->quote($name) . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	return $row ? (int)$row['id'] : 0;
}

/** Quantidade de ofertas do personagem no market. */
function market_seeder_own_offer_count(int $playerId): int
{
	global $db;
	return (int)$db->query('SELECT COUNT(*) FROM `market_offers` WHERE `player_id` = ' . (int)$playerId)->fetchColumn();
}

/** Quantidade de ofertas de outros personagens (nao do dono). */
function market_seeder_foreign_offer_count(int $playerId): int
{
	global $db;
	return (int)$db->query('SELECT COUNT(*) FROM `market_offers` WHERE `player_id` <> ' . (int)$playerId)->fetchColumn();
}

/* ------------------------------------------------------------------ */
/* Resolucao de itens (nome <-> id) a partir de data/items/items.xml   */
/* ------------------------------------------------------------------ */

/**
 * Mapa nome (minusculo) -> id, lido direto de items.xml.
 * Nao depende do cache do MyAAC; funciona mesmo sem "Server Data" carregado.
 */
function market_seeder_name_map(): array
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
			// itens em faixa (fromid/toid): aponta para o primeiro id
			$map[$name] = (int)$fromMatch[1];
		}
	}

	return $map;
}

/** Nome do item a partir do id (string vazia se desconhecido). */
function market_seeder_item_name(int $id): string
{
	foreach (market_seeder_name_map() as $name => $mappedId) {
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
function market_seeder_resolve_item(string $input): array
{
	$input = trim($input);
	if ($input === '') {
		return [0, ''];
	}

	if (ctype_digit($input)) {
		return [(int)$input, ''];
	}

	$map = market_seeder_name_map();
	$key = strtolower($input);
	if (isset($map[$key])) {
		return [$map[$key], $input];
	}

	return [0, $input];
}

/* ------------------------------------------------------------------ */
/* Importacao de listas                                                */
/* ------------------------------------------------------------------ */

/**
 * Importa linhas no formato: nome_ou_id;quantidade;preco;tier;dias
 * Linhas vazias e comecando por '#' sao ignoradas.
 * Itens ja existentes (mesmo item_id + tier) sao ignorados, para nao duplicar.
 * @return array{imported:int,missing:array<int,string>,skipped:int}
 */
function market_seeder_import_text(string $text): array
{
	global $db;
	market_seeder_ensure_tables();

	$imported = 0;
	$skipped = 0;
	$missing = [];

	foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}

		$parts = array_map('trim', explode(';', $line));
		$target = $parts[0] ?? '';
		if ($target === '') {
			continue;
		}

		$amount = max(1, (int)($parts[1] ?? 1));
		$price = max(0, (int)($parts[2] ?? 0));
		$tier = max(0, (int)($parts[3] ?? 0));
		$days = max(1, (int)($parts[4] ?? 30));

		[$itemId, $name] = market_seeder_resolve_item($target);
		if ($itemId <= 0) {
			$missing[] = $target;
			continue;
		}

		$exists = $db->query(
			'SELECT 1 FROM `' . market_seeder_table('items') . '` WHERE `item_id` = ' . $itemId . ' AND `tier` = ' . $tier . ' LIMIT 1'
		)->fetchColumn();
		if ($exists) {
			$skipped++;
			continue;
		}

		if ($name === '') {
			$name = market_seeder_item_name($itemId);
		}

		$db->query(
			'INSERT INTO `' . market_seeder_table('items') . '` (`item_id`,`name`,`amount`,`price`,`tier`,`days`,`enabled`) VALUES (' .
			(int)$itemId . ',' . $db->quote($name) . ',' . $amount . ',' . $price . ',' . $tier . ',' . $days . ',1)'
		);
		$imported++;
	}

	return ['imported' => $imported, 'missing' => $missing, 'skipped' => $skipped];
}

/** Importa o arquivo de lista padrao embarcado no plugin. */
function market_seeder_import_defaults(): array
{
	$file = PLUGINS . 'market-seeder/data/default-offers.txt';
	if (!is_file($file)) {
		return ['imported' => 0, 'missing' => []];
	}

	return market_seeder_import_text((string)file_get_contents($file));
}

/* ------------------------------------------------------------------ */
/* Rotina principal                                                    */
/* ------------------------------------------------------------------ */

/**
 * Sincroniza o market com a lista configurada.
 *
 * - Cada linha ativa vira uma oferta anonima de venda do personagem dono.
 * - Ofertas do dono que nao estao mais na lista sao removidas.
 * - Ofertas existentes tem preco/quantidade atualizados (a validade original
 *   e preservada; ao expirar, a proxima execucao recria).
 *
 * @return array{ok:bool,inserted?:int,updated?:int,deleted?:int,skipped?:bool,message?:string}
 */
function market_seeder_run(bool $force = false): array
{
	global $db;
	market_seeder_ensure_tables();

	$owner = trim((string)market_seeder_setting('owner', 'ADM1'));
	if ($owner === '') {
		$owner = 'ADM1';
	}

	$ownerId = market_seeder_player_id($owner);
	if ($ownerId <= 0) {
		return ['ok' => false, 'message' => "Personagem '" . $owner . "' nao encontrado na tabela players."];
	}

	// Importa a lista padrao na primeira vez, se a lista estiver vazia.
	$listCount = (int)$db->query('SELECT COUNT(*) FROM `' . market_seeder_table('items') . '`')->fetchColumn();
	if ($listCount === 0
		&& market_seeder_setting('auto_import', true)
		&& market_seeder_state_get('defaults_imported') !== '1') {
		market_seeder_import_defaults();
		market_seeder_state_set('defaults_imported', '1');
	}

	if (!$force && market_seeder_setting('only_when_empty', true) && market_seeder_foreign_offer_count($ownerId) > 0) {
		return [
			'ok' => true,
			'skipped' => true,
			'inserted' => 0,
			'updated' => 0,
			'deleted' => 0,
			'message' => 'Existem ofertas de outros jogadores; nada foi alterado.',
		];
	}

	$duration = market_seeder_market_duration();
	$maxDays = max(1, (int)floor($duration / 86400));

	$rows = $db->query(
		'SELECT * FROM `' . market_seeder_table('items') . '` WHERE `enabled` = 1 ORDER BY `id`'
	)->fetchAll(PDO::FETCH_ASSOC);

	$kept = [];
	foreach ($rows as $row) {
		$kept[(int)$row['item_id'] . ':' . (int)$row['tier']] = true;
	}

	// Remove ofertas do dono cujo item saiu da lista (ou foi desabilitado).
	$deleted = 0;
	$ownOffers = $db->query(
		'SELECT `id`,`itemtype`,`tier` FROM `market_offers` WHERE `player_id` = ' . $ownerId
	)->fetchAll(PDO::FETCH_ASSOC);
	foreach ($ownOffers as $offer) {
		$key = (int)$offer['itemtype'] . ':' . (int)$offer['tier'];
		if (!isset($kept[$key])) {
			$db->exec('DELETE FROM `market_offers` WHERE `id` = ' . (int)$offer['id']);
			$deleted++;
		}
	}

	$now = time();
	$inserted = 0;
	$updated = 0;

	foreach ($rows as $row) {
		$itemId = (int)$row['item_id'];
		$tier = max(0, (int)$row['tier']);
		$amount = max(1, (int)$row['amount']);
		$price = max(0, (int)$row['price']);
		$days = max(1, min($maxDays, (int)$row['days']));

		$existing = $db->query(
			'SELECT `id` FROM `market_offers` WHERE `player_id` = ' . $ownerId .
			' AND `itemtype` = ' . $itemId . ' AND `tier` = ' . $tier . ' LIMIT 1'
		)->fetch(PDO::FETCH_ASSOC);

		if ($existing) {
			$db->exec(
				'UPDATE `market_offers` SET `amount` = ' . $amount . ', `price` = ' . $price .
				', `anonymous` = 1 WHERE `id` = ' . (int)$existing['id']
			);
			$updated++;
			continue;
		}

		// created recuado para que a oferta expire em "days" (expiry = created + duration).
		$created = $now - ($duration - $days * 86400);
		$db->exec(
			'INSERT INTO `market_offers` (`player_id`,`sale`,`itemtype`,`amount`,`created`,`anonymous`,`price`,`tier`) VALUES (' .
			$ownerId . ',1,' . $itemId . ',' . $amount . ',' . $created . ',1,' . $price . ',' . $tier . ')'
		);
		$inserted++;
	}

	return ['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted];
}

/**
 * Executa no maximo uma vez por dia (guarda por data + lock de arquivo).
 * Chamada pelos hooks de web e pelo cronjob.
 */
function market_seeder_maybe_run_daily(): void
{
	$today = date('Y-m-d');

	// Fallback por visita: so roda a partir das 03:00 locais (mesmo horario do
	// crontab), para nao antecipar a execucao diaria agendada. O caminho normal e
	// o cron do container (03:00); este cobre o caso de o cron nao ter rodado.
	if ((int)date('G') < 3) {
		return;
	}

	if (market_seeder_state_get('last_run') === $today) {
		return;
	}

	$lock = @fopen(CACHE . 'market-seeder.lock', 'c+');
	if (!$lock) {
		return;
	}
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		fclose($lock);
		return;
	}

	if (market_seeder_state_get('last_run') !== $today) {
		$result = market_seeder_run(false);
		market_seeder_state_set('last_run', $today);
		market_seeder_state_set('last_run_at', date('Y-m-d H:i:s'));
		market_seeder_state_set('last_result', json_encode($result));
	}

	flock($lock, LOCK_UN);
	fclose($lock);
}
