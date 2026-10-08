<?php

defined('MYAAC') or die('Direct access not allowed!');

$title = 'In-Game Commands';

$commands = $db->query('SELECT * FROM `commands` ORDER BY FIELD(`group_type`, \'God\', \'GM\', \'Player\'), `words` ASC')->fetchAll();

$twig->display('admin.tools.commands.html.twig', [
	'commands' => $commands,
]);