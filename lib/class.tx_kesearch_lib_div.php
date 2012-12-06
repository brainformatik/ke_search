<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * Plugin 'Faceted search - searchbox and filters' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_lib_div {
	var $showShortMessage = false;

	/**
	 * Contains the parent object
	 * @var tx_kesearch_pi1
	 */
	var $pObj;

	public function __construct($pObj) {
		$this->pObj = $pObj;
	}

	public function getStartingPoint() {
		// if loadFlexformsFromOtherCE is set
		// try to get startingPoint of given page
		if($uid = intval($this->pObj->conf['loadFlexformsFromOtherCE'])) {
			$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
				'pages, recursive',
				'tt_content',
				'uid = ' . $uid,
				'', '', ''
			);
			if($GLOBALS['TYPO3_DB']->sql_num_rows($res)) {
				$row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
				return $this->pObj->pi_getPidList($row['pages'], $row['recursive']);
			}
		}
		// if loadFlexformsFromOtherCE is NOT set
		// get startingPoints of current page
		return $this->pObj->pi_getPidList(
			$this->pObj->cObj->data['pages'],
			$this->pObj->cObj->data['recursive']
		);
	}

	/**
	 * Get the first page of starting points
	 * @param string comma seperated list of page-uids
	 * @return int first page uid
	 */
	public function getFirstStartingPoint($pages = 0) {
		$pageArray = explode(',', $pages);
		return intval($pageArray[0]);
	}


	/**
	* Use removeXSS function from t3lib_div if exists
	* otherwise use removeXSS class included in this extension
	*
	* @param string value
	* @return string XSS safe value
	*/
	public function removeXSS($value) {
		if(method_exists(t3lib_div, 'removeXSS')) {
			return t3lib_div::removeXSS($value);
		} else {
			require_once(t3lib_extMgm::extPath($this->extKey).'res/scripts/RemoveXSS.php');
			return RemoveXSS::process($value);
		}
	}


	/**
	 * Returns the current TYPO3 version number as an integer, eg. 4005000 for version 4.5
	 *
	 * @return int
	 */
	public function getNumericTYPO3versionNumber() {
		if (class_exists('VersionNumberUtility')) {
			$numeric_typo3_version = \TYPO3\CMS\Core\Utility\VersionNumberUtility::convertVersionNumberToInteger(TYPO3_version);
		} else if (class_exists('t3lib_utility_VersionNumber')) {
			$numeric_typo3_version = t3lib_utility_VersionNumber::convertVersionNumberToInteger(TYPO3_version);
		} else {
			$numeric_typo3_version = t3lib_div::int_from_ver(TYPO3_version);
		}
		return $numeric_typo3_version;
	}


	/**
	 * function cleanPiVars
	 *
	 * cleans all piVars used in this EXT
	 * uses removeXSS(...), htmlspecialchars(...) and / or intval(...)
	 *
	 * @param $piVars array		array containing all piVars
	 *
	 */
	public function cleanPiVars($piVars) {

		// run through all piVars
		foreach ($piVars as $key => $value) {

			// process removeXSS(...) for all piVars
			if(!is_array($piVars[$key])) $piVars[$key] = $this->removeXSS($value);

			// process further cleaning regarding to param type
			switch ($key) {

				// intvals - default 1
				case 'page':
					$piVars[$key] = intval($value);
					// set to "1" if no value set
					if (!$piVars[$key]) $piVars[$key] = 1;
					break;

				// intvals
				case 'resetFilters':
					$piVars[$key] = intval($value);
					break;

				// string arrays
				case 'filter':
					if(is_array($piVars[$key])) {
						foreach($piVars[$key] as $filterId => $filterValue)  {
							if(is_array($piVars[$key][$filterId])) {
								foreach($piVars[$key][$filterId] as $key => $value) {
									$piVars[$key][$filterId][$key] = htmlspecialchars($value, ENT_QUOTES);
								}
							} else {
								$piVars[$key][$filterId] = htmlspecialchars($filterValue, ENT_QUOTES);
							}
						}
					}
					break;

				// string
				case 'sword':
				case 'orderByField':
					$value = trim($value);
					//$value = str_replace(array('"', "'"), array(' ', ' '), $value);
					$piVars[$key] = htmlspecialchars($value, ENT_NOQUOTES);
					break;

				// "asc" or "desc"
				case 'orderByDir':
					$piVars[$key] = strtolower(htmlspecialchars($value, ENT_QUOTES));
					if ($piVars[$key] != 'asc' && $piVars[$key] != 'desc') {
						$piVars[$key] = 'asc';
					}
					break;
			}
		}

		// return cleaned piVars values
		return $piVars;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_lib_div.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_lib_div.php']);
}
?>
