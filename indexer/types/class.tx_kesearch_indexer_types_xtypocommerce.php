<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
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

require_once(t3lib_extMgm::extPath('ke_search').'indexer/class.tx_kesearch_indexer_types.php');

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_xtypocommerce extends tx_kesearch_indexer_types {
	
	/**
	 * Initializes indexer for xtypocommerce
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);
	}
	
	
	/**
	 * This function was called from indexer object and saves content to index table
	 * 
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		$content = '';

		// get xtypocommerce products
		$fields = '*';
		$table = 'tx_xtypocommerce_products, tx_xtypocommerce_products_description';
		$where = 'tx_xtypocommerce_products.products_id = tx_xtypocommerce_products_description.products_id';
		$where .= ' AND tx_xtypocommerce_products_description.products_name <> "" ';
		$where .= ' AND tx_xtypocommerce_products_description.products_description <> "" ';
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
		$resCount = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		while ($prodRecord=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {

			$additionalFields = array();

			// prepare content for storing in index table
			$title = strip_tags($prodRecord['products_name']);
			$tags = '';
			$params = '&xtypocommerce[product]='.intval($prodRecord['products_id']);
			$description = strip_tags($prodRecord['products_description']);

			// keywords
			$keywordsContent = '';
			$keywords = t3lib_div::trimExplode(',', $prodRecord['products_keywords'], true);
			if (is_array($keywords)) {
				foreach ($keywords as $index => $keyword) {
					$keywordsContent .= $keyword."\n";
				}
			}

			// build full content
			$fullContent = $description. "\n" . $keywordsContent;

			// set target pid
			$targetPID = $this->indexerConfig['targetpid'];

			// get tags
			$tagContent = '';
			// categories
			$catRootline = $this->getXTYPOCommerceCategories($prodRecord['products_id']);
			if (is_array($catRootline)) {
				foreach ($catRootline as $productId => $categories) {
					$catArray = t3lib_div::trimExplode(',', $categories);
					foreach($catArray as $key => $catId)  {
						$tagContent .= '#category_'.$catId.'#';
					}
				}
			}

			// regions
			if (!empty($prodRecord['products_region']) || !empty($prodRecord['products_countries'])) {
				$tagContent .= $this->getXTYPOCommerceRegionAndCountryTags($prodRecord['products_region'], $prodRecord['products_countries']);
			}
			// manufacturer
			if (!empty($prodRecord['manufacturers_id'])) {
				// publisher
				$tagContent .= '#publisher_'.$prodRecord['manufacturers_id'].'#';
			}
			// language
			if (!empty($prodRecord['products_language'])) {
				// language
				$lang = $prodRecord['products_language'];
				// niederländisch
				if (stristr($lang, 'Deutsch') && stristr($lang, 'Englisch')) {
					// deutsch oder englisch
					$lang = 'Deutsch_oder_Englisch';
				} else if (stristr($lang, 'Deutsch')) {
					// nur Deutsch
					$lang = 'Deutsch';
				} else if (stristr($lang, 'Englisch')) {
					// nur Englisch
					$lang = 'Englisch';
				} else if (stristr($lang, 'niederl')) {
					$lang = 'Niederlaendisch';
				} else
				$tagContent .= '#language_'.$lang.'#';
			}
			// publish date
			if (!empty($prodRecord['products_monat']) && !empty($prodRecord['products_jahr'])) {
				// date
				$tagContent .= '#year_'.$prodRecord['products_jahr'].'#';

				// set date as sortdate
				$additionalFields['sortdate'] = mktime(0,0,0,$prodRecord['products_monat'], 1, $prodRecord['products_jahr']);
			}

			// hook for custom modifications of the indexed data, e. g. the tags
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyXTYPOCommerceIndexEntry'])) {
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyXTYPOCommerceIndexEntry'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$_procObj->modifyXTYPOCommerceIndexEntry(
						$title,
						$abstract,
						$fullContent,
						$params,
						$tagContent,
						$prodRecord,
						$additionalFields
					);
				}
			}

			// store in index
			$this->pObj->storeInIndex(
				$this->indexerConfig['storagepid'], // storage PID
				$title,                       // page/record title
				'xtypocommerce',              // content type
				$targetPID,                   // target PID: where is the single view?
				$fullContent,                 // indexed content, includes the title (linebreak after title)
				$tagContent,                  // tags
				$params,                      // typolink params for singleview
				$abstract,                    // abstract
				0,                            // language uid
				0,                            // starttime
				0,                            // endtime
				0,                            // fe_group
				false,                        // debug only?
				$additionalFields             // additional fields added by hooks
			);

		}

		$content = '<p><b>Indexer "' . $this->indexerConfig['title'] . '": ' . $resCount . ' Products have been indexed.</b></p>';

		return $content;
	}


	/*
	 * function getXTYPOCommerceRegionAndCountryTags()
	 * @param $arg
	 */
	function getXTYPOCommerceRegionAndCountryTags($regions, $countries) {
		$tagContent = '';

		$regions = t3lib_div::trimExplode(',', $regions, true);
		if (is_array($regions)) {
			foreach ($regions as $key => $region) {
				$tagContent .= '#region_'.$region.'#';
			}
		}

		$countries = t3lib_div::trimExplode(',', $countries, true);
		if (is_array($countries)) {
			foreach ($countries as $key => $country) {
				$tagContent .= '#country_'.$country.'#';
			}
		}

		return $tagContent;

	}


	/*
	 * function getRecursiveXTYPOCommerceCategories
	 * @param $product_id
	 */
	function getXTYPOCommerceCategories($product_id) {
		// get products' categories
		$fields = 'categories_id';
		$table = 'tx_xtypocommerce_products_to_categories';
		$where = 'products_id = "'.intval($product_id).'" ';
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
		$anz = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			$categories[] = $row['categories_id'];
		}

		// get recursive categories
		if (is_array($categories)) {
			foreach ($categories as $index => $catId) {
				$this->catRoot = '';
				$this->getXTYPOCommerceParentCat($catId);
				$this->catRoot = t3lib_div::rm_endcomma($this->catRoot);
				$catRootline[$catId] = $this->catRoot;
			}

		}
		return $catRootline;

	}


	/*
	 * function getXTYPOCommerceCatRootline
	 * @param $catId int
	 * @return void
	 */
	function getXTYPOCommerceParentCat($catId) {
		$fields = '*';
		$table = 'tx_xtypocommerce_categories';
		$where = 'categories_id="'.intval($catId).'" ';
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
		while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			if ($row['categories_id'] > 0) {
				$this->catRoot .= $row['categories_id'].',';
			}
			if ($row['parent_id'] > 0) {
				$this->catRoot .= $this->getXTYPOCommerceParentCat($row['parent_id']);
			}
		}
	}
}