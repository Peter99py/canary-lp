<?php
/**
 * level-stats-fixer - settings
 *
 * Campos SOMENTE LEITURA (attrs.readonly): aparecem em Admin -> Settings apenas
 * para consulta dos valores usados pelo hook. Nao ha necessidade de edita-los.
 *
 * A base (nivel 1) foi derivada das amostras em canary/schema.sql e os ganhos
 * sao os mesmos de canary/data/XML/vocations.xml.
 */

return [
	'key' => 'level_stats_fixer',
	'settings' => [
		[
			'type' => 'section',
			'title' => 'Base (nivel 1)',
		],
		'base_hp' => [
			'name' => 'Vida base',
			'type' => 'number',
			'default' => 150,
			'attrs' => ['readonly' => true],
			'desc' => 'Vida maxima no nivel 1. Derivado das amostras (canary/schema.sql).',
		],
		'base_mana' => [
			'name' => 'Mana base',
			'type' => 'number',
			'default' => 55,
			'attrs' => ['readonly' => true],
			'desc' => 'Mana maxima no nivel 1. Derivado das amostras (canary/schema.sql).',
		],
		'base_cap' => [
			'name' => 'Cap base',
			'type' => 'number',
			'default' => 400,
			'attrs' => ['readonly' => true],
			'desc' => 'Capacidade no nivel 1. Derivado das amostras (canary/schema.sql).',
		],
		[
			'type' => 'section',
			'title' => 'Ganhos por vocacao [hp, mana, cap]',
		],
		'gains' => [
			'name' => 'Ganhos por vocacao',
			'type' => 'textarea',
			'default' => '{"0":[5,5,10],"1":[5,30,10],"2":[5,30,10],"3":[10,15,20],"4":[15,5,25],"5":[5,30,10],"6":[5,30,10],"7":[10,15,20],"8":[15,5,25],"9":[10,10,25],"10":[10,10,25]}',
			'attrs' => ['readonly' => true],
			'desc' => 'Mesmos valores de canary/data/XML/vocations.xml (gainhp, gainmana, gaincap).',
		],
		[
			'type' => 'section',
			'title' => 'Comportamento',
		],
		'fill' => [
			'name' => 'Encher vida/mana ao salvar',
			'type' => 'boolean',
			'default' => true,
			'desc' => 'Ao recalcular, define vida/mana atuais iguais as maximas.',
		],
	],
];
