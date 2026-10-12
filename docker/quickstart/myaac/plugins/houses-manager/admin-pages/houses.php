<?php
/**
 * houses-manager - pagina de admin (admin/?p=houses)
 *
 * Une duas fontes por houseid:
 *   - XML do mapa (otservbr-house.xml): rent, nome, tamanho, camas, guildhall,
 *     townid, clientid. E o que o servidor cobra e o que o client mostra.
 *   - banco (`houses`): owner, estado e leilao. E o que o servidor le no boot.
 *
 * Tambem regenera o houses.json (catalogo do client) na raiz do site.
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'houses-manager/lib.php';

$title = 'Houses';
$use_datatable = true;

csrfProtect();

$page = houses_manager_page();
$states = houses_manager_states();
$action = $_POST['action'] ?? '';
$xmlPath = houses_manager_xml_path();
$jsonPath = houses_manager_json_path();
$xmlReadable = is_file($xmlPath);
$xmlWritable = $xmlReadable && is_writable($xmlPath);

if ($action === 'save') {
	$id = (int)($_POST['id'] ?? 0);
	if ($id <= 0) {
		error('Casa invalida.');
	} else {
		// Parte XML (rent/nome/tamanho/camas/townid/clientid/guildhall).
		$xmlChanges = [
			'name' => (string)($_POST['name'] ?? ''),
			'rent' => (string)($_POST['rent'] ?? '0'),
			'size' => (string)($_POST['size'] ?? '0'),
			'beds' => (string)($_POST['beds'] ?? '0'),
			'townid' => (string)($_POST['townid'] ?? '0'),
			'clientid' => (string)($_POST['clientid'] ?? ''),
			'guildhall' => ((string)($_POST['guildhall'] ?? '0') === '1') ? 'true' : 'false',
		];
		$xmlOk = houses_manager_xml_update($id, $xmlChanges);

		// Parte banco (owner/estado/leilao).
		$ownerName = trim((string)($_POST['owner_name'] ?? ''));
		$owner = 0;
		$ownerValid = true;
		if ($ownerName !== '') {
			[$owner, $tmp] = houses_manager_player($ownerName);
			if ($owner <= 0) {
				$ownerValid = false;
				error('Personagem nao encontrado: ' . htmlspecialchars($ownerName));
			}
		}

		$dbOk = false;
		if ($ownerValid) {
			$dbOk = houses_manager_update($id, [
				'owner' => $owner,
				'new_owner' => (int)($_POST['new_owner'] ?? -1),
				'state' => (int)($_POST['state'] ?? 0),
				'bidder_name' => (string)($_POST['bidder_name'] ?? ''),
				'highest_bid' => (int)($_POST['highest_bid'] ?? 0),
				'internal_bid' => (int)($_POST['internal_bid'] ?? 0),
				'bid_end_date' => (int)($_POST['bid_end_date'] ?? 0),
				'transfer_status' => (int)($_POST['transfer_status'] ?? 0),
			]);
		}

		if ($xmlOk || $dbOk) {
			$gen = houses_manager_generate_json();
			success(
				'Casa #' . $id . ' salva.' .
				($gen['ok'] ? (' houses.json regenerado (' . (int)$gen['count'] . ' casas).') : ' Aviso: nao foi possivel gravar o houses.json.')
			);
		} elseif ($ownerValid) {
			error('Nada foi salvo. Verifique se o XML esta montado e gravavel: ' . htmlspecialchars($xmlPath));
		}
	}
} elseif ($action === 'free') {
	$id = (int)($_POST['id'] ?? 0);
	if ($id > 0 && houses_manager_free($id)) {
		success('Casa #' . $id . ' liberada (owner = 0).');
	}
} elseif ($action === 'genjson') {
	$gen = houses_manager_generate_json();
	if ($gen['ok']) {
		success('houses.json gerado: ' . (int)$gen['count'] . ' casas em ' . htmlspecialchars($gen['path']) . '.');
	} else {
		error('Falha ao gravar ' . htmlspecialchars($jsonPath) . ' (permissao?).');
	}
}

$xml = houses_manager_xml_read();
$dbRows = houses_manager_all();
$dbById = [];
foreach ($dbRows as $r) {
	$dbById[(int)$r['id']] = $r;
}

$ids = array_unique(array_merge(array_keys($dbById), array_keys($xml)));
sort($ids);

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0 && (isset($dbById[$editId]) || isset($xml[$editId]))) {
	$edit = ['id' => $editId, 'db' => $dbById[$editId] ?? null, 'xml' => $xml[$editId]['attrs'] ?? null];
}
?>

<h2>Houses</h2>

<?php if (!$xmlReadable): ?>
	<div class="alert alert-warning">
		<strong>XML de casas nao encontrado</strong> em <code><?= htmlspecialchars($xmlPath) ?></code>.
		Verifique o mount no <code>docker-compose.override.yml</code>. Sem ele, rent/nome/size/beds
		nao podem ser exibidos nem editados, e o houses.json nao pode ser gerado.
	</div>
<?php elseif (!$xmlWritable): ?>
	<div class="alert alert-warning">
		<strong>XML de casas nao gravavel</strong>: <code><?= htmlspecialchars($xmlPath) ?></code>.
		Edicoes de rent/nome/size/beds e a geracao do houses.json vao falhar. Verifique o
		<code>chmod</code> no entrypoint do MyAAC.
	</div>
<?php endif; ?>

<div class="alert alert-info">
	<strong>Fontes:</strong>
	<code>rent/name/size/beds/guildhall</code> vem do XML (<code><?= htmlspecialchars($xmlPath) ?></code>);
	<code>owner/state/leilao</code> vem do banco. O <code>houses.json</code> do client e gerado do XML em
	<code><?= htmlspecialchars($jsonPath) ?></code>.
	<br><small class="text-muted">Edite as casas com o servidor parado; ao reiniciar, o servidor
	recarrega o XML e o banco. Salvar aqui ja regenera o houses.json.</small>
</div>

<form method="post" class="mb-3">
	<?php csrf(); ?>
	<input type="hidden" name="action" value="genjson">
	<button type="submit" class="btn btn-primary"><i class="fas fa-sync"></i> Gerar houses.json</button>
	<span class="ml-2 text-muted">Gera o catalogo do client a partir do XML atual.</span>
</form>

<?php if ($edit): ?>
	<?php
	$d = $edit['db'] ?? [];
	$x = $edit['xml'] ?? [];
	?>
	<div class="card">
		<div class="card-header">
			<strong>Editar casa #<?= (int)$edit['id'] ?></strong>
			- <?= htmlspecialchars((string)($x['name'] ?? $d['name'] ?? ('House #' . $edit['id']))) ?>
		</div>
		<div class="card-body">
			<form method="post">
				<?php csrf(); ?>
				<input type="hidden" name="action" value="save">
				<input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

				<h6 class="text-muted">XML (mapa)</h6>
				<div class="form-row">
					<div class="form-group col-md-4">
						<label>Nome</label>
						<input type="text" class="form-control" name="name" value="<?= htmlspecialchars((string)($x['name'] ?? '')) ?>">
					</div>
					<div class="form-group col-md-2">
						<label>Rent</label>
						<input type="number" class="form-control" name="rent" value="<?= (int)($x['rent'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-2">
						<label>Size (sqm)</label>
						<input type="number" class="form-control" name="size" value="<?= (int)($x['size'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-2">
						<label>Beds</label>
						<input type="number" class="form-control" name="beds" value="<?= (int)($x['beds'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-2">
						<label>Guildhall</label>
						<select class="form-control" name="guildhall">
							<option value="0"<?= (($x['guildhall'] ?? '') === 'true' ? '' : ' selected') ?>>Nao</option>
							<option value="1"<?= (($x['guildhall'] ?? '') === 'true' ? ' selected' : '') ?>>Sim</option>
						</select>
					</div>
					<div class="form-group col-md-3">
						<label>Town id</label>
						<input type="number" class="form-control" name="townid" value="<?= (int)($x['townid'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-3">
						<label>Client id</label>
						<input type="number" class="form-control" name="clientid" value="<?= (int)($x['clientid'] ?? 0) ?>">
					</div>
				</div>

				<h6 class="text-muted mt-2">Banco (gameplay)</h6>
				<div class="form-row">
					<div class="form-group col-md-4">
						<label>Owner (nome do personagem; vazio = disponivel)</label>
						<input type="text" class="form-control" name="owner_name" value="<?= htmlspecialchars((string)($d['owner_name'] ?? '')) ?>">
					</div>
					<div class="form-group col-md-4">
						<label>State</label>
						<select class="form-control" name="state">
							<?php foreach ($states as $value => $label): ?>
								<option value="<?= (int)$value ?>"<?= ((int)($d['state'] ?? 0) === (int)$value ? ' selected' : '') ?>><?= htmlspecialchars($label) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-group col-md-4">
						<label>Bidder name</label>
						<input type="text" class="form-control" name="bidder_name" value="<?= htmlspecialchars((string)($d['bidder_name'] ?? '')) ?>">
					</div>
					<div class="form-group col-md-3">
						<label>Highest bid</label>
						<input type="number" class="form-control" name="highest_bid" value="<?= (int)($d['highest_bid'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-3">
						<label>Internal bid</label>
						<input type="number" class="form-control" name="internal_bid" value="<?= (int)($d['internal_bid'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-3">
						<label>Bid end date (timestamp)</label>
						<input type="number" class="form-control" name="bid_end_date" value="<?= (int)($d['bid_end_date'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-3">
						<label>Transfer status</label>
						<input type="number" class="form-control" name="transfer_status" value="<?= (int)($d['transfer_status'] ?? 0) ?>">
					</div>
					<div class="form-group col-md-3">
						<label>New owner (id, -1 = nenhum)</label>
						<input type="number" class="form-control" name="new_owner" value="<?= (int)($d['new_owner'] ?? -1) ?>">
					</div>
				</div>

				<button type="submit" class="btn btn-success">Salvar</button>
				<a href="?p=<?= htmlspecialchars($page) ?>" class="btn btn-secondary">Cancelar</a>
			</form>
		</div>
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-header"><strong>Casas (<?= count($ids) ?>)</strong></div>
	<div class="card-body">
		<div class="table-responsive">
			<table id="tb_houses" class="table table-sm table-striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Nome</th>
						<th>Owner</th>
						<th>Rent</th>
						<th>Size</th>
						<th>Beds</th>
						<th>Town</th>
						<th>State</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($ids as $id): ?>
					<?php
					$id = (int)$id;
					$d = $dbById[$id] ?? [];
					$x = $xml[$id]['attrs'] ?? [];
					$owner = (int)($d['owner'] ?? 0);
					$state = (int)($d['state'] ?? 0);
					$name = (string)($x['name'] ?? $d['name'] ?? ('House #' . $id));
					?>
					<tr>
						<td><?= $id ?></td>
						<td><?= htmlspecialchars($name) ?><?= (($x['guildhall'] ?? '') === 'true' ? ' <span class="badge badge-secondary">GH</span>' : '') ?></td>
						<td>
							<?php if ($owner > 0): ?>
								<?= $owner ?><?= !empty($d['owner_name']) ? ' (' . htmlspecialchars((string)$d['owner_name']) . ')' : '' ?>
							<?php else: ?>
								<span class="text-muted">disponivel</span>
							<?php endif; ?>
						</td>
						<td><?= (int)($x['rent'] ?? 0) ?></td>
						<td><?= (int)($x['size'] ?? 0) ?></td>
						<td><?= (int)($x['beds'] ?? 0) ?></td>
						<td><?= (int)($x['townid'] ?? 0) ?></td>
						<td><?= htmlspecialchars($states[$state] ?? (string)$state) ?></td>
						<td class="text-right text-nowrap">
							<a href="?p=<?= htmlspecialchars($page) ?>&edit=<?= $id ?>" class="btn btn-success btn-sm" title="Editar"><i class="fas fa-pencil-alt"></i></a>
							<form method="post" class="d-inline" onsubmit="return confirm('Liberar a casa #<?= $id ?>?');">
								<?php csrf(); ?>
								<input type="hidden" name="action" value="free">
								<input type="hidden" name="id" value="<?= $id ?>">
								<button type="submit" class="btn btn-danger btn-sm" title="Liberar"><i class="fas fa-home"></i></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if (empty($ids)): ?>
					<tr><td colspan="9" class="text-center text-muted">Nenhuma casa.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<script>
$(function () { $('#tb_houses').DataTable(); });
</script>
