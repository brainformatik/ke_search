<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
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
 * DB Class for ke_search, generates search queries.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_db implements t3lib_Singleton {
	var $conf = array();
	var $bestIndex = '';
	var $countResultsOfTags = 0;
	var $countResultsOfContent = 0;
	var $table = 'tx_kesearch_index';
	protected $hasSearchResults = TRUE;
	protected $searchResults = array();

	/**
	 * @var tx_kesearch_pi1
	 */
	var $pObj;

	/**
	 * @var tslib_cObj
	 */
	var $cObj;

	public function __construct($pObj) {
		$this->pObj = $pObj;
		$this->cObj = $this->pObj->cObj;
		$this->conf = $this->pObj->conf;
	}


	public function getSearchResults() {
		// if there are no searchresults return the empty result array directly
		if(!$this->hasSearchResults) return $this->searchResults;

		// if result array is empty start search on DB, else return cached result list
		if(!count($this->searchResults)) {
			if($this->pObj->extConfPremium['enableSphinxSearch'] && !$this->pObj->isEmptySearch) {
				$this->searchResults = $this->getSearchResultBySphinx();
			} else $this->searchResults = $this->getSearchResultByMySQL();
			if(count($this->searchResults) === 0) {
				$this->hasSearchResults = FALSE;
			}
		}
		return $this->searchResults;
	}


	/**
	 * get a limitted amount of search results for a requested page
	 *
	 * @return array Array containing a limitted (one page) amount of search results
	 */
	public function getSearchResultByMySQL() {
		$queryParts = $this->getQueryParts();

		return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			$queryParts['SELECT'],
			$queryParts['FROM'],
			$queryParts['WHERE'],
			$queryParts['GROUPBY'],
			$queryParts['ORDERBY'],
			'',
			'uid'
		);
	}


	/**
	 * get a limitted amount of search results for a requested page
	 *
	 * @return array Array containing a limitted (one page) amount of search results
	 */
	public function getSearchResultBySphinx() {
		require_once(t3lib_extMgm::extPath('ke_search_premium') . 'class.user_kesearchpremium.php');
		$this->user_kesearchpremium = t3lib_div::makeInstance('user_kesearchpremium');

		// set ordering
		$this->user_kesearchpremium->setSorting($this->getOrdering());

		// set limit
		//$limit = $this->getLimit();
		//$this->user_kesearchpremium->setLimit($limit[0], $limit[1]);

		// generate query
		$queryForSphinx = '';
		if($this->pObj->wordsAgainst) $queryForSphinx .= ' @(title,content) ' . $this->pObj->wordsAgainst;
		if(count($this->pObj->tagsAgainst)) {
			foreach($this->pObj->tagsAgainst as $value) {
				// in normal case only checkbox mode has spaces
				$queryForSphinx .= ' @tags ' . str_replace('" "', '" | "', trim($value));
			}
		}
		$queryForSphinx .= ' @language _language_-1 | _language_' . $GLOBALS['TSFE']->sys_language_uid;
		$queryForSphinx .= ' @fe_group _group_NULL | _group_0';

		// add fe_groups to query
		if(!empty($GLOBALS['TSFE']->gr_list)) {
			$feGroups = t3lib_div::trimExplode(',', $GLOBALS['TSFE']->gr_list, 1);
			foreach($feGroups as $key => $group) {
				if ($this->pObj->div->getNumericTYPO3versionNumber() >= 6000000) {
					$intval_positive_group = TYPO3\CMS\Core\Utility\MathUtility::convertToPositiveInteger($group);
				} else {
					$intval_positive_group = t3lib_div::intval_positive($group);
				}
				if($intval_positive_group) {
					$feGroups[$key] = '_group_' . $group;
				} else unset($feGroups[$key]);
			}
			if(is_array($feGroups) && count($feGroups)) $queryForSphinx .= ' | ' . implode(' | ', $feGroups);
		}

		// hook for appending additional where clause to sphinx query
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['appendWhereToSphinx'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['appendWhereToSphinx'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$queryForSphinx = $_procObj->appendWhereToSphinx($queryForSphinx, $this->user_kesearchpremium, $this);
			}
		}
		$rows = $this->user_kesearchpremium->getSearchResults($queryForSphinx);

		// get number of records
		$this->numberOfResults = $this->user_kesearchpremium->getTotalFound();
		return $rows;
	}


	/**
	 * get query parts like SELECT, FROM and WHERE for MySQL-Query
	 *
	 * @return array Array containing the query parts for MySQL
	 */
	public function getQueryParts() {
		$fields = '*';
		$table = $this->table . $this->bestIndex;
		$where = '1=1';

		// if a searchword was given, calculate percent of score
		if($this->pObj->sword) {
			$fields .= ',
				MATCH (title, content) AGAINST ("' . $this->pObj->scoreAgainst . '") + (' . $this->pObj->extConf['multiplyValueToTitle'] . ' * MATCH (title) AGAINST ("' . $this->pObj->scoreAgainst . '")) AS score,
				IFNULL(ROUND((MATCH (title, content) AGAINST ("' . $this->pObj->scoreAgainst . '") + (' . $this->pObj->extConf['multiplyValueToTitle'] . ' * MATCH (title) AGAINST ("' . $this->pObj->scoreAgainst . '"))) / maxScore * 100), 0) AS percent
			';
			$table .= ',
				(SELECT MAX(MATCH (title, content) AGAINST ("' . $this->pObj->scoreAgainst . '") + (' . $this->pObj->extConf['multiplyValueToTitle'] . ' * MATCH (title) AGAINST ("' . $this->pObj->scoreAgainst . '"))) AS maxScore FROM ' . $this->table . ') maxScoreTable
			';
		}

		// add where clause
		$where .= $this->getWhere();

		// add ordering
		$orderBy = $this->getOrdering();

		// add limitation
		$limit = $this->getLimit();

		$queryParts = array(
			'SELECT' => $fields,
			'FROM' => $table,
			'WHERE' => $where,
			'GROUPBY' => '',
			'ORDERBY' => $orderBy,
			'LIMIT' => $limit[0] . ',' . $limit[1]
		);

		return $queryParts;
	}


	/**
	 * Counts the search results
	 * It's better to make an additional query than working with SQL_CALC_FOUND_ROWS. Further we don't have to lock tables.
	 *
	 * @return integer Amount of SearchResults
	 */
	public function getAmountOfSearchResults() {
		$this->getSearchResults();
		return count($this->searchResults);
	}


	/**
	 * get all tags which are found in search result
	 * additional the tags are counted
	 *
	 * @return array Array containing the tags as key and the sum as value
	 */
	public function getTagsFromSearchResult() {
		$rows = $this->getSearchResults();
		if(is_array($rows) && count($rows)) {
			$tagChar = $this->pObj->extConf['prePostTagChar'];
			foreach($rows as $row) {
				$divider = $tagChar . ',' . $tagChar;
				$row['tags'] = trim($row['tags'], $tagChar);
				foreach(explode($divider, $row['tags']) as $tag) {
					$tags[$tag] += 1;
				}
			} return $tags;
		} else return array();
	}


	/**
	 * This function is useful to decide which index to use
	 * In case of the individual amount of results (content and tags) another index can be much faster
	 *
	 * Never add ORDER BY, LIMIT or some additional MATCH-parts to this queries, because this slows down the query time.
	 *
	 * @param string $searchString
	 * @param string $tagsString
	 */
	public function chooseBestIndex($searchString = '', $tags) {
		$countQueries = 0;

		// Count results only if it is the first run and a searchword is given
		if(!$this->countResultsOfContent && $searchString != '') {
			$count = $GLOBALS['TYPO3_DB']->exec_SELECTcountRows(
				'uid',
				'tx_kesearch_index',
				'MATCH (title, content) AGAINST ("' . $searchString . '" IN BOOLEAN MODE)'
			);
			$this->countResultsOfContent = $count;
			$countQueries++;
		}

		// Count results only if it is the first run and a tagstring is given
		if(!$this->countResultsOfTags && count($tags)) {
			$count = $GLOBALS['TYPO3_DB']->exec_SELECTcountRows(
				'tags',
				'tx_kesearch_index',
				'1=1 ' . $this->createQueryForTags($tags)
			);
			$this->countResultsOfTags = $count;
			$countQueries++;
		}

		//decide which index to use
		if($countQueries == 2) {
			// if there are more results in content than in tags, another index is much faster than the index choosed by MySQL
			// With this configuration we speed up our results from 47 sec. to 7 sec. with over 50.000 records
			if($this->countContentResult > $this->countTagsResult) {
				$this->bestIndex = ' USE INDEX (tag)';
			} else {
				$this->bestIndex = ' USE INDEX (titlecontent)';
			}
		} else {
			// MySQL chooses the best index for you, if only one part (content OR tags) are given
			// With this configuration we speed up our results from 7 sec. to 2 sec. with over 50.000 records
			$this->bestIndex = '';
		}
	}


	/**
	 * In checkbox mode we have to create for each checkbox one MATCH-AGAINST-Construct
	 * So this function returns the complete WHERE-Clause for each tag
	 *
	 * @param array $tags
	 * @return string Query
	 */
	protected function createQueryForTags(array $tags) {
		if(count($tags) && is_array($tags)) {
			foreach($tags as $value) {
				$value = $GLOBALS['TYPO3_DB']->quoteStr($value, 'tx_kesearch_index');
				$where .= ' AND MATCH (tags) AGAINST (\'' . $value . '\' IN BOOLEAN MODE) ';
			}
			return $where;
		} return '';
	}


	/**
	 * get where clause for search results
	 *
	 * @return string where clause
	 */
	public function getWhere() {
		// add boolean where clause for searchwords
		if($this->pObj->wordsAgainst != '') {
			$where .= ' AND MATCH (title, content) AGAINST (\'' . $this->pObj->wordsAgainst . '\' IN BOOLEAN MODE) ';
		}

		// add boolean where clause for tags
		if(($tagWhere = $this->createQueryForTags($this->pObj->tagsAgainst))) {
			$where .= $tagWhere;
		}

		// restrict to storage page
		$where .= ' AND pid in (' . $this->pObj->startingPoints . ') ';

		// add language
		$lang = intval($GLOBALS['TSFE']->sys_language_uid);
		$where .= ' AND language IN(' . $lang . ', -1) ';

		// add "tagged content only" searchphrase
		if($this->conf['showTaggedContentOnly']) {
			$where .= ' AND tags <> ""';
		}

		// add enable fields
		$where .= $this->cObj->enableFields($this->table);

		return $where;
	}


	/**
	 * get ordering for where query
	 *
	 * @return string ordering (f.e. score DESC)
	 */
	public function getOrdering() {
		// if the following code fails, fall back to this default ordering
		$orderBy = $this->conf['sortWithoutSearchword'];

		// if sorting in FE is allowed
		if($this->conf['showSortInFrontend']) {
			$piVarsField = $this->pObj->piVars['sortByField'];
			$piVarsDir = $this->pObj->piVars['sortByDir'];
			$piVarsDir = ($piVarsDir == '') ? 'asc' : $piVarsDir;
			if(!empty($piVarsField)) { // if an ordering field is defined by GET/POST
				if($this->conf['sortByVisitor'] != '' && t3lib_div::inList($this->conf['sortByVisitor'], $piVarsField)) {
					$orderBy = $piVarsField . ' ' . $piVarsDir;
				} // if sortByVisitor is not set OR not in the list of allowed fields then use fallback ordering in "sortWithoutSearchword"
			} // if sortByVisitor is not set OR not in the list of allowed fields then use fallback ordering in "sortWithoutSearchword"
		} else if (!empty($this->pObj->wordsAgainst)) { // if sorting is predefined by admin
			$orderBy = $this->conf['sortByAdmin'];
		} else {
			$orderBy = $this->conf['sortWithoutSearchword'];
		}

		return $orderBy;
	}


	/**
	 * get limit for where query
	 */
	public function getLimit() {
		$limit = $this->conf['resultsPerPage'] ? $this->conf['resultsPerPage'] : 10;

		if($this->pObj->piVars['page']) {
			$start = ($this->pObj->piVars['page'] * $limit) - $limit;
			if($start < 0) $start = 0;
		}

		$startLimit = array($start, $limit);

		// hook for third party pagebrowsers or for modification $this->pObj->piVars['page'] parameter
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['getLimit'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['getLimit'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->getLimit($startLimit, $this);
			}
		}

		return $startLimit;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_db.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_db.php']);
}
?>