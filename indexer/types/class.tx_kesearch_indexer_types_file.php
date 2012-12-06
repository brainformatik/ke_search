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

require_once(t3lib_extMgm::extPath('ke_search').'indexer/class.tx_kesearch_indexer_types.php');

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_file extends tx_kesearch_indexer_types {

	var $extConf = array(); // saves the configuration of extension ke_search_hooks
	var $app = array(); // saves the path to the executables
	var $isAppArraySet = false;

	/**
	 * @var tx_kesearch_lib_fileinfo
	 */
	var $fileInfo;





	/**
	 * Initializes indexer for files
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);
		// get extension configuration of ke_search
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search']);
		$this->fileInfo = t3lib_div::makeInstance('tx_kesearch_lib_fileinfo');
	}


	/**
	 * initializes the object and the executables to get content of given files
	 */
	public function init() {
		if($this->extConf['pathPdftotext']) {
			$pathPdftotext = rtrim($this->extConf['pathPdftotext'], '/') . '/';
			$pathPdfinfo = rtrim($this->extConf['pathPdfinfo'], '/') . '/';
			$safeModeEnabled = t3lib_utility_PhpOptions::isSafeModeEnabled();
			$exe = (TYPO3_OS == 'WIN') ? '.exe' : '';
			if($safeModeEnabled || (@is_file($pathPdftotext . 'pdftotext' . $exe) && @is_file($pathPdfinfo . 'pdfinfo' . $exe))) {
				$this->app['pdfinfo'] = $pathPdfinfo . 'pdfinfo' . $exe;
				$this->app['pdftotext'] = $pathPdftotext . 'pdftotext' . $exe;
				$this->isAppArraySet = true;
			} else $this->isAppArraySet = false;
		} else $this->isAppArraySet = false;
		if(!$this->isAppArraySet) t3lib_utility_Debug::debug('The path for the pdftools is not correctly set in extConf. You can get the path with "which pdfinfo" or "which pdftotext".');
	}


	/**
	 * This function was called from indexer object and saves content to index table
	 *
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		$directories = $this->indexerConfig['directories'];
		$directoryArray = t3lib_div::trimExplode(',', $directories, true);
		$files = $this->getFilesFromDirectories($directoryArray);
		$this->extractContentAndSaveToIndex($files);

		// show indexer content?
		$content .= '<p><b>Indexer "' . $this->indexerConfig['title'] . '": </b><br />'
			. count($files) . ' files have been found for indexing.</b></p>' . "\n";

		$content .= $this->showTime();

		return $content;
	}


	/**
	 * get files from given relative directory path array
	 *
	 * @param array $directoryArray
	 * @return array An Array containing all files of all valid directories
	 */
	public function getFilesFromDirectories(array $directoryArray) {
		$directoryArray = $this->getAbsoluteDirectoryPath($directoryArray);
		if(is_array($directoryArray) && count($directoryArray)) {
			foreach($directoryArray as $directory) {
				$foundFiles = t3lib_div::getAllFilesAndFoldersInPath(array(), $directory, $this->indexerConfig['fileext']);
				if(is_array($foundFiles) && count($foundFiles)) {
					foreach($foundFiles as $file) {
						$files[] = $file;
					}
				}
			}
			return $files;
		} else return array();
	}


	/**
	 * get absolute directory paths of given path in array
	 *
	 * @param array $directory
	 * @return array An Array containing the absolute directory paths
	 */
	public function getAbsoluteDirectoryPath(array $directoryArray) {
		if(is_array($directoryArray) && count($directoryArray)) {
			foreach($directoryArray as $key => $directory) {
				$directory = rtrim($directory, '/');
				$directoryArray[$key] = PATH_site . $directory . '/';
			}
			return $directoryArray;
		} else return array();
	}


	public function extractContentAndSaveToIndex($files) {
		if(is_array($files) && count($files)) {
			foreach($files as $file) {
				if($this->fileInfo->setFile($file)) {
					if(($content = $this->getFileContent($file))) {
						$this->storeToIndex($file, $content);
					} else continue;
				} else continue;
			}
		}
	}


	/**
	 * get filecontent of allowed extensions
	 *
	 * @param string $file
	 * @return mixed false or fileinformations as array
	 */
	public function getFileContent($file) {
		// we can continue only when given file is a true file and not a directory or what ever
		if($this->fileInfo->getIsFile()) {
			$className = 'tx_kesearch_indexer_filetypes_' . $this->fileInfo->getExtension();

			// check if class exists
			if(class_exists($className)) {
				// make instance
				$fileObj = t3lib_div::makeInstance($className);

				// check if new object has interface implemented
				if($fileObj instanceof tx_kesearch_indexer_filetypes) {
					// now we can execute the method of our new object
					return $fileObj->getContent($file);
				} else return false;
			} else return false;
		} else return false;
	}


	/**
	 * get a unique hash for current file
	 * this is needed for a faster check if record allready exists in indexer table
	 *
	 * @return string A 25 digit MD5 hash value of current file and last modification time
	 */
	public function getUniqueHashForFile() {
		$path = $this->fileInfo->getPath();
		$file = $this->fileInfo->getName();
		$mtime = $this->fileInfo->getModificationTime();

		return md5($path . $file . '-' . $mtime);
	}


	public function storeToIndex($file, $content) {
		$additionalFields = array(
			'sortdate' => $this->fileInfo->getModificationTime(),
			'orig_uid' => 0,
			'orig_pid' => 0,
			'directory' => $this->fileInfo->getPath(),
			'hash' => $this->getUniqueHashForFile()
		);

		//hook for custom modifications of the indexed data, e. g. the tags
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFileIndexEntry'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFileIndexEntry'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->modifyFileIndexEntry($file, $content, additionalFields);
			}
		}

		// store record in index table
		$this->pObj->storeInIndex(
			$this->indexerConfig['storagepid'],    // storage PID
			$this->fileInfo->getName(),            // page title
			'file',                                // content type
			1,                                     // target PID: where is the single view?
			$content,                              // indexed content, includes the title (linebreak after title)
			$tags,                                 // tags
			'',                                    // typolink params for singleview
			'',                                    // abstract
			0,                                     // language uid
			0,                                     // starttime
			0,                                     // endtime
			0,                                     // fe_group
			false,                                 // debug only?
			$additionalFields                      // additional fields added by hooks
		);
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_file.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_file.php']);
}
?>