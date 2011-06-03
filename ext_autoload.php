<?php
$extensionPath = t3lib_extMgm::extPath('ke_search');
return array(
	'tx_kesearch_pi1' => $extensionPath . 'pi1/class.tx_kesearch_pi1.php',
	'tx_kesearch_div' => $extensionPath . 'pi1/class.tx_kesearch_div.php',
	'tx_kesearch_indexertask' => $extensionPath . 'tasks/class.tx_kesearch_indexertask.php',
);
?>