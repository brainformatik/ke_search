<?php

########################################################################
# Extension Manager/Repository config file for ext "ke_search".
#
# Auto generated 20-09-2011 12:40
#
# Manual updates:
# Only the data in the array - everything else is removed by next
# writing. "version" and "dependencies" must not be touched!
########################################################################

$EM_CONF[$_EXTKEY] = array(
	'title' => 'Faceted Search',
	'description' => 'Faceted Search for TYPO3. Very easy to install. AJAX Frontend. Fast (tested with 50.000 records) and flexible (you can write your own indexers). Indexes content directly from the databases (no frontend crawling). Visit kesearch.de for further information.',
	'category' => 'plugin',
	'author' => 'A. Kiefer, C. Buelter, S. Froemken (kennziffer.com)',
	'author_email' => 'kiefer@kennziffer.com',
	'shy' => '',
	'dependencies' => 'xajax',
	'conflicts' => '',
	'priority' => '',
	'module' => '',
	'state' => 'beta',
	'internal' => '',
	'uploadfolder' => 0,
	'createDirs' => '',
	'modify_tables' => '',
	'clearCacheOnLoad' => 0,
	'lockType' => '',
	'author_company' => '',
	'version' => '0.4.0',
	'constraints' => array(
		'depends' => array(
			'xajax' => '0.2.5-0.2.99',
		),
		'conflicts' => array(
		),
		'suggests' => array(
		),
	),
	'suggests' => array(
	),
	'_md5_values_when_last_written' => 'a:91:{s:9:"ChangeLog";s:4:"bad0";s:10:"README.txt";s:4:"f5bf";s:16:"ext_autoload.php";s:4:"0444";s:21:"ext_conf_template.txt";s:4:"d63e";s:12:"ext_icon.gif";s:4:"1e65";s:17:"ext_localconf.php";s:4:"946d";s:14:"ext_tables.php";s:4:"2e05";s:14:"ext_tables.sql";s:4:"25f7";s:34:"icon_tx_kesearch_filteroptions.gif";s:4:"a25c";s:28:"icon_tx_kesearch_filters.gif";s:4:"6364";s:26:"icon_tx_kesearch_index.gif";s:4:"b932";s:34:"icon_tx_kesearch_indexerconfig.gif";s:4:"1938";s:25:"icon_tx_kesearch_tags.gif";s:4:"3fc0";s:17:"locallang_csh.xml";s:4:"0cf6";s:16:"locallang_db.xml";s:4:"e66d";s:16:"pageTSconfig.txt";s:4:"1fdb";s:44:"selicon_tx_kesearch_indexerconfig_type_0.gif";s:4:"392f";s:44:"selicon_tx_kesearch_indexerconfig_type_1.gif";s:4:"8615";s:44:"selicon_tx_kesearch_indexerconfig_type_2.gif";s:4:"3bcb";s:44:"selicon_tx_kesearch_indexerconfig_type_3.gif";s:4:"4dc0";s:44:"selicon_tx_kesearch_indexerconfig_type_4.gif";s:4:"bedc";s:44:"selicon_tx_kesearch_indexerconfig_type_5.gif";s:4:"2cb6";s:44:"selicon_tx_kesearch_indexerconfig_type_6.gif";s:4:"b727";s:7:"tca.php";s:4:"9b86";s:46:"classes/class.tx_kesearch_classes_flexform.php";s:4:"7f01";s:33:"classes/class.user_filterlist.php";s:4:"a18b";s:26:"cli/class.cli_kesearch.php";s:4:"e5fd";s:24:"cli/ke_search_indexer.sh";s:4:"5429";s:14:"doc/manual.sxw";s:4:"f052";s:19:"doc/wizard_form.dat";s:4:"babc";s:20:"doc/wizard_form.html";s:4:"ed3f";s:34:"hooks/class.user_kesearchhooks.php";s:4:"299c";s:37:"indexer/class.tx_kesearch_indexer.php";s:4:"723e";s:43:"indexer/class.tx_kesearch_indexer_types.php";s:4:"3d97";s:53:"indexer/types/class.tx_kesearch_indexer_types_dam.php";s:4:"78c2";s:56:"indexer/types/class.tx_kesearch_indexer_types_ke_yac.php";s:4:"77aa";s:54:"indexer/types/class.tx_kesearch_indexer_types_page.php";s:4:"2db4";s:60:"indexer/types/class.tx_kesearch_indexer_types_tt_address.php";s:4:"4b54";s:60:"indexer/types/class.tx_kesearch_indexer_types_tt_content.php";s:4:"7966";s:56:"indexer/types/class.tx_kesearch_indexer_types_ttnews.php";s:4:"e289";s:63:"indexer/types/class.tx_kesearch_indexer_types_xtypocommerce.php";s:4:"9722";s:28:"lib/class.tx_kesearch_db.php";s:4:"73de";s:29:"lib/class.tx_kesearch_lib.php";s:4:"113c";s:35:"lib/class.tx_kesearch_lib_items.php";s:4:"3305";s:13:"mod1/conf.php";s:4:"10db";s:14:"mod1/index.php";s:4:"0978";s:18:"mod1/locallang.xml";s:4:"6da8";s:22:"mod1/locallang_mod.xml";s:4:"42cc";s:19:"mod1/moduleicon.gif";s:4:"1e65";s:14:"pi1/ce_wiz.gif";s:4:"309c";s:29:"pi1/class.tx_kesearch_div.php";s:4:"72a2";s:29:"pi1/class.tx_kesearch_pi1.php";s:4:"62e8";s:37:"pi1/class.tx_kesearch_pi1_wizicon.php";s:4:"aa43";s:20:"pi1/flexform_pi1.xml";s:4:"2a81";s:17:"pi1/locallang.xml";s:4:"0546";s:14:"pi2/ce_wiz.gif";s:4:"309c";s:29:"pi2/class.tx_kesearch_pi2.php";s:4:"2907";s:37:"pi2/class.tx_kesearch_pi2_wizicon.php";s:4:"6b1c";s:20:"pi2/flexform_pi2.xml";s:4:"a3db";s:17:"pi2/locallang.xml";s:4:"dec7";s:14:"pi3/ce_wiz.gif";s:4:"309c";s:29:"pi3/class.tx_kesearch_pi3.php";s:4:"01b2";s:37:"pi3/class.tx_kesearch_pi3_wizicon.php";s:4:"d332";s:20:"pi3/flexform_pi3.xml";s:4:"a3db";s:17:"pi3/locallang.xml";s:4:"9976";s:21:"res/ke_search_pi1.css";s:4:"2f23";s:20:"res/template_pi1.tpl";s:4:"10bd";s:23:"res/img/ajax-loader.gif";s:4:"0d08";s:22:"res/img/arrow-next.gif";s:4:"ab16";s:29:"res/img/arrow-next_orange.gif";s:4:"008e";s:22:"res/img/arrow-prev.gif";s:4:"03d9";s:29:"res/img/arrow-prev_orange.gif";s:4:"517b";s:21:"res/img/attention.gif";s:4:"a8ba";s:17:"res/img/blank.gif";s:4:"3254";s:27:"res/img/default-indexer.gif";s:4:"0b8d";s:14:"res/img/go.gif";s:4:"bc1d";s:27:"res/img/list-bullet-cat.gif";s:4:"78ba";s:27:"res/img/list-head-bgrnd.gif";s:4:"54b5";s:28:"res/img/list-head-closed.gif";s:4:"281e";s:30:"res/img/list-head-expanded.gif";s:4:"8f38";s:17:"res/img/reset.gif";s:4:"b7d8";s:27:"res/img/searchbox-bgrnd.gif";s:4:"66cf";s:19:"res/img/spinner.gif";s:4:"2029";s:21:"res/img/types/dam.gif";s:4:"4c43";s:24:"res/img/types/ke_yac.gif";s:4:"d502";s:22:"res/img/types/page.gif";s:4:"a659";s:28:"res/img/types/tt_address.gif";s:4:"ff1f";s:25:"res/img/types/tt_news.gif";s:4:"9e5f";s:25:"res/scripts/RemoveXSS.php";s:4:"17c6";s:19:"res/scripts/SVG.php";s:4:"3b1f";s:39:"tasks/class.tx_kesearch_indexertask.php";s:4:"47b3";}',
);

?>
