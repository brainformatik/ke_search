<?php
interface tx_kesearch_indexer_filetypes {

	/**
	 * get Content of PDF file
	 *
	 * @param string $file
	 * @return string The extracted content of the file
	 */
	public function getContent($file);
}
?>