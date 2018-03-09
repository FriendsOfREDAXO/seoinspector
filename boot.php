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

rex_extension::register('PACKAGES_INCLUDED', function ($params) {

    if (rex::isBackend()) {

        rex_extension::register('STRUCTURE_CONTENT_SIDEBAR', function (rex_extension_point $ep) {
            $params = $ep->getParams();
            $subject = $ep->getSubject();

            $panel = include(rex_path::addon('seoinspector','lib/seoinspect.php'));

            $fragment = new rex_fragment();
            $fragment->setVar('title', '<i class="rex-icon thermo fa-thermometer-1"></i> ' . rex_i18n::msg('si_title'), false);
            $fragment->setVar('body', $panel, false);
            $fragment->setVar('article_id', $params["article_id"], false);
            $fragment->setVar('clang', $params["clang"], false);
            $fragment->setVar('ctype', $params["ctype"], false);
            $fragment->setVar('collapse', true);
            $fragment->setVar('collapsed', false);
            $content = $fragment->parse('core/page/section.php');

            return $subject.$content;

        });

    }


}, rex_extension::EARLY);

