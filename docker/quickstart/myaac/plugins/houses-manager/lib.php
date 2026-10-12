<?php
/**
 * houses-manager - biblioteca comum
 *
 * Ajuda a gerenciar a tabela OT `houses`. Importante: o servidor (Canary), ao
 * iniciar, so le do banco: owner, new_owner, bidder, bidder_name, highest_bid,
 * internal_bid, bid_end_date, state, transfer_status. Os campos rent, name,
 * size, beds e town_id vem do mapa (*-house.xml) e sao regravados pelo servidor
 * a cada save. Faca as edicoes com o servidor parado.
 */

defined('MYAAC') or die('Direct access not allowed!');

/** Pagina/rota da admin (basename do arquivo em admin-pages/). */
function houses_manager_page(): string
{
	return 'houses';
}

/** Colunas que o servidor realmente le do banco no boot. */
function houses_manager_editable_columns(): array
{
	return ['owner', 'new_owner', 'bidder', 'bidder_name', 'highest_bid', 'internal_bid', 'bid_end_date', 'state', 'transfer_status'];
}

/** Rotulos do estado (CyclopediaHouseState). */
function houses_manager_states(): array
{
	return [0 => 'Available', 2 => 'Rented', 3 => 'Transfer', 4 => 'MoveOut'];
}

/** Registra a pagina no menu do admin, se ainda nao existir. */
function houses_manager_ensure_admin_menu(): void
{
	global $db;
	if (!$db->hasTable(TABLE_PREFIX . 'admin_menu')) {
		return;
	}

	$page = houses_manager_page();
	$exists = $db->query(
		'SELECT 1 FROM `' . TABLE_PREFIX . 'admin_menu` WHERE `page` = ' . $db->quote($page) . ' LIMIT 1'
	)->fetchColumn();
	if (!$exists) {
		$db->query(
			'INSERT INTO `' . TABLE_PREFIX . 'admin_menu` (`name`,`page`,`ordering`,`flags`,`enabled`) ' .
			"VALUES ('Houses'," . $db->quote($page) . ',81,0,1)'
		);
	}
}

/** Personagem pelo nome. @return array{0:int,1:string} [id, nomeComoNoBanco] */
function houses_manager_player(string $name): array
{
	global $db;
	$name = trim($name);
	if ($name === '') {
		return [0, ''];
	}

	$row = $db->query(
		'SELECT `id`, `name` FROM `players` WHERE `name` = ' . $db->quote($name) . ' LIMIT 1'
	)->fetch(PDO::FETCH_ASSOC);

	return $row ? [(int)$row['id'], (string)$row['name']] : [0, ''];
}

/** Lista as casas com o nome do dono. */
function houses_manager_all(): array
{
	global $db;
	return $db->query(
		'SELECT `h`.`id`, `h`.`owner`, `h`.`name`, `h`.`rent`, `h`.`town_id`, `h`.`size`, `h`.`beds`, ' .
		'`h`.`bidder_name`, `h`.`highest_bid`, `h`.`internal_bid`, `h`.`bid_end_date`, `h`.`state`, `h`.`transfer_status`, ' .
		'`p`.`name` AS `owner_name` ' .
		'FROM `houses` `h` LEFT JOIN `players` `p` ON `p`.`id` = `h`.`owner` ORDER BY `h`.`id` ASC'
	)->fetchAll(PDO::FETCH_ASSOC);
}

/** Uma casa por id (ou null). */
function houses_manager_get(int $id): ?array
{
	global $db;
	if ($id <= 0) {
		return null;
	}

	$row = $db->query(
		'SELECT `h`.*, `p`.`name` AS `owner_name` FROM `houses` `h` ' .
		'LEFT JOIN `players` `p` ON `p`.`id` = `h`.`owner` WHERE `h`.`id` = ' . $id . ' LIMIT 1'
	)->fetch(PDO::FETCH_ASSOC);

	return $row ?: null;
}

/** Grava apenas as colunas que o servidor le do banco. */
function houses_manager_update(int $id, array $data): bool
{
	global $db;
	if ($id <= 0 || empty($data)) {
		return false;
	}

	$allowed = houses_manager_editable_columns();
	$parts = [];
	foreach ($data as $col => $val) {
		if (!in_array($col, $allowed, true) || !$db->hasColumn('houses', $col)) {
			continue;
		}
		$parts[] = '`' . $col . '` = ' . (is_int($val) ? $val : $db->quote((string)$val));
	}
	if (empty($parts)) {
		return false;
	}

	return (bool)$db->query('UPDATE `houses` SET ' . implode(', ', $parts) . ' WHERE `id` = ' . $id . ' LIMIT 1');
}

/** Libera a casa (espelha House::setOwner(0)). */
function houses_manager_free(int $id): bool
{
	global $db;
	if ($id <= 0) {
		return false;
	}

	return (bool)$db->query(
		'UPDATE `houses` SET `owner` = 0, `new_owner` = -1, `paid` = 0, `bidder` = 0, `bidder_name` = \'\', ' .
		'`highest_bid` = 0, `internal_bid` = 0, `bid_end_date` = 0, `state` = 0 WHERE `id` = ' . $id . ' LIMIT 1'
	);
}

/* ------------------------------------------------------------------ */
/* XML de casas (mapa) - fonte da verdade de rent/nome/size/beds      */
/* ------------------------------------------------------------------ */

/** Caminho do XML de casas montado no container (read-write). */
function houses_manager_xml_path(): string
{
	global $config;
	return ($config['data_path'] ?? '') . 'world/otservbr-house.xml';
}

/** Caminho do catalogo do client (servido na raiz do site). */
function houses_manager_json_path(): string
{
	return BASE . 'houses.json';
}

/** Atributos do XML que o admin pode editar. */
function houses_manager_xml_attrs(): array
{
	return ['name', 'rent', 'size', 'beds', 'townid', 'clientid', 'guildhall'];
}

/**
 * Le o XML de casas.
 * @return array<int,array{line:int,attrs:array<string,string>}> indexado por houseid
 */
function houses_manager_xml_read(): array
{
	$file = houses_manager_xml_path();
	if (!is_file($file)) {
		return [];
	}

	$lines = @file($file, FILE_IGNORE_NEW_LINES);
	if ($lines === false) {
		return [];
	}

	$out = [];
	foreach ($lines as $i => $line) {
		if (!preg_match('/<house\b([^>]*)\/?>/i', $line, $m)) {
			continue;
		}

		$attrs = [];
		if (preg_match_all('/([A-Za-z_]+)\s*=\s*"([^"]*)"/', $m[1], $am, PREG_SET_ORDER)) {
			foreach ($am as $a) {
				$attrs[strtolower($a[1])] = $a[2];
			}
		}

		$id = (int)($attrs['houseid'] ?? 0);
		if ($id > 0) {
			$out[$id] = ['line' => $i, 'attrs' => $attrs];
		}
	}

	return $out;
}

/**
 * Atualiza atributos de uma casa no XML, preservando formatacao/ordem.
 * @param array<string,string> $changes
 */
function houses_manager_xml_update(int $houseId, array $changes): bool
{
	$file = houses_manager_xml_path();
	if ($houseId <= 0 || empty($changes) || !is_file($file) || !is_writable($file)) {
		return false;
	}

	$lines = @file($file, FILE_IGNORE_NEW_LINES);
	if ($lines === false) {
		return false;
	}

	$allowed = houses_manager_xml_attrs();
	$changed = false;
	foreach ($lines as $i => $line) {
		if (!preg_match('/<house\b[^>]*\bhouseid\s*=\s*"(\d+)"/i', $line, $idm) || (int)$idm[1] !== $houseId) {
			continue;
		}

		$newLine = $line;
		foreach ($changes as $attr => $val) {
			$attr = strtolower((string)$attr);
			if (!in_array($attr, $allowed, true)) {
				continue;
			}

			$val = trim((string)$val);
			if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*"[^"]*"/i', $newLine)) {
				$newLine = preg_replace('/\b' . preg_quote($attr, '/') . '\s*=\s*"[^"]*"/i', $attr . '="' . $val . '"', $newLine, 1);
			} else {
				$newLine = preg_replace('/\s*\/>\s*$/', ' ' . $attr . '="' . $val . '" />', $newLine, 1);
			}
		}

		if ($newLine !== $line) {
			$lines[$i] = $newLine;
			$changed = true;
		}
		break;
	}

	if (!$changed) {
		return false;
	}

	return @file_put_contents($file, implode("\n", $lines) . "\n") !== false;
}

/**
 * Monta o catalogo do client (clientId => dados) a partir do XML do mapa.
 * @return array<string,array<string,mixed>>
 */
function houses_manager_catalog(): array
{
	$out = [];
	foreach (houses_manager_xml_read() as $id => $info) {
		$a = $info['attrs'];
		$clientId = (string)($a['clientid'] ?? '');
		if ($clientId === '') {
			continue;
		}

		$name = (string)($a['name'] ?? ('House #' . $id));
		$out[$clientId] = [
			'name' => $name,
			'description' => '',
			'rent' => (string)($a['rent'] ?? '0'),
			'beds' => (int)($a['beds'] ?? 0),
			'sqm' => (int)($a['size'] ?? 0),
			'GH' => (($a['guildhall'] ?? '') === 'true') ? 1 : 0,
			'shop' => (strpos($name, '(Shop)') !== false) ? 1 : 0,
		];
	}

	return $out;
}

/**
 * Gera o catalogo houses.json (client) a partir do XML e grava na raiz web.
 * @return array{ok:bool,count:int,path:string}
 */
function houses_manager_generate_json(): array
{
	$out = houses_manager_catalog();
	$path = houses_manager_json_path();
	$ok = @file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") !== false;

	return ['ok' => $ok, 'count' => count($out), 'path' => $path];
}
