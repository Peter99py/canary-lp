<?php
/**
 * market-seeder - settings
 *
 * Aparece em Admin -> Settings -> Market Seeder.
 * Consumido no codigo via setting('market_seeder.<chave>').
 */

return [
	'key' => 'market_seeder',
	'settings' => [
		[
			'type' => 'section',
			'title' => 'Market Seeder',
		],
		'owner' => [
			'name' => 'Personagem dono das ofertas',
			'type' => 'text',
			'default' => 'ADM1',
			'desc' => 'As ofertas ficam registradas neste personagem, mas marcadas como anonimas no cliente.',
		],
		'only_when_empty' => [
			'name' => 'So popular quando nao houver ofertas de jogadores',
			'type' => 'boolean',
			'default' => true,
			'desc' => 'Se existir qualquer oferta de outro personagem no market, a rotina nao altera nada.',
		],
		'auto_import' => [
			'name' => 'Importar lista padrao quando vazia',
			'type' => 'boolean',
			'default' => true,
			'desc' => 'Na primeira execucao, se a lista estiver vazia, importa plugins/market-seeder/data/default-offers.txt.',
		],
	],
];
