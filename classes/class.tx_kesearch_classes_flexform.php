<?php
class tx_kesearch_classes_flexform {
	/**
	 * @var language
	 */
	var $lang;

	function listAvailableOrderingsForFrontend(&$config) {
		$this->lang = t3lib_div::makeInstance('language');
		$this->lang->init($GLOBALS['BE_USER']->uc['lang']);
		t3lib_div::loadTCA('tx_kesearch_index');

		// get orderings
		$fieldLabel = $this->lang->sL('LLL:EXT:ke_search/locallang_db.php:tx_kesearch_index.relevance');
		$notAllowedFields = 'uid,pid,tstamp,crdate,cruser_id,starttime,endtime,fe_group,targetpid,content,params,type,tags,abstract,language,orig_uid,orig_pid,hash';
		$config['items'][] = array($fieldLabel, 'score');
		$res = $GLOBALS['TYPO3_DB']->sql_query('SHOW COLUMNS FROM tx_kesearch_index');
		while($col = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if(!t3lib_div::inList($notAllowedFields, $col['Field'])) {
				$file = $GLOBALS['TCA']['tx_kesearch_index']['columns'][$col['Field']]['label'];
				$fieldLabel = $this->lang->sL($file);
				$config['items'][] = array($fieldLabel, $col['Field']);
			}
		}
	}

	function listAvailableOrderingsForAdmin(&$config) {
		$this->lang = t3lib_div::makeInstance('language');
		$this->lang->init($GLOBALS['BE_USER']->uc['lang']);
		t3lib_div::loadTCA('tx_kesearch_index');

		// get orderings
		$fieldLabel = $this->lang->sL('LLL:EXT:ke_search/locallang_db.php:tx_kesearch_index.relevance');
		$notAllowedFields = 'uid,pid,tstamp,crdate,cruser_id,starttime,endtime,fe_group,targetpid,content,params,type,tags,abstract,language,orig_uid,orig_pid,hash';
		if(!$config['config']['relevanceNotAllowed']) {
			$config['items'][] = array($fieldLabel . ' UP', 'score asc');
			$config['items'][] = array($fieldLabel . ' DOWN', 'score desc');
		}
		$res = $GLOBALS['TYPO3_DB']->sql_query('SHOW COLUMNS FROM tx_kesearch_index');
		while($col = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if(!t3lib_div::inList($notAllowedFields, $col['Field'])) {
				$file = $GLOBALS['TCA']['tx_kesearch_index']['columns'][$col['Field']]['label'];
				$fieldLabel = $this->lang->sL($file);
				$config['items'][] = array($fieldLabel . ' UP', $col['Field'] . ' asc');
				$config['items'][] = array($fieldLabel . ' DOWN', $col['Field'] . ' desc');
			}
		}
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/classes/class.tx_kesearch_classes_flexform.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/classes/class.tx_kesearch_classes_flexform.php']);
}
?>