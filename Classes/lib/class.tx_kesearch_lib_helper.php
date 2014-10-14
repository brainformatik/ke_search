<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2014 Christian Bülter (kennziffer.com) <buelter@kennziffer.com>
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

class tx_kesearch_helper {
	public function getExtConf() {
		$extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search']);

		// Set the "tagChar"
		// sphinx has problems with # in query string.
		// so you we need to change the default char # against something else.
		// MySQL has problems also with #
		// but we wrap # with " and it works.
		if(t3lib_extMgm::isLoaded('ke_search_premium')) {
			$extConfPremium = tx_kesearch_helper::getExtConfPremium();
			$extConf['prePostTagChar'] = $extConfPremium['prePostTagChar'];
		} else {
			$extConf['prePostTagChar'] = '#';
		}
		$extConf['multiplyValueToTitle'] = ($extConf['multiplyValueToTitle']) ? $extConf['multiplyValueToTitle'] : 1;
		$extConf['searchWordLength'] = ($extConf['searchWordLength']) ? $extConf['searchWordLength'] : 4;

		// override extConf with TS Setup
		if (is_array($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.']) && count ($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'])) {
			foreach ($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'] as $key => $value) {
				$extConfPremium[$key] = $value;
			}
		}

		return $extConf;
	}

	public function getExtConfPremium() {
		if(t3lib_extMgm::isLoaded('ke_search_premium')) {
			$extConfPremium = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search_premium']);
			if (!$extConfPremium['prePostTagChar']) $extConfPremium['prePostTagChar'] = '_';
		} else {
			$extConfPremium = array();
		}

		// override extConfPremium with TS Setup
		if (is_array($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.']) && count ($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'])) {
			foreach ($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'] as $key => $value) {
				$extConfPremium[$key] = $value;
			}
		}

		return $extConfPremium;
	}

}
