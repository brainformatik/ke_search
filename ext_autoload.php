<?php
$extensionPath = t3lib_extMgm::extPath('ke_search');
return array(
	'tx_kesearch_indexer' => $extensionPath . 'indexer/class.tx_kesearch_indexer.php',
	'tx_kesearch_pi1' => $extensionPath . 'pi1/class.tx_kesearch_pi1.php',
	'tx_kesearch_div' => $extensionPath . 'pi1/class.tx_kesearch_div.php',
	'tx_kesearch_lib' => $extensionPath . 'lib/class.tx_kesearch_lib.php',
	'tx_kesearch_lib_sorting' => $extensionPath . 'lib/class.tx_kesearch_lib_sorting.php',
	'tx_kesearch_lib_filters_textlinks' => $extensionPath . 'lib/filters/class.tx_kesearch_lib_filters_textlinks.php',
	'tx_kesearch_db' => $extensionPath . 'lib/class.tx_kesearch_db.php',
	'tx_kesearch_indexertask' => $extensionPath . 'tasks/class.tx_kesearch_indexertask.php',
);
?>