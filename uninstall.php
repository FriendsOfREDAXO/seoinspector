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
	->removeColumn('seoinspector_focuskeyword')
    ->alter()
;

$sql = rex_sql::factory();
rex_delete_cache();
