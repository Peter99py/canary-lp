<?php

defined('MYAAC') or die('Direct access not allowed!');

$title = 'Edit Command';

csrfProtect();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$statement = $db->prepare('SELECT * FROM `commands` WHERE `id` = ? LIMIT 1');
$statement->execute([$id]);
$cmd = $statement->fetch();

if (!$cmd) {
	error('Command not found.');
	return;
}

if (isset($_POST['save'])) {
	$words = trim($_POST['words'] ?? '');
	$description = trim($_POST['description'] ?? '');
	$group_type = $_POST['group_type'] ?? 'God';

	if (!in_array($group_type, ['Player', 'GM', 'God'], true)) {
		$group_type = 'God';
	}

	$statement = $db->prepare('UPDATE `commands` SET `words` = ?, `description` = ?, `group_type` = ? WHERE `id` = ?');
	$statement->execute([$words, $description, $group_type, $id]);

	success('Command updated.');

	$cmd['words'] = $words;
	$cmd['description'] = $description;
	$cmd['group_type'] = $group_type;
}

$twig->display('admin.tools.commands_edit.html.twig', [
	'cmd' => $cmd,
]);