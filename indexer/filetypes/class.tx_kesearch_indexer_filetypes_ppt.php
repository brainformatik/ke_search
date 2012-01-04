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

require_once(t3lib_extMgm::extPath('ke_search').'indexer/types/class.tx_kesearch_indexer_types_file.php');

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_filetypes_ppt extends tx_kesearch_indexer_types_file implements tx_kesearch_indexer_filetypes {
	var $extConf = array(); // saves the configuration of extension ke_search_hooks
	var $app = array(); // saves the path to the executables
	var $isAppArraySet = false;


	/**
	 * class constructor
	 */
	public function __construct() {
		// get extension configuration of ke_search
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search']);

		//
		if($this->extConf['pathCatppt'])	{
			$pathCatppt = rtrim($this->extConf['pathCatppt'], '/') . '/';
			$safeModeEnabled = t3lib_utility_PhpOptions::isSafeModeEnabled();
			$exe = (TYPO3_OS == 'WIN') ? '.exe' : '';
			if($safeModeEnabled || (@is_file($pathCatppt . 'catppt' . $exe))) {
				$this->app['catppt'] = $pathCatppt . 'catppt' . $exe;
				$this->isAppArraySet = true;
			} else $this->isAppArraySet = false;
		} else $this->isAppArraySet = false;
		if(!$this->isAppArraySet) t3lib_utility_Debug::debug('The path for the catppttools is not correctly set in extConf. You can get the path with "which catppt".');
	}


	/**
	 * get Content of PDF file
	 *
	 * @param string $file
	 * @return string The extracted content of the file
	 */
	public function getContent($file) {
		// create the tempfile which will contain the content
		$tempFileName = t3lib_div::tempnam('ppt_files-Indexer');
		@unlink ($tempFileName); // Delete if exists, just to be safe.

		// generate and execute the pdftotext commandline tool
		$cmd = $this->app['catppt'] . ' -s UTF-8 ' . escapeshellarg($file) . ' > ' . $tempFileName;
		t3lib_utility_Command::exec($cmd);

		// check if the tempFile was successfully created
		if(@is_file($tempFileName)) {
			$content = t3lib_div::getUrl($tempFileName);
			unlink($tempFileName);
		} else return false;

		// check if content was found
		if(strlen($content)) {
			return $content;
		} else return false;
	}
}
?>