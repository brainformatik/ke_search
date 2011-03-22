<?php
class tx_kesearch_classes_flexform {
	/**
	 * @var language
	 */
	var $lang;
	
	function listAvailableOrderingsForFrontend(&$config) {
		$this->lang = t3lib_div::makeInstance('language');
		$this->lang->init($GLOBALS['BE_USER']->uc['lang']);
		
		// get orderings
		$fieldLabel = $this->lang->sL('LLL:EXT:ke_search/locallang_db.php:tx_kesearch_index.relevance');
		$notAllowedFields = 'uid,pid,tstamp,crdate,cruser_id,starttime,endtime,fe_group,targetpid,content,params,type,tags,abstract,language';
		$config['items'][] = array($fieldLabel, 'score');			
		$res = $GLOBALS['TYPO3_DB']->sql_query('SHOW COLUMNS FROM tx_kesearch_index');
		while($col = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if(!t3lib_div::inList($notAllowedFields, $col['Field'])) {
				$fieldLabel = $this->lang->sL('LLL:EXT:ke_search/locallang_db.php:tx_kesearch_index.' . $col['Field']);
				$config['items'][] = array($fieldLabel, $col['Field']);			
			}
		}
	}

	function listAvailableOrderingsForAdmin(&$config) {
		$this->lang = t3lib_div::makeInstance('language');
		$this->lang->init($GLOBALS['BE_USER']->uc['lang']);
		
		// get orderings
		$fieldLabel = $this->lang->sL('LLL:EXT:ke_search/locallang_db.php:tx_kesearch_index.relevance');
		$notAllowedFields = 'uid,pid,tstamp,crdate,cruser_id,starttime,endtime,fe_group,targetpid,content,params,type,tags,abstract,language';
		$config['items'][] = array($fieldLabel . ' UP', 'score ASC');			
		$config['items'][] = array($fieldLabel . ' DOWN', 'score DESC');			
		$res = $GLOBALS['TYPO3_DB']->sql_query('SHOW COLUMNS FROM tx_kesearch_index');
		while($col = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if(!t3lib_div::inList($notAllowedFields, $col['Field'])) {
				$fieldLabel = $this->lang->sL('LLL:EXT:ke_search/locallang_db.php:tx_kesearch_index.' . $col['Field']);
				$config['items'][] = array($fieldLabel . ' UP', $col['Field'] . ' ASC');			
				$config['items'][] = array($fieldLabel . ' DOWN', $col['Field'] . ' DESC');			
			}
		}
	}
}
?>