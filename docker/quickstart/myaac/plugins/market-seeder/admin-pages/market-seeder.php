<?php
/**
 * market-seeder - pagina de admin
 *
 * Lista/edita os itens que serao publicados no market como ofertas anonimas do
 * personagem dono (configurado em Admin -> Settings -> Market Seeder).
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'market-seeder/lib.php';

$title = 'Market Seeder';

csrfProtect();
market_seeder_ensure_tables();

$action = $_POST['action'] ?? '';

if ($action === 'save_all') {
	$items = $_POST['item'] ?? [];
	$amounts = $_POST['amount'] ?? [];
	$prices = $_POST['price'] ?? [];
	$tiers = $_POST['tier'] ?? [];
	$daysArr = $_POST['days'] ?? [];
	$stacksArr = $_POST['stacks'] ?? [];
	$enabled = $_POST['enabled'] ?? [];
	$deletes = $_POST['delete'] ?? [];

	$saved = 0;
	$removed = 0;
	$unresolved = [];

	foreach ($items as $id => $itemInput) {
		$id = (int)$id;
		if ($id <= 0) {
			continue;
		}

		if (!empty($deletes[$id])) {
			$db->query('DELETE FROM `' . market_seeder_table('items') . '` WHERE `id` = ' . $id);
			$removed++;
			continue;
		}

		[$itemId, $name] = market_seeder_resolve_item((string)$itemInput);
		if ($itemId <= 0) {
			$unresolved[] = (string)$itemInput;
			continue;
		}
		if ($name === '') {
			$name = market_seeder_item_name($itemId);
		}

		$amount = max(1, (int)($amounts[$id] ?? 1));
		$price = max(0, (int)($prices[$id] ?? 0));
		$tier = max(0, (int)($tiers[$id] ?? 0));
		$days = max(1, (int)($daysArr[$id] ?? 30));
		$stacks = max(1, (int)($stacksArr[$id] ?? 1));
		$isEnabled = isset($enabled[$id]) ? 1 : 0;

		$db->query(
			'UPDATE `' . market_seeder_table('items') . '` SET `item_id` = ' . $itemId .
			', `name` = ' . $db->quote($name) .
			', `amount` = ' . $amount .
			', `price` = ' . $price .
			', `tier` = ' . $tier .
			', `days` = ' . $days .
			', `stacks` = ' . $stacks .
			', `enabled` = ' . $isEnabled .
			' WHERE `id` = ' . $id
		);
		$saved++;
	}

	if ($saved > 0) {
		success($saved . ' item(ns) salvo(s).');
	}
	if ($removed > 0) {
		success($removed . ' item(ns) removido(s).');
	}
	if (!empty($unresolved)) {
		warning('Nao resolvidos (linha mantida como estava): ' . htmlspecialchars(implode(', ', array_slice($unresolved, 0, 50))));
	}
} elseif ($action === 'add') {
	[$itemId, $name] = market_seeder_resolve_item((string)($_POST['item'] ?? ''));
	if ($itemId <= 0) {
		error('Item nao encontrado. Informe o nome exato de items.xml ou um id numerico.');
	} else {
		if ($name === '') {
			$name = market_seeder_item_name($itemId);
		}
		$amount = max(1, (int)($_POST['amount'] ?? 1));
		$price = max(0, (int)($_POST['price'] ?? 0));
		$tier = max(0, (int)($_POST['tier'] ?? 0));
		$days = max(1, (int)($_POST['days'] ?? 30));
		$stacks = max(1, (int)($_POST['stacks'] ?? 1));
		$db->query(
			'INSERT INTO `' . market_seeder_table('items') . '` (`item_id`,`name`,`amount`,`price`,`tier`,`days`,`stacks`,`enabled`) VALUES (' .
			$itemId . ',' . $db->quote($name) . ',' . $amount . ',' . $price . ',' . $tier . ',' . $days . ',' . $stacks . ',1)'
		);
		success('Item adicionado: ' . htmlspecialchars($name) . ' (id ' . $itemId . ').');
	}
} elseif ($action === 'bulk_import') {
	$result = market_seeder_import_text((string)($_POST['list'] ?? ''));
	success($result['imported'] . ' item(ns) importado(s).' . ($result['skipped'] > 0 ? ' ' . $result['skipped'] . ' ja existiam (ignorados).' : ''));
	if (!empty($result['missing'])) {
		warning('Nao encontrados: ' . htmlspecialchars(implode(', ', array_slice($result['missing'], 0, 50))) . (count($result['missing']) > 50 ? ' ...' : ''));
	}
} elseif ($action === 'import_defaults') {
	$result = market_seeder_import_defaults();
	market_seeder_state_set('defaults_imported', '1');
	success($result['imported'] . ' item(ns) importado(s) da lista padrao.' . ($result['skipped'] > 0 ? ' ' . $result['skipped'] . ' ja existiam (ignorados).' : ''));
	if (!empty($result['missing'])) {
		warning('Nao encontrados: ' . htmlspecialchars(implode(', ', array_slice($result['missing'], 0, 50))) . (count($result['missing']) > 50 ? ' ...' : ''));
	}
} elseif ($action === 'run_now') {
		$result = market_seeder_run(true);
		if (empty($result['ok'])) {
			error($result['message'] ?? 'Falha ao executar.');
		} elseif (!empty($result['skipped'])) {
			info(($result['message'] ?? 'Ignorado.') . sprintf(' Inbox do dono limpa: %d item(ns) removido(s).', $result['inbox_cleared'] ?? 0));
		} else {
			success(sprintf('Execucao concluida: %d inserida(s), %d atualizada(s), %d removida(s), %d limpa(s) da inbox do dono.', $result['inserted'] ?? 0, $result['updated'] ?? 0, $result['deleted'] ?? 0, $result['inbox_cleared'] ?? 0));
		}
} elseif ($action === 'clear_offers') {
	$ownerId = market_seeder_player_id(trim((string)market_seeder_setting('owner', 'ADM1')));
	if ($ownerId > 0) {
		$db->exec('DELETE FROM `market_offers` WHERE `player_id` = ' . $ownerId);
		success('Ofertas do dono removidas do market.');
	} else {
		error('Personagem dono nao encontrado.');
	}
}

// Dados para renderizacao
$items = $db->query('SELECT * FROM `' . market_seeder_table('items') . '` ORDER BY `name` ASC, `id` ASC')->fetchAll(PDO::FETCH_ASSOC);
$owner = trim((string)market_seeder_setting('owner', 'ADM1'));
$ownerId = market_seeder_player_id($owner);
$ownOffers = $ownerId > 0 ? market_seeder_own_offer_count($ownerId) : 0;
$lastRunAt = market_seeder_state_get('last_run_at', '-');
$itemsXml = ($config['data_path'] ?? '') . 'items/items.xml';
$itemsXmlOk = is_file($itemsXml);
	$listInputPlaceholder = "Vampire Teeth;25;1400;0;30;1\nDiamond Arrow;100;15000;0;30;2";
?>

<h2>Market Seeder</h2>

<?php if (!$itemsXmlOk): ?>
	<div class="alert alert-warning">
		<strong>items.xml nao encontrado</strong> em <code><?= htmlspecialchars($itemsXml) ?></code>.
		A resolucao de itens por <em>nome</em> nao vai funcionar; use o id numerico.
		Monte <code>../data/items/items.xml</code> em <code>/canary/data/items/items.xml</code> no container do myaac.
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-body">
		<p class="mb-1">
			<strong>Dono das ofertas:</strong> <?= htmlspecialchars($owner) ?>
			<?= $ownerId > 0 ? '(id ' . $ownerId . ')' : '<span class="text-danger">(nao encontrado)</span>' ?>
			&nbsp;|&nbsp; <strong>Ofertas ativas no market:</strong> <?= (int)$ownOffers ?>
			&nbsp;|&nbsp; <strong>Ultima execucao diaria:</strong> <?= htmlspecialchars((string)$lastRunAt) ?>
		</p>
		<p class="text-muted mb-0">
			Dono, modo ("so quando vazio") e importacao automatica ficam em
			<a href="<?= ADMIN_URL ?>?p=settings">Admin &rarr; Settings &rarr; Market Seeder</a>.
			A rotina roda automaticamente <strong>uma vez por dia</strong> (na primeira visita ao site/admin do dia).
			A cada execucao as ofertas do dono tem a validade renovada (nao expiram) e a
			<strong>inbox do dono e esvaziada</strong> (itens devolvidos por ofertas expiradas).
			Ou execute agora:
		</p>
		<form method="post" class="mt-2 d-inline">
			<?php csrf(); ?>
			<input type="hidden" name="action" value="run_now">
			<button type="submit" class="btn btn-success"><i class="fas fa-play"></i> Rodar agora (forcado)</button>
		</form>
		<form method="post" class="d-inline" onsubmit="return confirm('Remover TODAS as ofertas do dono do market?');">
			<?php csrf(); ?>
			<input type="hidden" name="action" value="clear_offers">
			<button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Limpar ofertas do market</button>
		</form>
	</div>
</div>

<h3 class="mt-4">Itens (<?= count($items) ?>)</h3>
<form method="post">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="save_all">
	<div class="table-responsive">
		<table class="table table-sm table-striped">
			<thead>
				<tr>
					<th style="width:26%">Item (nome ou id)</th>
					<th style="width:8%">Stack</th>
					<th style="width:12%">Pre&ccedil;o (un.)</th>
					<th style="width:7%">Tier</th>
					<th style="width:8%">Dias</th>
					<th style="width:11%">Stacks</th>
					<th style="width:7%">Ativo</th>
					<th style="width:9%">Remover</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($items as $item): ?>
				<?php $id = (int)$item['id']; ?>
				<tr>
					<td>
						<input type="text" class="form-control form-control-sm" name="item[<?= $id ?>]" value="<?= htmlspecialchars((string)$item['name']) ?>">
						<small class="text-muted">id <?= (int)$item['item_id'] ?></small>
					</td>
					<td><input type="number" min="1" class="form-control form-control-sm" name="amount[<?= $id ?>]" value="<?= (int)$item['amount'] ?>" title="Unidades por oferta (tamanho do stack)"></td>
					<td><input type="number" min="0" class="form-control form-control-sm" name="price[<?= $id ?>]" value="<?= (int)$item['price'] ?>"></td>
					<td><input type="number" min="0" class="form-control form-control-sm" name="tier[<?= $id ?>]" value="<?= (int)$item['tier'] ?>"></td>
					<td><input type="number" min="1" class="form-control form-control-sm" name="days[<?= $id ?>]" value="<?= (int)$item['days'] ?>"></td>
					<td><input type="number" min="1" class="form-control form-control-sm" name="stacks[<?= $id ?>]" value="<?= (int)($item['stacks'] ?? 1) ?>" title="Numero de ofertas (stacks) no market"></td>
					<td class="text-center"><input type="checkbox" name="enabled[<?= $id ?>]" value="1" <?= ((int)$item['enabled'] === 1 ? 'checked' : '') ?>></td>
					<td class="text-center"><input type="checkbox" name="delete[<?= $id ?>]" value="1"></td>
				</tr>
			<?php endforeach; ?>
			<?php if (empty($items)): ?>
				<tr><td colspan="8" class="text-center text-muted">Nenhum item. Adicione abaixo ou importe a lista padrao.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar altera&ccedil;&otilde;es</button>
</form>

<h3 class="mt-4">Adicionar item</h3>
<form method="post" class="form-inline">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="add">
	<input type="text" class="form-control mb-2 mr-2" name="item" placeholder="Nome do item ou id" required>
	<input type="number" min="1" class="form-control mb-2 mr-2" name="amount" value="1" title="Unidades por oferta" placeholder="Stack">
	<input type="number" min="0" class="form-control mb-2 mr-2" name="price" value="0" title="Preco por unidade" placeholder="Preco">
	<input type="number" min="0" class="form-control mb-2 mr-2" name="tier" value="0" title="Tier" placeholder="Tier">
	<input type="number" min="1" class="form-control mb-2 mr-2" name="days" value="30" title="Dias no market" placeholder="Dias">
	<input type="number" min="1" class="form-control mb-2 mr-2" name="stacks" value="1" title="Numero de ofertas (stacks)" placeholder="Stacks">
	<button type="submit" class="btn btn-success mb-2"><i class="fas fa-plus"></i> Adicionar</button>
</form>

<h3 class="mt-4">Importar lista em massa</h3>
<p class="text-muted">
	Uma linha por item, no formato <code>nome;stack;preco;tier;dias;stacks</code> (ou <code>id;...</code>).
	<code>stack</code> = unidades por oferta; <code>stacks</code> = quantas ofertas (opcional, padrao 1).
	Linhas em branco e com <code>#</code> s&atilde;o ignoradas. Itens n&atilde;o encontrados s&atilde;o listados e n&atilde;o importados.
</p>
<form method="post">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="bulk_import">
	<textarea class="form-control" name="list" rows="8" placeholder="<?= htmlspecialchars($listInputPlaceholder) ?>"></textarea>
	<div class="mt-2">
		<button type="submit" class="btn btn-primary"><i class="fas fa-file-import"></i> Importar</button>
	</div>
</form>

<form method="post" class="mt-2" onsubmit="return confirm('Anexar a lista padrao do plugin a lista atual?');">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="import_defaults">
	<button type="submit" class="btn btn-secondary"><i class="fas fa-download"></i> Anexar lista padrao do plugin</button>
</form>

<p class="text-muted mt-4 mb-0">
	<b>Sobre o campo Dias:</b> a expira&ccedil;&atilde;o do market &eacute; global
	(<code>marketOfferDuration</code> no config.lua, normalmente 30 dias).
	O valor em "Dias" agenda a oferta para expirar em N dias (limitado ao m&aacute;ximo acima);
	ao expirar, a pr&oacute;xima execu&ccedil;&atilde;o di&aacute;ria a recria.
</p>
