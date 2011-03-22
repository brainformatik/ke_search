<?php
class tx_kesearch_classes_flexform {
	function listAvailableOrderingsForFrontend(&$config) {
		// get orderings
		$notAllowedFields = 'uid,pid,tstamp,crdate,cruser_id,starttime,endtime,fe_group,targetpid,content,params,type,tags,abstract,title,language';
		$config['items'][] = array('Relevance', 'score');			
		$res = $GLOBALS['TYPO3_DB']->sql_query('SHOW COLUMNS FROM tx_kesearch_index');
		while($col = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if(!t3lib_div::inList($notAllowedFields, $col['Field'])) {
				$config['items'][] = array($col['Field'], $col['Field']);			
			}
		}
	}

	function listAvailableOrderingsForAdmin(&$config) {
		// get orderings
		$notAllowedFields = 'uid,pid,tstamp,crdate,cruser_id,starttime,endtime,fe_group,targetpid,content,params,type,tags,abstract,title,language';
		$config['items'][] = array('Relevance UP', 'score ASC');			
		$config['items'][] = array('Relevance DOWN', 'score DESC');			
		$res = $GLOBALS['TYPO3_DB']->sql_query('SHOW COLUMNS FROM tx_kesearch_index');
		while($col = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if(!t3lib_div::inList($notAllowedFields, $col['Field'])) {
				$config['items'][] = array($col['Field'] . ' UP', $col['Field'] . ' ASC');			
				$config['items'][] = array($col['Field'] . ' DOWN', $col['Field'] . ' DESC');			
			}
		}
	}
}
?>