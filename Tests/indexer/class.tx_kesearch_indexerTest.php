<?php
class IndexerTest extends Tx_Extbase_BaseTestCase {

	/**
	 * @var tx_kesearch_indexer
	 */
	var $indexer;





	public function setUp() {
		$this->indexer = t3lib_div::makeInstance('tx_kesearch_indexer');
		$this->indexer->additionalFields = array('orig_uid', 'orig_pid', 'enddate');

	}

	public function tearDown() {
		unset($this->indexer);
	}





	/**
	 * Test additional query parts for additional fields
	 *
	 * @test
	 */
	public function checkGetQueryPartsForAdditionalFields() {
		$now = time();
		$fieldValues = array(
			'tstamp' => $now,
			'crdate' => $now,
			'title' => 'tolle Überschrift',
			'orig_uid' => 213,
			'orig_pid' => 423,
			'enddate' => $now,
		);
		$fieldValues = $GLOBALS['TYPO3_DB']->fullQuoteArray($fieldValues, 'tx_kesearch_index');

		$shouldArray = array(
			'set' => ', @orig_uid = \'213\', @orig_pid = \'423\', @enddate = \'' . $now . '\'',
			'execute' => ', @orig_uid, @orig_pid, @enddate'
		);

		$isArray = $this->indexer->getQueryPartForAdditionalFields($fieldValues);

		$this->assertEquals($shouldArray, $isArray);
	}
}
?>