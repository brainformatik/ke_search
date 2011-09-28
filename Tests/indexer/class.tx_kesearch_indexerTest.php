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
	
	
	/**
	 * Test prepare record for insert
	 *
	 * @test
	 */
	public function checkPrepareRecordForInsert() {
		$now = time();
		$fieldValues = array(
			'pid' => 12,
			'title' => 'tolle Überschrift',
			'type' => 'tt_news',
			'targetpid' => 432,
			'content' => 'Ich warte schon lange auf diesen Moment',
			'tags' => 'cat_auto,area_zeppelin',
			'params' => '&tx_ttnews[tt_news]=4321',
			'abstract' => 'Ich warte schon lange...',
			'language' => 0,
			'starttime' => $now,
			'endtime' => $now,
			'fe_group' => '0,2,5',
			'tstamp' => $now,
			'crdate' => $now,
			'orig_uid' => 213,
			'orig_pid' => 423,
			'enddate' => $now,
		);
		$fieldValues = $GLOBALS['TYPO3_DB']->fullQuoteArray($fieldValues, 'tx_kesearch_index');
		
		$shouldArray[] = array(
			'set' => 'SET
				@pid = \'12\',
				@title = \'tolle Überschrift\',
				@type = \'tt_news\',
				@targetpid = \'432\',
				@content = \'Ich warte schon lange auf diesen Moment\',
				@tags = \'cat_auto,area_zeppelin\',
				@params = \'&tx_ttnews[tt_news]=4321\',
				@abstract = \'Ich warte schon lange...\',
				@language = \'0\',
				@starttime = \'' . $now . '\',
				@endtime = \'' . $now . '\',
				@fe_group = \'0,2,5\',
				@tstamp = \'' . $now . '\',
				@crdate = \'' . $now . '\', @orig_uid = \'213\', @orig_pid = \'423\', @enddate = \'' . $now . '\'
			;',
			'execute' => '
				EXECUTE insertStmt USING @pid, @title, @type, @targetpid, @content, @tags, @params, @abstract, @language, @starttime, @endtime, @fe_group, @tstamp, @crdate, @orig_uid, @orig_pid, @enddate;
			'
		);
		
		// this method sets global var $this->indexer->tempArrayForInsertNewRecords
		$this->indexer->prepareRecordForInsert($fieldValues);
		
		$this->assertEquals($shouldArray, $this->indexer->tempArrayForInsertNewRecords);
	}
}
?>