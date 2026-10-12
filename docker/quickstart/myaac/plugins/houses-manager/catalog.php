<?php
/**
 * houses-manager - catalogo publico do client
 *
 * Rota GET /house-catalog (ver "routes" em houses-manager.json). Devolve o JSON
 * que o client usa para exibir nome/rent/sqm/beds/GH/shop das casas. Passa pelo
 * PHP do MyAAC, entao nao e barrado pela regra que nega arquivos *.json
 * (nginx: location ~\.(ht|md|json|dist|sql)$ ; Apache: FilesMatch).
 *
 * E gerado a partir do XML do mapa a cada requisicao, entao fica sempre em
 * sincronia com o admin sem precisar gravar arquivo.
 */

defined('MYAAC') or die('Direct access not allowed!');

require_once PLUGINS . 'houses-manager/lib.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(houses_manager_catalog(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;
