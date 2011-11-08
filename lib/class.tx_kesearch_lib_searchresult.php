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
class tx_kesearch_lib_searchresult {

	protected $conf = array();
	protected $row = array();

	/**
	 * @var tx_kesearch_lib
	 */
	protected $pObj;

	/**
	 * @var tslib_cObj
	 */
	protected $cObj;

	/**
	 * @var tx_kesearch_lib_div
	 */
	protected $div;





	/**
	 * The constructor of this class
	 *
	 * @param tx_kesearch_lib $pObj
	 */
	public function __construct(tx_kesearch_lib $pObj) {
		// initializes this object
		$this->init($pObj);
	}


	/**
	 * Initializes this object
	 *
	 * @param tx_kesearch_lib $pObj
	 * @return void
	 */
	public function init(tx_kesearch_lib $pObj) {
		$this->pObj = $pObj;
		$this->row = $row;
		$this->cObj = $this->pObj->cObj;
		$this->conf = $this->pObj->conf;
	}


	/**
	 * set row array with current result element
	 *
	 * @param array $row
	 * @return void
	 */
	public function setRow(array $row) {
		$this->row = $row;
	}


	/**
	 * get title for result row
	 *
	 * @return string The linked result title
	 */
	public function getTitle() {
		// configure the link
		$linkconf = $this->getResultLinkConfiguration();

		// clean title
		$linktext = $this->row['title'];
		$linktext = strip_tags($linktext);
		$linktext = $this->pObj->div->removeXSS($linktext);
		$linktext = $linktext;

		// highlight hits in result title?
		if($this->conf['highlightSword'] && count($this->pObj->swords)) {
			foreach($this->pObj->swords as $word) {
				$word = str_replace('/', '\/', $word);
				$linktextReplaced = preg_replace('/(' . $word . ')/iu','<span class="hit">\0</span>', $linktext);
				if(!empty($linktextReplaced)) $linktext = $linktextReplaced;
			}
		}
		return $this->cObj->typoLink($this->row['title'], $linkconf);
	}


	/**
	 * get result url (not linked)
	 *
	 * @return string The results URL
	 */
	public function getResultUrl($linked = FALSE) {
		$resultUrl = t3lib_div::getIndpEnv('TYPO3_SITE_URL') . $this->cObj->typoLink_URL(
			$this->getResultLinkConfiguration()
		);
		if($linked) {
			$linkConf['parameter'] = $resultUrl;
			$resultUrl = $this->cObj->typoLink($resultUrl, $linkConf);
		}
		return $resultUrl;
	}


	/**
	 * get result link configuration
	 * It can devide between the result types (file, page, content)
	 *
	 * @return array configuration for typolink
	 */
	public function getResultLinkConfiguration() {
		$linkconf = array();

		switch($this->row['type']) {
			case 'file': // render a link for files
				$relPath = str_replace(PATH_site, '', $this->row['directory']);
				$linkconf['parameter'] = $relPath . rawurlencode($this->row['title']);
				$linkconf['fileTarget'] = $this->conf['resultLinkTarget'];
				break;
			default: // render a link for page targets
				// if params are filled, add them to the link generation process
				if (!empty($row['params'])) {
					$additionalParams = $row['params'];
				}
				$linkconf['additionalParams'] = $additionalParams;
				$linkconf['parameter'] = $this->row['targetpid'];
				$linkconf['useCacheHash'] = true;
				$linkconf['target'] = $this->conf['resultLinkTarget'];
				break;
		}

		return $linkconf;
	}
}

if(defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/pi1/class.tx_kesearch_lib_searchresult.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/pi1/class.tx_kesearch_lib_searchresult.php']);
}
?>
