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
 * Plugin 'Faceted search - searchbox and filters' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_db {
	var $conf = array();
	var $bestIndex = '';
	var $countResultsOfTags = 0;
	var $countResultsOfContent = 0;
	var $table = 'tx_kesearch_index';
	
	/**
	 * Contains the parent object
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

	public function generateQueryForSearch() {
		$fields = '*';
		$table = $this->table . $this->bestIndex;
		$where = '1=1';
		
		// if a searchword was given, calculate percent of score
		if(count($this->pObj->swords)) {
			$fields .= ',
				MATCH (title, content) AGAINST ("' . $this->pObj->scoreAgainst . '") + (' . $this->pObj->extConf['multiplyValueToTitle'] . ' * MATCH (title) AGAINST ("' . $this->pObj->scoreAgainst . '")) AS score,
				IFNULL(ROUND(MATCH (title, content) AGAINST ("' . $this->pObj->scoreAgainst . '") + (' . $this->pObj->extConf['multiplyValueToTitle'] . ' * MATCH (title) AGAINST ("' . $this->pObj->scoreAgainst . '")) / maxScore * 100), 0) AS percent
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

		// process query
		if(count($this->pObj->swords)) {
			$query = $GLOBALS['TYPO3_DB']->SELECTquery(
				$fields,
				$table, 
				$where,
				'', '', $limit
			);
			$query = $GLOBALS['TYPO3_DB']->SELECTquery('*', '(' . $query . ') as results', '', '', 'results.' . $orderBy, '');
		} else {
			$query = $GLOBALS['TYPO3_DB']->SELECTquery($fields, $table, $where, '', $orderBy, $limit);
		}
		return $query;
	}	
	
	/**
	 * Counts the search results
	 * It's better to make an additional query than working with SQL_CALC_FOUND_ROWS. Further we don't have to lock tables.
	 * 
	 * @return integer Amount of SearchResults
	 */
	public function getAmountOfSearchResults() {
		// Execute query only if we haven't count the results before
		if(!$this->pObj->numberOfResults) {
			$where = '1=1';
	
			// add where clause
			$where .= $this->getWhere();
	
			// never add something like ORDER BY, GROUP BY or limit to this query!!!
			// never insert something others like '*' to field part
			$this->pObj->numberOfResults = $GLOBALS['TYPO3_DB']->exec_SELECTcountRows(
				'*',
				$this->table . $this->bestIndex, 
				$where
			);
		}
		
		return $this->pObj->numberOfResults;
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
				$this->createQueryForTags($tags)
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
	protected function createQueryForTags($tags) {
		if(count($tags)) {
			foreach($tags as $value) {
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
	protected function getOrdering() {
		$orderBy = $this->conf['sortWithoutSearchword'];
		
		if(!$this->pObj->isEmptySearch) {
			// if sorting in FE is allowed
			if($this->conf['showSortInFrontend']) {
				$tempField = strtolower(t3lib_div::removeXSS($this->pObj->piVars['orderByField']));
				$tempDir = strtolower(t3lib_div::removeXSS($this->pObj->piVars['orderByDir']));
				if($tempField != '' && $tempDir != '') {
					// overwrite ordering with piVars when allowed
					if($this->conf['sortByVisitor'] != '' && t3lib_div::inList($this->conf['sortByVisitor'], $tempField)) {
						$orderBy = $tempField . ' ' . $tempDir;
					}
				}
			} else { // if sorting is predefined by admin
				$orderBy = $this->conf['sortByAdmin'];
			}				
		}
		return $orderBy;
	}
	
	/**
	 * get limit for where query
	 */
	protected function getLimit() {
		$limit = 10;

		if($this->pObj->piVars['page']) {
			$start = ($this->pObj->piVars['page'] * $this->conf['resultsPerPage']) - $this->conf['resultsPerPage'];
			if($start < 0) $start = 0;
			$limit = $start . ', ' . $this->conf['resultsPerPage'];
		}
		
		return $limit;
	}
}
?>