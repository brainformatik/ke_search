<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 Stefan Froemken <froemken@kennziffer.com>
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
 * Hooks for ke_search
 *
 * @author Stefan Froemken <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	ke_search
 */

class user_kesearch_sortdate {
	public function registerAdditionalFields(&$additionalFields) {
		$newField = array('sortdate', 0);
		$additionalFields[] = $newField;
	}

	public function modifyPagesIndexEntry(&$title, &$pageContent, &$tags, $pageRecord, &$additionalFields) {
		// crdate is always given, but can be overwritten
		if(isset($pageRecord['crdate']) && $pageRecord['crdate'] > 0) {
			$additionalFields['sortdate'] = $pageRecord['crdate'];
		}
		// if TYPO3 sets last changed
		if(isset($pageRecord['SYS_LASTCHANGED']) && $pageRecord['SYS_LASTCHANGED'] > 0) {
			$additionalFields['sortdate'] = $pageRecord['SYS_LASTCHANGED'];
		}
		// if the user has manually set a date
		if(isset($pageRecord['lastUpdated']) && $pageRecord['lastUpdated'] > 0) {
			$additionalFields['sortdate'] = $pageRecord['lastUpdated'];
		}
	}

	public function modifyNewsIndexEntry(&$title, &$abstract, &$fullContent, &$params, &$tags, $newsRecord, &$additionalFields) {
		// crdate is always given, but can be overwritten
		if(isset($newsRecord['crdate']) && $newsRecord['crdate'] > 0) {
			$additionalFields['sortdate'] = $newsRecord['crdate'];
		}
		// if TYPO3 sets last changed
		if(isset($newsRecord['datetime']) && $newsRecord['datetime'] > 0) {
			$additionalFields['sortdate'] = $newsRecord['datetime'];
		}
	}

	public function modifyYACIndexEntry(&$title, &$abstract, &$fullContent, &$params, &$tags, $yacRecord, $targetPID, &$additionalFields) {
		// crdate is always given, but can be overwritten
		if(isset($yacRecord['crdate']) && $yacRecord['crdate'] > 0) {
			$additionalFields['sortdate'] = $yacRecord['crdate'];
		}
		// if TYPO3 sets last changed
		if(isset($yacRecord['starttime']) && $yacRecord['starttime'] > 0) {
			$additionalFields['sortdate'] = $yacRecord['starttime'];
		}
	}

	public function modifyDAMIndexEntry(&$title, &$abstract, &$fullContent, &$params, &$tags, $damRecord, $targetPID, &$clearTextTags, &$additionalFields) {
		// crdate is always given, but can be overwritten
		if(isset($damRecord['crdate']) && $damRecord['crdate'] > 0) {
			$additionalFields['sortdate'] = $damRecord['crdate'];
		}
		// if TYPO3 sets last changed
		if(isset($damRecord['file_ctime']) && $damRecord['file_ctime'] > 0) {
			$additionalFields['sortdate'] = $damRecord['file_ctime'];
		}
		// if TYPO3 sets last changed
		if(isset($damRecord['file_mtime']) && $damRecord['file_mtime'] > 0) {
			$additionalFields['sortdate'] = $damRecord['file_mtime'];
		}
	}

	public function modifyXTYPOCommerceIndexEntry(&$title, &$abstract, &$fullContent, &$params, &$tagContent, $prodRecord, &$additionalFields) {

	}
	
}
?>
