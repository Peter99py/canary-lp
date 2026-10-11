<?php
/**
 * give-item - pagina de admin
 *
 * Da itens a um personagem, como o comando /i. O pedido e gravado em
 * myaac_item_delivery e entregue pelo servidor (script Lua), normalmente em
 * poucos segundos se o personagem estiver online, ou no proximo login.
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'give-item/lib.php';

$title = 'Give Item';

csrfProtect();
give_item_ensure_tables();

$action = $_POST['action'] ?? '';

if ($action === 'give') {
	$playerInput = trim((string)($_POST['player'] ?? ''));
	[$playerId, $playerName] = give_item_player($playerInput);

	if ($playerId <= 0) {
		error('Personagem nao encontrado: ' . htmlspecialchars($playerInput));
	} else {
		[$itemId, $name] = give_item_resolve_item((string)($_POST['item'] ?? ''));
		if ($itemId <= 0) {
			error('Item nao encontrado. Informe o nome exato de items.xml ou um id numerico.');
		} else {
			if ($name === '') {
				$name = give_item_item_name($itemId);
			}
			$count = max(1, (int)($_POST['count'] ?? 1));
			$tier = max(0, (int)($_POST['tier'] ?? 0));
			$target = (string)($_POST['target'] ?? 'backpack');

			$id = give_item_enqueue($playerId, $playerName, $itemId, $name, $count, $tier, $target);
			if ($id > 0) {
				success(sprintf(
					'Pedido #%d criado: %dx %s (id %d, tier %d) para %s. A entrega ocorre em instantes se ele estiver online; senao, no proximo login.',
					$id,
					$count,
					htmlspecialchars($name !== '' ? $name : ('id ' . $itemId)),
					$itemId,
					$tier,
					htmlspecialchars($playerName)
				));
			} else {
				error('Falha ao gravar o pedido.');
			}
		}
	}
} elseif ($action === 'retry') {
	$id = (int)($_POST['id'] ?? 0);
	if ($id > 0) {
		$db->query(
			'UPDATE `' . give_item_table() . "` SET `status` = 'pending', `message` = '', `processed_at` = 0 WHERE `id` = " . $id
		);
		success('Pedido #' . $id . ' reenviado para a fila.');
	}
} elseif ($action === 'delete') {
	$id = (int)($_POST['id'] ?? 0);
	if ($id > 0) {
		$db->query('DELETE FROM `' . give_item_table() . '` WHERE `id` = ' . $id);
		success('Pedido #' . $id . ' removido.');
	}
} elseif ($action === 'clear_finished') {
	$db->query('DELETE FROM `' . give_item_table() . "` WHERE `status` <> 'pending'");
	success('Pedidos concluidos/falhos removidos.');
}

$counts = give_item_counts();
$deliveries = give_item_recent(100);
$itemsXml = ($config['data_path'] ?? '') . 'items/items.xml';
$itemsXmlOk = is_file($itemsXml);

$targetLabels = [
	'backpack' => 'Backpack',
	'store_inbox' => 'Store Inbox',
];
$statusLabels = [
	'pending' => 'Pendente',
	'done' => 'Entregue',
	'failed' => 'Falhou',
];
?>

<h2>Give Item</h2>

<?php if (!$itemsXmlOk): ?>
	<div class="alert alert-warning">
		<strong>items.xml nao encontrado</strong> em <code><?= htmlspecialchars($itemsXml) ?></code>.
		A resolucao de itens por <em>nome</em> nao vai funcionar; use o id numerico.
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-body">
		<p class="mb-1">
			<strong>Pendentes:</strong> <?= (int)$counts['pending'] ?>
			&nbsp;|&nbsp; <strong>Entregues:</strong> <?= (int)$counts['done'] ?>
			&nbsp;|&nbsp; <strong>Falhas:</strong> <?= (int)$counts['failed'] ?>
		</p>
		<p class="text-muted mb-0">
			O item e entregue pelo servidor: em poucos segundos se o personagem estiver
			<strong>online</strong>, ou no <strong>proximo login</strong> se estiver offline.
			O destino padrao e a backpack/containers; se o personagem nao tiver backpack, o
			servidor cria uma. Se nao houver espaco, o pedido fica como <em>Falhou</em> com o motivo.
		</p>
	</div>
</div>

<h3 class="mt-4">Dar item</h3>
<form method="post" class="form-inline">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="give">
	<input type="text" class="form-control mb-2 mr-2" name="player" placeholder="Personagem" required>
	<input type="text" class="form-control mb-2 mr-2" name="item" placeholder="Item (nome ou id)" required>
	<input type="number" min="1" class="form-control mb-2 mr-2" name="count" value="1" title="Quantidade" placeholder="Qtd">
	<input type="number" min="0" class="form-control mb-2 mr-2" name="tier" value="0" title="Tier (0-10)" placeholder="Tier">
	<select class="form-control mb-2 mr-2" name="target" title="Destino">
		<option value="backpack">Backpack</option>
		<option value="store_inbox">Store Inbox</option>
	</select>
	<button type="submit" class="btn btn-success mb-2"><i class="fas fa-plus"></i> Dar item</button>
</form>

<h3 class="mt-4">Pedidos (ultimos 100)</h3>
<form method="post" class="mb-2" onsubmit="return confirm('Remover todos os pedidos entregues/falhos?');">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="clear_finished">
	<button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-broom"></i> Limpar entregues/falhos</button>
</form>

<div class="table-responsive">
	<table class="table table-sm table-striped">
		<thead>
			<tr>
				<th>#</th>
				<th>Personagem</th>
				<th>Item</th>
				<th>Qtd</th>
				<th>Tier</th>
				<th>Destino</th>
				<th>Status</th>
				<th>Motivo</th>
				<th>Criado</th>
				<th>Processado</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($deliveries as $d): ?>
			<?php
			$id = (int)$d['id'];
			$status = (string)$d['status'];
			$statusClass = $status === 'done' ? 'success' : ($status === 'failed' ? 'danger' : 'warning');
			$itemName = (string)$d['item_name'];
			?>
			<tr>
				<td><?= $id ?></td>
				<td><?= htmlspecialchars((string)$d['player_name']) ?> <small class="text-muted">#<?= (int)$d['player_id'] ?></small></td>
				<td><?= htmlspecialchars($itemName !== '' ? $itemName : ('id ' . (int)$d['item_id'])) ?> <small class="text-muted">id <?= (int)$d['item_id'] ?></small></td>
				<td><?= (int)$d['count'] ?></td>
				<td><?= (int)$d['tier'] ?></td>
				<td><?= htmlspecialchars($targetLabels[(string)$d['target']] ?? (string)$d['target']) ?></td>
				<td><span class="badge badge-<?= $statusClass ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status) ?></span></td>
				<td><small><?= htmlspecialchars((string)$d['message']) ?></small></td>
				<td><small><?= $d['created_at'] ? date('Y-m-d H:i:s', (int)$d['created_at']) : '-' ?></small></td>
				<td><small><?= $d['processed_at'] ? date('Y-m-d H:i:s', (int)$d['processed_at']) : '-' ?></small></td>
				<td class="text-right text-nowrap">
					<?php if ($status === 'failed'): ?>
						<form method="post" class="d-inline">
							<?php csrf(); ?>
							<input type="hidden" name="action" value="retry">
							<input type="hidden" name="id" value="<?= $id ?>">
							<button type="submit" class="btn btn-warning btn-sm" title="Reenviar"><i class="fas fa-redo"></i></button>
						</form>
					<?php endif; ?>
					<form method="post" class="d-inline" onsubmit="return confirm('Remover o pedido #<?= $id ?>?');">
						<?php csrf(); ?>
						<input type="hidden" name="action" value="delete">
						<input type="hidden" name="id" value="<?= $id ?>">
						<button type="submit" class="btn btn-danger btn-sm" title="Remover"><i class="fas fa-trash"></i></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if (empty($deliveries)): ?>
			<tr><td colspan="11" class="text-center text-muted">Nenhum pedido ainda.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>
</div>
