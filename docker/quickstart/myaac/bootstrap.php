<?php

declare(strict_types=1);

function env_value(string $name, string $default = ''): string
{
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
}

function wait_for_database(): PDO
{
	$host = env_value('CANARY_DB_HOST', 'db');
	$port = env_value('CANARY_DB_PORT', '3306');
	$name = env_value('CANARY_DB_NAME', 'canary');
	$user = env_value('CANARY_DB_USER', 'canary');
	$password = env_value('CANARY_DB_PASSWORD', 'canary');
	$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

	for ($attempt = 1; $attempt <= 90; ++$attempt) {
		try {
			return new PDO($dsn, $user, $password, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);
		} catch (Throwable $error) {
			echo "Waiting for database ({$attempt}/90): {$error->getMessage()}\n";
			sleep(2);
		}
	}

	throw new RuntimeException('Database did not become available.');
}

function table_exists(PDO $pdo, string $table): bool
{
	$statement = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
	);
	$statement->execute([$table]);
	return (int)$statement->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
	$statement = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
	);
	$statement->execute([$table, $column]);
	return (int)$statement->fetchColumn() > 0;
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
	if (!table_exists($pdo, $table) || column_exists($pdo, $table, $column)) {
		return;
	}

	$pdo->exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
}

function wait_for_canary_schema(PDO $pdo): void
{
	for ($attempt = 1; $attempt <= 90; ++$attempt) {
		if (table_exists($pdo, 'accounts') && table_exists($pdo, 'players')) {
			return;
		}

		echo "Waiting for Canary schema ({$attempt}/90)\n";
		sleep(2);
	}

	throw new RuntimeException('Canary schema was not created before MyAAC setup.');
}

function execute_sql_script(PDO $pdo, string $sql): void
{
	$statement = '';
	$quote = null;
	$escaped = false;
	$lineComment = false;
	$blockComment = false;
	$length = strlen($sql);

	for ($index = 0; $index < $length; ++$index) {
		$char = $sql[$index];
		$next = $index + 1 < $length ? $sql[$index + 1] : '';

		if ($lineComment) {
			if ($char === "\n") {
				$lineComment = false;
			}
			continue;
		}

		if ($blockComment) {
			if ($char === '*' && $next === '/') {
				$blockComment = false;
				++$index;
			}
			continue;
		}

		if ($quote !== null) {
			$statement .= $char;
			if ($escaped) {
				$escaped = false;
			} elseif ($char === '\\') {
				$escaped = true;
			} elseif ($char === $quote) {
				$quote = null;
			}
			continue;
		}

		if ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
			$lineComment = true;
			++$index;
			continue;
		}

		if ($char === '#') {
			$lineComment = true;
			continue;
		}

		if ($char === '/' && $next === '*') {
			$blockComment = true;
			++$index;
			continue;
		}

		if ($char === "'" || $char === '"' || $char === '`') {
			$quote = $char;
			$statement .= $char;
			continue;
		}

		if ($char === ';') {
			$trimmed = trim($statement);
			if ($trimmed !== '') {
				$pdo->exec($trimmed);
			}
			$statement = '';
			continue;
		}

		$statement .= $char;
	}

	$trimmed = trim($statement);
	if ($trimmed !== '') {
		$pdo->exec($trimmed);
	}
}

function write_myaac_config(): void
{
	$serverPath = env_value('MYAAC_SERVER_PATH', '/canary/');
	if (!str_ends_with($serverPath, '/')) {
		$serverPath .= '/';
	}

	$config = [
		'env' => 'prod',
		'server_path' => $serverPath,
		'site_url' => rtrim(env_value('MYAAC_SITE_URL', 'http://localhost:8080'), '/') . '/',
		'database_overwrite' => true,
		'database_type' => 'mysql',
		'database_host' => env_value('CANARY_DB_HOST', 'db'),
		'database_port' => env_value('CANARY_DB_PORT', '3306'),
		'database_user' => env_value('CANARY_DB_USER', 'canary'),
		'database_password' => env_value('CANARY_DB_PASSWORD', 'canary'),
		'database_name' => env_value('CANARY_DB_NAME', 'canary'),
		'database_encryption' => 'sha1',
		'gzip_output' => false,
		'cache_engine' => 'auto',
		'cache_prefix' => 'myaac_docker_',
		'database_auto_migrate' => true,
	];

	$content = "<?php\n";
	$content .= "\$config['installed'] = true;\n";
	foreach ($config as $key => $value) {
		$content .= "\$config['{$key}'] = " . var_export($value, true) . ";\n";
	}

	if (file_put_contents('/var/www/html/config.local.php', $content) === false) {
		throw new RuntimeException('Could not write /var/www/html/config.local.php.');
	}
}

function import_myaac_schema(PDO $pdo): void
{
	if (table_exists($pdo, 'myaac_account_actions')) {
		echo "MyAAC schema already exists.\n";
		return;
	}

	echo "Importing MyAAC schema...\n";
	$schema = file_get_contents('/var/www/html/install/includes/schema.sql');
	if ($schema === false) {
		throw new RuntimeException('Could not read MyAAC schema.sql.');
	}

	execute_sql_script($pdo, $schema);
}

function myaac_database_version(): int
{
	$common = file_get_contents('/var/www/html/common.php');
	if ($common === false || !preg_match('/const\s+DATABASE_VERSION\s*=\s*(\d+);/', $common, $matches)) {
		throw new RuntimeException('Could not detect MyAAC DATABASE_VERSION.');
	}

	return (int)$matches[1];
}

function set_myaac_database_version(PDO $pdo): void
{
	$version = (string)myaac_database_version();
	$statement = $pdo->prepare(
		'INSERT INTO myaac_config (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
	);
	$statement->execute(['database_version', $version]);
}

function myaac_quickstart_installed(PDO $pdo): bool
{
	if (!table_exists($pdo, 'myaac_config')) {
		return false;
	}

	$statement = $pdo->prepare('SELECT `value` FROM myaac_config WHERE `name` = ? LIMIT 1');
	$statement->execute(['docker_quickstart_installed']);
	return $statement->fetchColumn() !== false;
}

function ensure_canary_myaac_columns(PDO $pdo): void
{
	add_column_if_missing($pdo, 'accounts', 'key', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `email`");
	add_column_if_missing($pdo, 'accounts', 'created', "INT(11) NOT NULL DEFAULT 0 AFTER `key`");
	add_column_if_missing($pdo, 'accounts', 'rlname', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `created`");
	add_column_if_missing($pdo, 'accounts', 'location', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `rlname`");
	add_column_if_missing($pdo, 'accounts', 'country', "VARCHAR(3) NOT NULL DEFAULT '' AFTER `location`");
	add_column_if_missing($pdo, 'accounts', 'web_lastlogin', "INT(11) NOT NULL DEFAULT 0 AFTER `country`");
	add_column_if_missing($pdo, 'accounts', 'web_flags', "INT(11) NOT NULL DEFAULT 0 AFTER `web_lastlogin`");
	add_column_if_missing($pdo, 'accounts', 'email_verified', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `web_flags`");
	add_column_if_missing($pdo, 'accounts', 'email_new', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `email_verified`");
	add_column_if_missing($pdo, 'accounts', 'email_new_time', "INT(11) NOT NULL DEFAULT 0 AFTER `email_new`");
	add_column_if_missing($pdo, 'accounts', 'email_code', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `email_new_time`");
	add_column_if_missing($pdo, 'accounts', 'email_next', "INT(11) NOT NULL DEFAULT 0 AFTER `email_code`");
	add_column_if_missing($pdo, 'accounts', 'premium_points', "INT(11) NOT NULL DEFAULT 0 AFTER `email_next`");

	add_column_if_missing($pdo, 'players', 'created', 'INT(11) NOT NULL DEFAULT 0');
	if (!column_exists($pdo, 'players', 'deletion')) {
		add_column_if_missing($pdo, 'players', 'deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
	}
	add_column_if_missing($pdo, 'players', 'hide', 'TINYINT(1) NOT NULL DEFAULT 0');
	add_column_if_missing($pdo, 'players', 'comment', "VARCHAR(5000) NOT NULL DEFAULT ''");

	if (table_exists($pdo, 'guilds')) {
		add_column_if_missing($pdo, 'guilds', 'motd', "VARCHAR(255) NOT NULL DEFAULT ''");
		add_column_if_missing($pdo, 'guilds', 'description', "VARCHAR(5000) NOT NULL DEFAULT ''");
		add_column_if_missing($pdo, 'guilds', 'logo_name', "VARCHAR(255) NOT NULL DEFAULT 'default.gif'");
	}
}

function ensure_commands_table(PDO $pdo): void
{
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS `commands` ('
		. '`id` INT(11) NOT NULL AUTO_INCREMENT,'
		. '`words` VARCHAR(255) NOT NULL,'
		. '`description` VARCHAR(255) NOT NULL DEFAULT \'\','
		. '`group_type` ENUM(\'Player\',\'GM\',\'God\') NOT NULL DEFAULT \'God\','
		. '`hide` TINYINT(1) NOT NULL DEFAULT 0,'
		. 'PRIMARY KEY (`id`)'
		. ') ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4'
	);

	$seeded = $pdo->prepare('SELECT `value` FROM myaac_config WHERE `name` = ? LIMIT 1');
	$seeded->execute(['commands_seeded']);
	if ($seeded->fetchColumn() !== false) {
		return;
	}

	$commands = [
		// God
		['!testcontainer', 'Inspects your backpack container (test).', 'God'],
		['/addachievement', 'Gives an achievement to a player.', 'God'],
		['/addaddon', 'Adds an outfit addon to a player.', 'God'],
		['/addbadge', 'Adds a badge to a player.', 'God'],
		['/addbosskill', 'Adds a boss kill to a player bosstiary.', 'God'],
		['/addcharms', 'Adds charm points to a player.', 'God'],
		['/adddustlevel', 'Adds forge dust level to a player.', 'God'],
		['/adddusts', 'Adds forge dusts to a player.', 'God'],
		['/addloot', 'Adds an item to your Loot Pouch.', 'God'],
		['/addminorcharms', 'Adds minor charm points to a player.', 'God'],
		['/addmoney', 'Adds money to a player.', 'God'],
		['/addmount', 'Adds a mount to a player.', 'God'],
		['/addreward', 'Adds an item to a reward chest.', 'God'],
		['/addskill', 'Adds skill levels to a player.', 'God'],
		['/addtitle', 'Adds a title to a player.', 'God'],
		['/addtutor', 'Promotes a player to tutor.', 'God'],
		['/areasound', 'Plays a sound in an area.', 'God'],
		['/attr', 'Sets attributes on an item, creature, or player.', 'God'],
		['/bakragoreicon', 'Gives the Bakragore icon.', 'God'],
		['/bountypoints', 'Reads or adjusts a player bounty points.', 'God'],
		['/changeflowmap', 'Changes the Soul War flow map.', 'God'],
		['/charmexpansion', 'Grants charm expansion to a player.', 'God'],
		['/charmrunes', 'Unlocks all charm runes for a player.', 'God'],
		['/checkachievements', 'Lists a player achievements.', 'God'],
		['/clearcooldown', 'Clears a boss cooldown for a player.', 'God'],
		['/clearhirelingstas', 'Clears hireling stats.', 'God'],
		['/clearloot', 'Removes all items from your Loot Pouch.', 'God'],
		['/closeserver', 'Closes, saves, or shuts down the server.', 'God'],
		['/countloot', 'Counts the items in your Loot Pouch.', 'God'],
		['/createloot', 'Fills your Loot Pouch with random test items.', 'God'],
		['/createtestshop', 'Fills your Loot Pouch with shop test items.', 'God'],
		['/fiendish', 'Teleports to a fiendish monster.', 'God'],
		['/forceperiod', 'Forces the world light period.', 'God'],
		['/getallkv', 'Lists all key-value entries.', 'God'],
		['/getdusts', 'Shows a player forge dusts.', 'God'],
		['/getkv', 'Reads a key-value entry.', 'God'],
		['/globalsound', 'Plays a global sound.', 'God'],
		['/gotohouse', 'Teleports to a house.', 'God'],
		['/hasflag', 'Checks if a player has a flag.', 'God'],
		['/hireling', 'Creates a hireling lamp.', 'God'],
		['/i', 'Creates an item.', 'God'],
		['/inbox', 'Sends an item to a player inbox.', 'God'],
		['/influenced', 'Teleports to an influenced monster.', 'God'],
		['/internalsound', 'Plays an internal sound.', 'God'],
		['/ipban', 'Bans an IP address.', 'God'],
		['/listraid', 'Lists the available raids.', 'God'],
		['/m', 'Creates monsters around you.', 'God'],
		['/n', 'Creates an NPC.', 'God'],
		['/openforge', 'Opens the forge for a player.', 'God'],
		['/openserver', 'Opens the server for logins.', 'God'],
		['/owner', 'Sets or clears the owner of a house.', 'God'],
		['/playericon', 'Manages player icons.', 'God'],
		['/probeopcode', 'Probes a client protocol opcode.', 'God'],
		['/proficiency', 'Adds weapon experience to your equipped weapon.', 'God'],
		['/protocolprobe', 'Probes a client protocol message.', 'God'],
		['/r', 'Removes items from the map.', 'God'],
		['/raid', 'Starts a raid by name.', 'God'],
		['/reload', 'Reloads server configuration or data (param: all, items, monsters, ...).', 'God'],
		['/removeachievement', 'Removes an achievement from a player.', 'God'],
		['/removedusts', 'Removes forge dusts from a player.', 'God'],
		['/removeflag', 'Removes a flag from a player.', 'God'],
		['/removetaint', 'Removes a player taint state.', 'God'],
		['/removetutor', 'Removes tutor status from a player.', 'God'],
		['/resetcd', 'Resets cooldowns for a player.', 'God'],
		['/resetcharms', 'Resets a player charms.', 'God'],
		['/s', 'Creates a summon.', 'God'],
		['/save', 'Saves the current server state.', 'God'],
		['/setbestiary', 'Sets bestiary progress for a creature.', 'God'],
		['/setdusts', 'Sets a player forge dusts.', 'God'],
		['/setfiendish', 'Sets a new fiendish monster.', 'God'],
		['/setflag', 'Grants a flag to a player.', 'God'],
		['/setkv', 'Sets a key-value entry.', 'God'],
		['/setmonstername', 'Renames a monster.', 'God'],
		['/setstorage', 'Sets a player storage value.', 'God'],
		['/settaint', 'Sets a player taint state.', 'God'],
		['/settitle', 'Sets a player active title.', 'God'],
		['/simraid', 'Simulates a raid without running it.', 'God'],
		['/soulseals', 'Reads or adjusts a player soulseals.', 'God'],
		['/spawn', 'Creates a monster spawn.', 'God'],
		['/taskboarddelivery', 'Delivers a task board reward.', 'God'],
		['/taskpoints', 'Reads or adjusts a player task points.', 'God'],
		['/taskslot', 'Manages task board slots.', 'God'],
		['/testicon', 'Tests player icons.', 'God'],
		['/testlog', 'Writes a test log entry.', 'God'],
		['/testmessage', 'Sends a test message.', 'God'],
		['/testtaintconditions', 'Sets the taint icon on yourself.', 'God'],
		['/vip', 'Manages a player\'s VIP days.', 'God'],
		['/zones', 'Manages zones.', 'God'],
		// GM
		['!pos', 'Shows your position or teleports to a position.', 'GM'],
		['!position', 'Shows your current position.', 'GM'],
		['/a', 'Teleports N tiles in the direction you face.', 'GM'],
		['/active', 'Teleports to an active player.', 'GM'],
		['/afk', 'Toggles AFK status.', 'GM'],
		['/ambientsound', 'Plays an ambient sound.', 'GM'],
		['/b', 'Broadcasts a message.', 'GM'],
		['/ban', 'Bans a player or account.', 'GM'],
		['/bless', 'Shows your blessing status.', 'GM'],
		['/c', 'Moves a creature to a free tile near you.', 'GM'],
		['/clean', 'Cleans items from the floor.', 'GM'],
		['/countmonsters', 'Counts monsters from the spawn file.', 'GM'],
		['/distanceeffect', 'Plays a distance effect.', 'GM'],
		['/down', 'Moves down a floor.', 'GM'],
		['/effect', 'Plays a magic effect.', 'GM'],
		['/getlook', 'Shows a creature\'s outfit XML.', 'GM'],
		['/getstorage', 'Reads a player storage value.', 'GM'],
		['/ghost', 'Toggles ghost mode.', 'GM'],
		['/goldrank', 'Shows the gold highscore.', 'GM'],
		['/goto', 'Teleports to a creature.', 'GM'],
		['/info', 'Shows player info.', 'GM'],
		['/kick', 'Kicks a player.', 'GM'],
		['/listplayers', 'Lists active players to teleport to.', 'GM'],
		['/looktype', 'Sets your outfit look type.', 'GM'],
		['/mc', 'Checks for multi-client.', 'GM'],
		['/musicsound', 'Plays a music sound.', 'GM'],
		['/namelock', 'Name-locks a player.', 'GM'],
		['/pos', 'Shows your position or teleports to a position.', 'GM'],
		['/rewardbag', 'Simulates opening a reward bag (test).', 'GM'],
		['/setlight', 'Sets the light level.', 'GM'],
		['/spy', 'Shows a player\'s equipment.', 'GM'],
		['/t', 'Teleports you or a player to the temple.', 'GM'],
		['/teleport', 'Creates a teleport to a destination position.', 'GM'],
		['/town', 'Teleports to a town.', 'GM'],
		['/tp', 'Creates a teleport to a destination position.', 'GM'],
		['/unban', 'Unbans a player or account.', 'GM'],
		['/up', 'Moves up a floor.', 'GM'],
		// Player
		['!aol', 'Buys an amulet of loss.', 'Player'],
		['!autoloot', 'Sets auto loot mode (all/on/off).', 'Player'],
		['!balance', 'Shows your bank balance.', 'Player'],
		['!bless', 'Buys all blessings.', 'Player'],
		['!buyhouse', 'Buys a house.', 'Player'],
		['!checktaint', 'Shows your taint state.', 'Player'],
		['!checkvip', 'Shows your VIP status.', 'Player'],
		['!commands', 'Lists the available commands.', 'Player'],
		['!deposit', 'Deposits money in the bank.', 'Player'],
		['!emote', 'Toggles emote spells (on/off).', 'Player'],
		['!flask', 'Toggles whether you receive flasks (on/off).', 'Player'],
		['!hiddenshop', 'Toggles hidden sell shop items (on/off).', 'Player'],
		['!leavehouse', 'Leaves a house.', 'Player'],
		['!livestream', 'Manages the livestream system.', 'Player'],
		['!online', 'Lists online players.', 'Player'],
		['!refill', 'Refills chargeable amulets and rings with silver tokens.', 'Player'],
		['!reward', 'Claims your exercise weapon reward.', 'Player'],
		['!sellhouse', 'Sells a house.', 'Player'],
		['!serverinfo', 'Shows server information.', 'Player'],
		['!time', 'Shows the server time.', 'Player'],
		['!transfer', 'Transfers money to another player.', 'Player'],
		['!vip', 'Shows your VIP status.', 'Player'],
		['!withdraw', 'Withdraws money from the bank.', 'Player'],
	];

	$exists = $pdo->prepare('SELECT COUNT(*) FROM `commands` WHERE `words` = ?');
	$insert = $pdo->prepare('INSERT INTO `commands` (`words`, `description`, `group_type`) VALUES (?, ?, ?)');
	foreach ($commands as $command) {
		$exists->execute([$command[0]]);
		if ((int)$exists->fetchColumn() === 0) {
			$insert->execute($command);
		}
	}

	$mark = $pdo->prepare('INSERT INTO myaac_config (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
	$mark->execute(['commands_seeded', date(DATE_ATOM)]);
}

function finish_myaac_install(PDO $pdo): void
{
	global $cache, $config, $db, $eloquentConnection, $hooks, $locale, $ots, $twig;

	if (myaac_quickstart_installed($pdo)) {
		echo "MyAAC quickstart already installed.\n";
		return;
	}

	require_once '/var/www/html/common.php';
	require_once SYSTEM . 'functions.php';
	require_once BASE . 'install/includes/locale.php';
	require_once SYSTEM . 'init.php';

	$adminAccount = env_value('MYAAC_ADMIN_ACCOUNT', 'myaacadmin');
	$adminEmail = env_value('MYAAC_ADMIN_EMAIL', 'admin@localhost.local');
	$adminPassword = env_value('MYAAC_ADMIN_PASSWORD', 'admin123');
	$adminPlayer = env_value('MYAAC_ADMIN_PLAYER', 'MyAAC Admin');

	$groups = new OTS_Groups_List();
	$highestGroupId = max(1, (int)$groups->getHighestId());
	$account = new OTS_Account();
	$account->find($adminAccount);

	if (!$account->isLoaded()) {
		$account->create($adminAccount);
	}

	$account->setPassword(encrypt($adminPassword));
	$account->setEMail($adminEmail);
	$account->save();
	$account->setCustomField('created', time());
	$account->setCustomField('web_flags', FLAG_ADMIN + FLAG_SUPER_ADMIN);
	$account->setCustomField('country', 'br');
	$account->setCustomField('email_verified', 1);

	if ($GLOBALS['db']->hasColumn('accounts', 'group_id')) {
		$account->setCustomField('group_id', $highestGroupId);
	}

	if ($GLOBALS['db']->hasColumn('accounts', 'type')) {
		$account->setCustomField('type', 6);
	}

	if ($GLOBALS['db']->hasTable('players')) {
		$player = new OTS_Player();
		$player->find($adminPlayer);

		if (!$player->isLoaded()) {
			$player->setName($adminPlayer);
			$player->setAccountId($account->getId());
			$player->setGroupId($highestGroupId);
			$player->save();
		} else {
			$player->setAccountId($account->getId());
			$player->save();
		}
	}

	require BASE . 'install/includes/import_base_data.php';

	if (function_exists('clearCache')) {
		clearCache();
	}

	foreach ([17, 20, 22, 27, 30, 31, 45] as $migration) {
		$path = SYSTEM . "migrations/{$migration}.php";
		if (is_file($path)) {
			require $path;
			if (isset($up) && is_callable($up)) {
				$up();
			}
			unset($up);
		}
	}

	if (class_exists(\MyAAC\Models\FAQ::class) && \MyAAC\Models\FAQ::count() === 0) {
		\MyAAC\Models\FAQ::create([
			'question' => 'What is this?',
			'answer' => 'This is a Canary quickstart website powered by MyAAC.',
		]);
	}

	if (class_exists(\MyAAC\Models\News::class) && \MyAAC\Models\News::count() === 0) {
		\MyAAC\Models\News::create([
			'type' => 1,
			'date' => time(),
			'category' => 2,
			'title' => 'Canary Docker quickstart',
			'body' => 'Your local Canary server is ready to use.',
			'player_id' => 0,
			'comments' => 'https://docs.opentibiabr.com/',
			'hide' => 0,
		]);
	}

	$settings = \MyAAC\Settings::getInstance();
	$settings->updateInDatabase('core', 'anonymous_usage_statistics', 'false');
	$settings->updateInDatabase('core', 'date_timezone', env_value('MYAAC_TIMEZONE', 'America/Fortaleza'));
	$settings->updateInDatabase('core', 'client', env_value('MYAAC_CLIENT_VERSION', '1513'));

	$statement = $pdo->prepare(
		'INSERT INTO myaac_config (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
	);
	$statement->execute(['docker_quickstart_installed', date(DATE_ATOM)]);
}

chdir('/var/www/html');
write_myaac_config();

$pdo = wait_for_database();
wait_for_canary_schema($pdo);
import_myaac_schema($pdo);
ensure_canary_myaac_columns($pdo);
ensure_commands_table($pdo);
set_myaac_database_version($pdo);
finish_myaac_install($pdo);
echo "MyAAC quickstart is ready.\n";
