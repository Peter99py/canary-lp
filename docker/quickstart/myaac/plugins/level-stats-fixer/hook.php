<?php
/**
 * level-stats-fixer - hook
 *
 * Recalcula (modo absoluto) vida/mana/cap quando o level OU a vocacao de um
 * personagem mudam no editor de players do admin do MyAAC.
 *
 * Como funciona: o hook roda em HOOK_ADMIN_BEFORE_PAGE, ANTES de
 * admin/pages/players.php processar o formulario; escrevemos os valores
 * corrigidos em $_POST e o players.php os salva normalmente. Assim nenhum
 * arquivo do core do MyAAC e sobrescrito.
 *
 * Base (nivel 1) e ganhos por vocacao vem do settings.php (somente leitura).
 *
 * Referencias (canary):
 *  - ganhos por nivel: canary/data/XML/vocations.xml (gainhp / gainmana / gaincap)
 *  - regra rook e soma por nivel: canary/src/creatures/players/player.cpp (addExperience)
 *  - base nivel 1 derivada das amostras: canary/schema.sql
 */

defined('MYAAC') or die('Direct access not allowed!');

// So interessa o save do editor de players.
if (!defined('PAGE') || PAGE !== 'players' || empty($_POST['save'])) {
	return true;
}

$id = (int)($_REQUEST['id'] ?? 0);
$newLevel = (int)($_POST['level'] ?? 0);
$newVocation = (int)($_POST['vocation'] ?? 0);
if ($id <= 0 || $newLevel <= 0) {
	return true;
}

$row = $db->query('SELECT `level`, `vocation` FROM `players` WHERE `id` = ' . $id)->fetch(PDO::FETCH_ASSOC);
if (!$row) {
	return true;
}

$oldLevel = (int)$row['level'];
$oldVocation = (int)$row['vocation'];

// Nada de level/vocacao mudou: nao mexe em vida/mana/cap (preserva edicoes manuais).
if ($newLevel === $oldLevel && $newVocation === $oldVocation) {
	return true;
}

// --- Valores imutaveis (definidos em settings.php) ---
$base = [
	(int)setting('level_stats_fixer.base_hp'),
	(int)setting('level_stats_fixer.base_mana'),
	(int)setting('level_stats_fixer.base_cap'),
];

// Fallback caso o settings ainda nao tenha sido salvo/parseado.
$defaultGains = [
	0 => [5, 5, 10],
	1 => [5, 30, 10],
	2 => [5, 30, 10],
	3 => [10, 15, 20],
	4 => [15, 5, 25],
	5 => [5, 30, 10],
	6 => [5, 30, 10],
	7 => [10, 15, 20],
	8 => [15, 5, 25],
	9 => [10, 10, 25],
	10 => [10, 10, 25],
];

$gains = json_decode((string)setting('level_stats_fixer.gains'), true);
if (!is_array($gains) || !isset($gains['0'])) {
	$gains = $defaultGains;
}

$none = $gains['0'];
$hp = $base[0];
$mana = $base[1];
$cap = $base[2];

for ($level = 2; $level <= $newLevel; ++$level) {
	// Regra rook: enquanto vocacao != None e nivel <= 8, usa os ganhos da vocacao None.
	$gain = ($newVocation != 0 && $level <= 8) ? $none : ($gains[$newVocation] ?? $none);
	$hp += $gain[0];
	$mana += $gain[1];
	$cap += $gain[2];
}

$hp = max(1, $hp);
$mana = max(0, $mana);
$cap = max(0, $cap);

$_POST['health_max'] = $hp;
$_POST['mana_max'] = $mana;
$_POST['capacity'] = $cap;

if (setting('level_stats_fixer.fill')) {
	$_POST['health'] = $hp;
	$_POST['mana'] = $mana;
}

return true;
