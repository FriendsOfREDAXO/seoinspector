<?php

/**
 * seo inspector
 *
 * @author rokito
 *
 * @package redaxo\seoinspector
 *
 * @var rex_addon $this
 */

rex_sql_table::get(rex::getTable('article'))
	->ensureColumn(new rex_sql_column('seoinspector_focuskeyword', 'varchar(255)'))
    ->alter()
;

$sql = rex_sql::factory();
rex_delete_cache();
