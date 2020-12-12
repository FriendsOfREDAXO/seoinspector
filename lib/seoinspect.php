<?php



class rex_article_content_base_local extends rex_article_content_base
{
    public function getArticle($curctype = -1)
    {
        $this->ctype = $curctype;

        if ($this->article_id == 0 && $this->getSlice == 0) {
            return rex_i18n::msg('no_article_available');
        }

        $articleLimit = '';
        if ($this->article_id != 0) {
            $articleLimit = ' AND ' . rex::getTablePrefix() . 'article_slice.article_id=' . (int) $this->article_id;
        }

        $sliceLimit = '';
        if ($this->getSlice != 0) {
            $sliceLimit = ' AND ' . rex::getTablePrefix() . "article_slice.id = '" . ((int) $this->getSlice) . "' ";
        }

        // ----- start: article caching
        ob_start();
        ob_implicit_flush(0);
        $module_id = rex_request('module_id', 'int');

        // ---------- alle teile/slices eines artikels auswaehlen
        $query = 'SELECT ' . rex::getTablePrefix() . 'module.id, ' . rex::getTablePrefix() . 'module.key, ' . rex::getTablePrefix() . 'module.name, ' . rex::getTablePrefix() . 'module.output, ' . rex::getTablePrefix() . 'module.input, ' . rex::getTablePrefix() . 'article_slice.*, ' . rex::getTablePrefix() . 'article.parent_id
                        FROM
                            ' . rex::getTablePrefix() . 'article_slice
                        LEFT JOIN ' . rex::getTablePrefix() . 'module ON ' . rex::getTablePrefix() . 'article_slice.module_id=' . rex::getTablePrefix() . 'module.id
                        LEFT JOIN ' . rex::getTablePrefix() . 'article ON ' . rex::getTablePrefix() . 'article_slice.article_id=' . rex::getTablePrefix() . 'article.id
                        WHERE
                            ' . rex::getTablePrefix() . "article_slice.clang_id='" . $this->clang . "' AND
                            " . rex::getTablePrefix() . "article.clang_id='" . $this->clang . "' AND
                            " . rex::getTablePrefix() . "article_slice.revision='" . $this->slice_revision . "'
                            " . $articleLimit . '
                            ' . $sliceLimit . ' AND
                            ' . rex::getTablePrefix() . 'article_slice.status = 1
                            ORDER BY ' . rex::getTablePrefix() . 'article_slice.priority';

        $query = rex_extension::registerPoint(new rex_extension_point(
            'ART_SLICES_QUERY',
            $query,
            ['article' => $this]
        ));

        $artDataSql = rex_sql::factory();
        $artDataSql->setDebug($this->debug);
        $artDataSql->setQuery($query);

        // pre hook
        $articleContent = '';
        $articleContent = $this->preArticle($articleContent, $module_id);

        // ---------- SLICES AUSGEBEN

        $prevCtype = null;
        $artDataSql->reset();
        $rows = $artDataSql->getRows();
        for ($i = 0; $i < $rows; ++$i) {
            $sliceId = $artDataSql->getValue(rex::getTablePrefix() . 'article_slice.id');
            $sliceCtypeId = $artDataSql->getValue(rex::getTablePrefix() . 'article_slice.ctype_id');
            $sliceModuleId = $artDataSql->getValue(rex::getTablePrefix() . 'module.id');

            // ----- ctype unterscheidung
            if ($this->mode != 'edit' && !$this->eval) {
                if (0 == $i) {
                    $articleContent = "<?php if (\$this->ctype == '" . $sliceCtypeId . "' || (\$this->ctype == '-1')) { \n";
                } elseif (isset($prevCtype) && $sliceCtypeId != $prevCtype) {
                    // ----- zwischenstand: ctype .. wenn ctype neu dann if
                    $articleContent .= "\n } if(\$this->ctype == '" . $sliceCtypeId . "' || \$this->ctype == '-1'){ \n";
                }
            }
            // rex::setProperty('redaxo', false);

            // ------------- EINZELNER SLICE - AUSGABE
            $slice_content = $this->outputSlice(
                $artDataSql,
                $module_id
            );
            // --------------- ENDE EINZELNER SLICE

            // --------------- EP: SLICE_SHOW
            $slice_content = rex_extension::registerPoint(new rex_extension_point(
                'SLICE_SHOW',
                $slice_content,
                [
                    'article_id' => $this->article_id,
                    'clang' => $this->clang,
                    'ctype' => $sliceCtypeId,
                    'module_id' => $sliceModuleId,
                    'slice_id' => $sliceId,
                    'function' => $this->function,
                    'function_slice_id' => $this->slice_id,
                    'sql' => $artDataSql,
                ]
            ));

            // ---------- slice in ausgabe speichern wenn ctype richtig
            if ($this->ctype == -1 || $this->ctype == $sliceCtypeId) {
                $articleContent .= $slice_content;
            }

            $prevCtype = $sliceCtypeId;

            $artDataSql->flushValues();
            $artDataSql->next();
        }

        // ----- end: ctype unterscheidung
        if ($this->mode != 'edit' && !$this->eval && $i > 0) {
            $articleContent .= "\n } ?>";
        }

        // ----- post hook
        $articleContent = $this->postArticle($articleContent, $module_id);

        // -------------------------- schreibe content
        echo $articleContent;

        // ----- end: article caching
        $CONTENT = ob_get_clean();

        return $CONTENT;
    }
}

class rex_article_content_local extends rex_article_content_base_local
{
    public function getArticle($curctype = -1)
    {
        $this->ctype = $curctype;

        if (!$this->getSlice && $this->article_id != 0) {
            // ----- start: article caching
            ob_start();
            ob_implicit_flush(0);

            $article_content_file = rex_path::addonCache('structure', $this->article_id . '.' . $this->clang . '.content');

            $generated = true;
            if (!file_exists($article_content_file)) {
                $generated = rex_content_service_local::generateArticleContent($this->article_id, $this->clang);
                if ($generated !== true) {
                    // fehlermeldung ausgeben
                    echo $generated;
                }
            }

            if ($generated === true) {
                require $article_content_file;
            }

            // ----- end: article caching
            $CONTENT = ob_get_clean();
        } else {
            // Inhalt ueber sql generierens
            $CONTENT = parent::getArticle($curctype);
        }

        $CONTENT = rex_extension::registerPoint(new rex_extension_point('ART_CONTENT', $CONTENT, [
            'ctype' => $curctype,
            'article' => $this,
        ]));

        return $CONTENT;
    }
}

class rex_content_service_local extends rex_content_service
{
    public static function generateArticleContent($article_id, $clang = null)
    {
        foreach (rex_clang::getAllIds() as $_clang) {
            if ($clang !== null && $clang != $_clang) {
                continue;
            }

            $CONT = new rex_article_content_base_local();
            $CONT->setCLang($_clang);
            $CONT->setEval(false); // Content nicht ausführen, damit in Cachedatei gespeichert werden kann
            if (!$CONT->setArticleId($article_id)) {
                return false;
            }

            // --------------------------------------------------- Artikelcontent speichern
            $article_content_file = rex_path::addonCache('structure', "$article_id.$_clang.content");
            $article_content = $CONT->getArticle();

            // ----- EXTENSION POINT
            $article_content = rex_extension::registerPoint(new rex_extension_point('GENERATE_FILTER', $article_content, [
                'id' => $article_id,
                'clang' => $_clang,
                'article' => $CONT,
            ]));

            if (rex_file::put($article_content_file, $article_content) === false) {
                return rex_i18n::msg('article_could_not_be_generated') . ' ' . rex_i18n::msg('check_rights_in_directory') . rex_path::addonCache('structure');
            }
        }

        return true;
    }
}









/*setup*/
$content = '';
$addon = rex_addon::get('yrewrite');
$seo = new rex_yrewrite_seo;

$article_id = $params['article_id'];
$clang = $params['clang'];
$ctype = $params['ctype'];



/*form*/
$yform = new rex_yform();

$yform->setObjectparams('form_action', rex_url::backendController(['page' => 'content/edit', 'article_id' => $article_id, 'clang' => $clang, 'ctype' => $ctype], false));
$yform->setObjectparams('main_table', rex::getTable('article'));
$yform->setObjectparams('main_id', $article_id);
$yform->setObjectparams('main_where', 'id='.$article_id.' and clang_id='.$clang);
$yform->setObjectparams('getdata', true);

$yform->setObjectparams('form_id', 'seoinspector');
$yform->setObjectparams('form_name', 'seoinspector');
$yform->setObjectparams('form_showformafterupdate', 1);

$yform->setValueField('text', ['seoinspector_focuskeyword', rex_i18n::msg('si_focuskeyword')]);

$yform->setActionField('db', [rex::getTable('article'), 'id=' . $article_id.' and clang_id='.$clang]);
$yform->setObjectparams('submit_btn_label', $addon->i18n('si_update'));

$form = $yform->getForm();



/*refresh*/
if ($yform->objparams['actions_executed']) {
    $form = rex_view::success(rex_i18n::msg('si_updated')) . $form;
    rex_article_cache::delete($article_id, $clang);
}



/*output area*/
$results = '<br><label>'.rex_i18n::msg('si_inspection').'</label><div class="si-results"></div>';
$preview = '<br><label>'.rex_i18n::msg('si_preview').'</label><div class="si-preview"></div>';

$articleContentInstance = new rex_article_content_local($article_id, $clang);
$articleContent = $articleContentInstance->getArticle();

/*script*/
$script = "
<script>

/*config*/
var optimumtitlelength = { min:40, max:70 },
	optimumdescriptionlength = { min:140, max:160 },
	optimumcontent = { wordcount:300 },
	results = {}

/*setup*/
var resultarea = jQuery('.si-results'),
	previewarea = jQuery('.si-preview'),
	truesign = '<i class=\"fa fa-dot-circle-o\" aria-hidden=\"true\"></i>',
	falsesign = '<i class=\"fa fa-circle-o\" aria-hidden=\"true\"></i>',
	ranking = { total:null, valid:null },
	focuskeyword = jQuery('#seoinspector > div > input').val(),
	focuskeywordlist = ".json_encode(rex_sql::factory()->setQuery("SELECT seoinspector_focuskeyword FROM rex_article")->getArray()).",
    articlecontent = '".json_encode(preg_replace('/[\'\"]/', '',$articleContent))."',
    articleplaincontent = '".json_encode(trim(preg_replace('/[\'\"]/', '',preg_replace('/\s\s+/', ' ', strip_tags($articleContent)))))."',
	articleurl = '".json_encode(rex_article::get($article_id)->getUrl())."',
	articlefullurl = document.location.origin+articleurl.replace(/\"/g,''),
	articletitle = '".json_encode($seo->getTitleTag())."',
	articlerealtitle = articletitle.match(/<title[^>]*>([^<]+)<\/title>/)[1],
	articledescription = '".json_encode($seo->getDescriptionTag())."',
	articlerealdescription = articledescription.match(/content=\"(.*?)\">/)[1],
	templatehtmlcontent = '".json_encode(htmlentities(str_replace('\'','"',@file_get_contents(rex_article::getCurrent()->getUrl()))))."'


/*inspect*/
articlehasdescription();
lengthofmetadescriptionis();
lengthofpagetitleis();
wordcountis();
uniquefirstgradeheadline();
if(focuskeyword){
	focuskeywordisintitle();
	focuskeywordisinurl();
	focuskeywordisindescription();
	focuskeywordisincontent();
	focuskeywordisunique();
}
generatepreview();
updateranking();

////focuskeywordisinalttags();
////metadescriptionisdifferentthanhomepage();
////stopwordscontained(); //which ones?

////////focuskeywordinfirstheadline();
////////focuskeywordinsubheadlines();
////////focuskeywordonbeginningoftitle();
////////contentfleshreadingeasetest();
////////outboundlinksonsite();
////////focuskeywordinfirstparagraph();



/*appending*/
function appendresult(value,description,rank){
    if (value == true){ var sign = truesign; }else{ var sign = falsesign;}
    resultarea.append('<div class=\"row \"><div class=\"col-lg-1\">'+sign+'</div><div class=\"col-lg-10\"><small>'+description+'</small></div></div>');
    if(rank != false){
		ranking.total++;
        if(value){ ranking.valid++; }
    }
}

/*thermometer rank*/
function updateranking(){
    var target = $('.thermo');
    var temperature = Math.round(100/ranking.total*ranking.valid/100*4);
    target.removeClass('fa-thermometer-0 fa-thermometer-1 fa-thermometer-2 fa-thermometer-3 fa-thermometer-4');
    target.addClass('fa-thermometer-'+temperature);
}

/*focus keyword is unique throug all articles*/
function focuskeywordisunique(){
    if ($.grep(focuskeywordlist,function(elem){
            return elem.seoinspector_focuskeyword === focuskeyword;
        }).length != 1){
        appendresult(false,'Fokus Keyword mehrfach benutzt');
    }else{
        appendresult(true,'Fokus Keyword ist einmalig');
    }
}

/*only one h1 in content*/
function uniquefirstgradeheadline(){
    if ((templatehtmlcontent.match(/&lt;h1/g) || []).length == 1){
        appendresult(true,'Einmalige H1 enthalten');
    }else{
        if ((templatehtmlcontent.match(/&lt;h1/g) || []).length == 0){
            appendresult(false,'Keine H1 enthalten');
        }else{
            appendresult(false,'Mehrere H1 enthalten');
        }
    }
}

/*is focus keyword in title*/
function focuskeywordisintitle(){
    if (~articlerealtitle.toLowerCase().indexOf(focuskeyword.toLowerCase())){
        appendresult(true,'Fokus Keyword im Seitentitel enthalten');
    }else{
        appendresult(false,'Fokus Keyword fehlt im Seitentitel');
    }
}

/*is focus keyword in title*/
function articlehasdescription(){
    if (!articlerealdescription){
        appendresult(false,'Meta Description fehlt');
    }else{
        appendresult(true,'Meta Description ist gesetzt');
    }
}

/*is focuskeyword in url*/
function focuskeywordisinurl(){
    if (~articlefullurl.toLowerCase().indexOf(focuskeyword.toLowerCase())){
        appendresult(true,'Fokus Keyword in der URL enthalten');
    }else{
        appendresult(false,'Fokus Keyword fehlt in der URL');
    }
}

/*is focuskeyword in meta description*/
function focuskeywordisindescription(){
	if (~articlerealdescription.toLowerCase().indexOf(focuskeyword.toLowerCase())){
        appendresult(true,'Fokus Keyword in Meta Description enthalten');
    }else{
        appendresult(false,'Fokus Keyword fehlt in Meta Description');
    }
}

/*length of meta description is*/
function lengthofmetadescriptionis(){
	switch (true) {
		case (articlerealdescription.length < optimumdescriptionlength.min):
			appendresult(false,'Meta Description ist zu kurz');
			break;
		case (articlerealdescription.length >= optimumdescriptionlength.min
		&& articlerealdescription.length <= optimumdescriptionlength.max):
			appendresult(true,'Meta Description hat optimale Länge');
			break;
		case (articlerealdescription.length > optimumdescriptionlength.max):
			appendresult(false,'Meta Description ist zu lang');
			break;
	}
}

/*length of page title is*/
function lengthofpagetitleis(){
	switch (true) {
		case (articlerealtitle.length < optimumtitlelength.min):
			appendresult(false,'Seitentitel ist zu kurz');
			break;
		case (articlerealtitle.length >= optimumtitlelength.min
		&& articlerealtitle.length <= optimumtitlelength.max):
			appendresult(true,'Seitentitel hat optimale Länge');
			break;
		case (articlerealtitle.length > optimumtitlelength.max):
			appendresult(false,'Seitentitel ist zu lang');
			break;
	}
}

/*focus keyword is in content*/
function focuskeywordisincontent(){
	if (~articleplaincontent.toLowerCase().indexOf(focuskeyword.toLowerCase())){
        appendresult(true,'Fokus Keyword im Content enthalten');
        focuskeywordfoundntimes();
        focuskeyworddensityisn();
    }else{
        appendresult(false,'Fokus Keyword fehlt im Content');
    }
}

/*focus keyword found n times*/
function focuskeywordfoundntimes(){
	var count = articleplaincontent.split(new RegExp(focuskeyword, \"gi\")).length-1;
	results.keywords = count;
	appendresult(true,'Fokus Keyword '+count+'x gefunden',false);
}

/*word count is*/
function wordcountis(){
	var words = articleplaincontent.split(' ');
	results.words = words.length;
    appendresult(true,'Content enthält '+words.length+' Wörter',false);
    wordcountminimumreached(words.length);
}

/*word count minimum reached*/
function wordcountminimumreached(found){
	if (found > optimumcontent.wordcount){
        appendresult(true,'Mindestlänge des Contents erreicht');
    }else{
        appendresult(false,'Länge des Contents ist zu kurz');
    }
}

/*focus keyword density is n*/
function focuskeyworddensityisn(){
	var density = Math.round(100/results.words*results.keywords);
	appendresult(true,'Keyword Dichte liegt bei '+density+'%',false);
}

/*seo preview*/
function generatepreview(){
    var truncateddescription;
    if(articlerealdescription.length > optimumdescriptionlength.max){
        truncateddescription = articlerealdescription.substring(0,optimumdescriptionlength.max)+' …';
    }else{
        truncateddescription = articlerealdescription;
    }
    var title = '<span style=\"display:block;color:darkblue;\">'+articlerealtitle+'</span>';
    var url = '<span style=\"display:block;color:darkgreen;font-size:smaller;\">'+articlefullurl+'</span>';
    var description = '<span style=\"display:block;color:grey;font-size:smaller;\">'+truncateddescription+'</span>';
    previewarea.html(title+url+description);
}

</script>
";



/*print*/
$content = '<section id="rex-page-sidebar-seoinspector" data-pjax-container="#rex-page-sidebar-seoinspector" data-pjax-no-history="1">'.$form.$results.$preview.$script.'</section>';
return $content;




