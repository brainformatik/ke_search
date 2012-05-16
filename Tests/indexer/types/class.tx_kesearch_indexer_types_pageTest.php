<?php
class IndexerTypesPageTest extends Tx_Extbase_BaseTestCase {

	/**
	 * @var tx_kesearch_indexer_types_page
	 */
	var $pageIndexer;

	/**
	 * @var tx_kesearch_indexer_types
	 */
	var $indexerTypes;





	public function setUp() {
		$this->indexerTypes = t3lib_div::makeInstance('tx_kesearch_indexer_types');
		$this->indexerTypes->queryGen = t3lib_div::makeInstance('t3lib_queryGenerator');
		$this->pageIndexer = t3lib_div::makeInstance('tx_kesearch_indexer_types_page');
		$this->pageIndexer->pObj = t3lib_div::makeInstance('tx_kesearch_indexer');
		$this->pageIndexer->pObj->extConf['prePostTagChar'] = '#';
	}

	public function tearDown() {
		unset($this->pageIndexer);
		unset($this->indexerTypes);
	}





	/**
	 * Test method addTagsToPageRecords
	 *
	 * @test
	 */
	public function addTagsToPageRecordsTest() {
		// get the rootPage UID. In most cases it should have recursive child elements
		$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'uid',
			'pages',
			'deleted=0 AND hidden=0 AND is_siteroot=1',
			'', '', '1'
		);
		if(count($rows) > 0) {
			$rootPage = $rows[0]['uid'];
		} else $rootPage = 1;

		// get all pages. Regardeless if they are shortcut, sysfolder or external link
		$indexPids = $this->pageIndexer->getPagelist($rootPage);

		// add complete page record to list of pids in $indexPids
		// and remove all page of type shortcut, sysfolder and external link
		$this->pageIndexer->pageRecords = $this->pageIndexer->getPageRecords($indexPids);

		// create a new list of allowed pids
		$indexPids = array_keys($this->pageIndexer->pageRecords);

		// add the tags of each page to the global page array
		$this->pageIndexer->addTagsToPageRecords($indexPids);
		//t3lib_utility_Debug::debug($this->pageIndexer->pageRecords, 'pages');
	}
}
?>