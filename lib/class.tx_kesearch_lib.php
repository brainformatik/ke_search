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

require_once(PATH_tslib.'class.tslib_pibase.php');
require_once(t3lib_extMgm::extPath('ke_search').'lib/class.tx_kesearch_db.php');
require_once(t3lib_extMgm::extPath('ke_search').'pi1/class.tx_kesearch_div.php');

/**
 * Plugin 'Faceted search - searchbox and filters' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_lib extends tslib_pibase {
	var $prefixId            = 'tx_kesearch_pi1';		// Same as class name
	var $extKey              = 'ke_search';	// The extension key.

	var $sword               = ''; // cleaned searchword (karl-heinz => karl heinz)
	var $swords              = ''; // searchwords as array
	var $wordsAgainst        = ''; // searchphrase for boolean mode (+karl* +heinz*)
	var $tagsAgainst         = ''; // tagsphrase for boolean mode (+#category_213# +#city_42#)
	var $scoreAgainst        = ''; // searchphrase for score/non boolean mode (karl heinz)
	var $isEmptySearch       = true; // true if no searchparams given; otherwise false

	var $templateFile        = ''; // Template file
	var $templateCode        = ''; // content of template file

	var $startingPoints      = 0; // comma seperated list of startingPoints
	var $firstStartingPoint  = 0; // comma seperated list of startingPoints
	var $conf                = array(); // FlexForm-Configuration
	var $extConf             = array(); // Extension-Configuration
	var $numberOfResults     = 0; // count search results
	var $indexToUse          = ''; // it's for 'USE INDEX ($indexToUse)' to speed up queries
	var $tagsInSearchResult  = false; // contains all tags of current search result
	var $preselectedFilter   = array(); // preselected filters by flexform
	var $filtersFromFlexform = array(); // array with filter-uids as key and whole data as value
	var $hasTooShortWords    = false; // contains a boolean value which represents if there are too short words in the searchstring

 	/**
	* @var tx_xajax
	*/
	var $xajax;

	/**
	* @var tx_kesearch_db
	*/
	var $db;

	/**
	* @var tx_kesearch_div
	*/
	var $div;

	/**
	* @var user_kesearchpremium
	*/
	var $user_kesearchpremium;


	/**
	 * Initializes flexform, conf vars and some more
	 *
	 * @return nothing
	 */
	public function init() {
		// get some helper functions
		$this->div = t3lib_div::makeInstance('tx_kesearch_div', $this);

		// set start of query timer
		if(!$GLOBALS['TSFE']->register['ke_search_queryStartTime']) $GLOBALS['TSFE']->register['ke_search_queryStartTime'] = t3lib_div::milliseconds();

		$this->moveFlexFormDataToConf();

		if(!empty($this->conf['loadFlexformsFromOtherCE'])) {
			$data = $this->pi_getRecord('tt_content', intval($this->conf['loadFlexformsFromOtherCE']));
			$this->cObj->data = $data;
			$this->moveFlexFormDataToConf();
		}

		// clean piVars
		$this->piVars = $this->div->cleanPiVars($this->piVars);

		// get preselected filter from rootline
		$this->getFilterPreselect();

		// add stdWrap properties to each config value
		foreach($this->conf as $key => $value) {
			$this->conf[$key] = $this->cObj->stdWrap($value, $this->conf[$key . '.']);
		}

		// set some default values (this part have to be after stdWrap!!!)
		if(!$this->conf['resultPage']) {
			if($this->cObj->data['pid']) {
				$this->conf['resultPage'] = $this->cObj->data['pid'];
			} else {
				$this->conf['resultPage'] = $GLOBALS['TSFE']->id;
			}
		}
		if(!isset($this->piVars['page'])) $this->piVars['page'] = 1;

		// hook: modifyFlexFormData
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFlexFormData'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFlexFormData'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->modifyFlexFormData($this->conf, $this->cObj, $this->piVars);
			}
		}

		// prepare database object
		$this->db = t3lib_div::makeInstance('tx_kesearch_db', $this);

		// set startingPoints
		$this->startingPoints = $this->div->getStartingPoint();

		// get extension configuration array
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
		// sphinx has problems with # in query string.
		// so you have the possibility to change # against some other char
		if(t3lib_extMgm::isLoaded('ke_search_premium')) {
			$extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search_premium']);
			if(!$extConf['prePostTagChar']) $extConf['prePostTagChar'] = '_';
			$this->extConf['prePostTagChar'] = $extConf['prePostTagChar'];
		} else {
			// MySQL has problems also with #
			// but we have wrapped # with " and it works.
			$this->extConf['prePostTagChar'] = '#';
		}
		$this->extConf['multiplyValueToTitle'] = ($this->extConf['multiplyValueToTitle']) ? $this->extConf['multiplyValueToTitle'] : 1;
		$this->extConf['searchWordLength'] = ($this->extConf['searchWordLength']) ? $this->extConf['searchWordLength'] : 4;

		// get html template
		$this->templateFile = $this->conf['templateFile'] ? $this->conf['templateFile'] : t3lib_extMgm::siteRelPath($this->extKey).'res/template_pi1.tpl';
		$this->templateCode = $this->cObj->fileResource($this->templateFile);

		// get first startingpoint
		$this->firstStartingPoint = $this->div->getFirstStartingPoint($this->startingPoints);

		// build words searchphrase
		$searchWordInformation = $this->div->buildSearchphrase();
		$this->sword = $searchWordInformation['sword'];
		$this->swords = $searchWordInformation['swords'];
		$this->wordsAgainst = $searchWordInformation['wordsAgainst'];
		$this->tagsAgainst = $searchWordInformation['tagsAgainst'];
		$this->scoreAgainst = $searchWordInformation['scoreAgainst'];

		$this->isEmptySearch = $this->isEmptySearch();

		// chooseBestIndex is only needed for MySQL-Search. Not for Sphinx
		if(!t3lib_extMgm::isLoaded('ke_search_premium')) {
			// precount results to find the best index
			$this->db->chooseBestIndex($this->wordsAgainst, $this->tagsAgainst);
		}

		// get css file
		$cssFile = $this->conf['cssFile'] ? $this->conf['cssFile'] : t3lib_extMgm::siteRelPath($this->extKey) . 'res/ke_search_pi1.css';
		$GLOBALS['TSFE']->additionalHeaderData[$this->prefixId . '_css'] = '<link rel="stylesheet" type="text/css" href="' . $cssFile . '" />';
	}


	/**
	 * Move all FlexForm data of current record to conf array
	 */
	public function moveFlexFormDataToConf() {
		// don't move this to init
		$this->pi_initPIflexForm();

		$piFlexForm = $this->cObj->data['pi_flexform'];
		if(is_array($piFlexForm['data'])) {
			foreach($piFlexForm['data'] as $sheetKey => $sheet) {
				foreach($sheet as $lang) {
					foreach($lang as $key => $value) {
						// delete current conf value from conf-array when FF-Value differs from TS-Conf and FF-Value is not empty
						$value = $this->fetchConfigurationValue($key, $sheetKey);
						if($this->conf[$key] != $value && !empty($value)) {
							unset($this->conf[$key]);
							$this->conf[$key] = $this->fetchConfigurationValue($key, $sheetKey);
						}
					}
				}
			}
		}
	}


	/*
	 * function initOnclickActions
	 */
	public function initOnclickActions() {

		switch ($this->conf['renderMethod']) {

			// AJAX after reload version
			case 'ajax_after_reload':

				// set pagebrowser onclick
				$this->onclickPagebrowser = 'pagebrowserAction(); ';

				// $this->onclickFilter = 'this.form.submit();';
				$this->onclickFilter = 'document.getElementById(\'pagenumber\').value=1; document.getElementById(\'xajax_form_kesearch_pi1\').submit();';

				break;

			// STATIC version
			case 'static':
				return;
				break;
		}
	}


	/*
	 * function getSearchboxContent
	 */
	public function getSearchboxContent() {

		// get main template code
		$content = $this->cObj->getSubpart($this->templateCode,'###SEARCHBOX_STATIC###');

		// set page = 1 if not set yet
		if (!$this->piVars['page']) $this->piVars['page'] = 1;
		$content = $this->cObj->substituteMarker($content,'###HIDDEN_PAGE_VALUE###',$this->piVars['page']);

		// submit
		$content = $this->cObj->substituteMarker($content,'###SUBMIT_VALUE###',$this->pi_getLL('submit'));

		// searchword input value
		$searchWordValue = trim($this->piVars['sword']);

		if (!empty($searchWordValue) && $searchWordValue != $this->pi_getLL('searchbox_default_value')) {
			// no searchword entered
			$this->swordValue = $this->piVars['sword'] ? $this->div->removeXSS($this->piVars['sword']) : '';
			$searchboxFocusJS = '';
		// } else if ($this->conf['renderMethod'] != 'static') {
		} else {
			// do not use when static mode is called

			// get default value from LL
			$this->swordValue = $this->pi_getLL('searchbox_default_value');

			// set javascript for resetting searchbox value
			$searchboxFocusJS = ' searchboxFocus(this);  ';

		}
		$content = $this->cObj->substituteMarker($content,'###SWORD_VALUE###', $this->swordValue);
		$content = $this->cObj->substituteMarker($content,'###SWORD_ONFOCUS###', $searchboxFocusJS);

		// set onsubmit action
		if ($this->conf['renderMethod'] != 'static') {
			$onSubmitMarker = 'onsubmit="document.getElementById(\'pagenumber\').value=1;"';
		} else {
			$onSubmitMarker = '';
		}
		$content = $this->cObj->substituteMarker($content,'###ONSUBMIT###', $onSubmitMarker);

		// get filters
		$content = $this->cObj->substituteMarker($content, '###FILTER###', $this->renderFilters());

		// set form action pid
		$content = $this->cObj->substituteMarker($content,'###FORM_TARGET_PID###', $this->conf['resultPage']);
		// set form action
		$content = $this->cObj->substituteMarker($content,'###FORM_ACTION###', t3lib_div::getIndpEnv('TYPO3_SITE_URL').'index.php');

		// set other hidden fields
		$hiddenFieldsContent = '';
		// language parameter
		$lParam = t3lib_div::_GET('L');
		if (isset($lParam)) {
			$hiddenFieldValue = intval($lParam);
			$hiddenFieldsContent .= '<input type="hidden" name="L" value="'.$hiddenFieldValue.'" />';
		}
		// mountpoint parameter
		$mpParam = t3lib_div::_GET('MP');
		if (isset($mpParam)) {
			$hiddenFieldValue = htmlentities($mpParam);
			$hiddenFieldsContent .= '<input type="hidden" name="MP" value="'.$hiddenFieldValue.'" />';
		}
		$content = $this->cObj->substituteMarker($content,'###HIDDENFIELDS###', $hiddenFieldsContent);

		// set reset link
		unset($linkconf);
		$linkconf['parameter'] = $this->conf['resultPage'];
		$resetUrl = $this->cObj->typoLink_URL($linkconf);
		$resetLink = '<a href="'.$resetUrl.'" class="resetButton"><span>'.$this->pi_getLL('reset_button').'</span></a>';

		// init onDomReadyAction
		$this->initDomReadyAction();

		$content = $this->cObj->substituteMarker($content,'###RESET###',$resetLink);

		// type param
		$typeParam = t3lib_div::_GP('type');
		if ($typeParam) {
			$hiddenFieldValue = intval($typeParam);
			$typeContent = $this->cObj->getSubpart($this->templateCode,'###SUB_PAGETYPE###');
			$typeContent = $this->cObj->substituteMarker($typeContent,'###PAGETYPE###',$typeParam);
		} else $typeContent = '';
		$content = $this->cObj->substituteSubpart ($content, '###SUB_PAGETYPE###', $typeContent, $recursive=1);

		return $content;
	}


	/*
	 * function renderFilters()
	 */
	public function renderFilters() {

		$tagChar = $this->extConf['prePostTagChar'];
		// get filters from db
		$this->filters = $this->getFiltersFromFlexform();

		if (!empty($this->conf['filters'])) {
			$filterList = explode(',', $this->conf['filters']);

			foreach ($filterList as $key => $filterUid) {

				$options = array();

				// current filter has options
				if (!empty($this->filters[$filterUid]['options'])) {

					// get filter options
					$fields = '*';
					$table = 'tx_kesearch_filteroptions';
					$where = 'FIND_IN_SET(uid, "'.$GLOBALS['TYPO3_DB']->quoteStr($this->filters[$filterUid]['options'],'tx_kesearch_index').'")';
					$where .= ' AND pid in ('.$GLOBALS['TYPO3_DB']->quoteStr($this->startingPoints,'tx_kesearch_index').')';
					$where .= $this->cObj->enableFields($table);
					$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='','FIND_IN_SET(uid, "'.$GLOBALS['TYPO3_DB']->quoteStr($this->filters[$filterUid]['options'],'tx_kesearch_index').'")',$limit='');

					while($option = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
						// Perform overlay on each record
						if(is_array($option) && $GLOBALS['TSFE']->sys_language_contentOL) {
							$option = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
								'tx_kesearch_filteroptions',
								$option,
								$GLOBALS['TSFE']->sys_language_content,
								$GLOBALS['TSFE']->sys_language_contentOL
							);
						}

						// check filter availability?
						if($this->conf['checkFilterCondition'] != 'none') {
							if($this->checkIfTagMatchesRecords($option['tag'], $this->conf['checkFilterCondition'], $filterUid)) {
								// process check in condition to other filters or without condition

								// selected / preselected?
								$selected = 0;

								if($this->piVars['filter'][$filterUid] == $option['tag']) {
									$selected = 1;
								} elseif(is_array($this->piVars['filter'][$filterUid])) {
									if(t3lib_div::inArray($this->piVars['filter'][$filterUid], $option['tag'])) {
										$selected = 1;
									}
								} elseif(!isset($this->piVars['filter'][$filterUid]) && !is_array($this->piVars['filter'][$filterUid])) {
									if (is_array($this->preselectedFilter) && in_array($option['tag'], $this->preselectedFilter)) {
										$selected = 1;
										$this->piVars['filter'][$filterUid] = $option['tag'];
									}
								}

								$options[$option['uid']] = array(
									'title' => $option['title'],
									'value' => $option['tag'],
									'results' => $this->tagsInSearchResult[$tagChar . $option['tag'] . $tagChar],
									'selected' => $selected,
								);
							}
						} else {
							// do not process check; show all filters
							$options[$option['uid']] = array(
								'title' => $option['title'],
								'value' => $option['tag'],
								'selected' => $selected,
							);
						}
					}
				}

				// hook for modifying filter options
				if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptionsArray'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptionsArray'] as $_classRef) {
						$_procObj = & t3lib_div::getUserObj($_classRef);
						$options = $_procObj->modifyFilterOptionsArray($filterUid, $options, $this);
					}
				}

				// render "wrap"
				if ($this->filters[$filterUid]['wrap']) {
					$wrap = t3lib_div::trimExplode('|', $this->filters[$filterUid]['wrap']);
				} else {
					$wrap = array(
						0 => '',
						1 => ''
					);
				}

				// get subparts corresponding to render type
				switch ($this->filters[$filterUid]['rendertype']) {

					case 'select':
					default:
						$filterContent .= $wrap[0] . $this->renderSelect($filterUid, $options) . $wrap[1];
						break;

					case 'list':
						$filterContent .= $wrap[0] . $this->renderList($filterUid, $options) . $wrap[1];
						break;

					case 'checkbox':
						$filterContent .= $wrap[0] . $this->renderCheckbox($filterUid, $options) . $wrap[1];
						break;

					case 'textlinks':
						$textLinkObj = t3lib_div::makeInstance('tx_kesearch_lib_filters_textlinks', $this);
						$filterContent .= $wrap[0] . $textLinkObj->renderTextlinks($filterUid, $options) . $wrap[1];
						break;
					// use custom render code
					default:
							// hook for custom filter renderer
						$customFilterContent = '';
						if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customFilterRenderer'])) {
							foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customFilterRenderer'] as $_classRef) {
								$_procObj = & t3lib_div::getUserObj($_classRef);
								$customFilterContent .= $_procObj->customFilterRenderer($filterUid, $options, $this);
							}
						}
						if ($customFilterContent) {
							$filterContent .= $wrap[0] . $customFilterContent . $wrap[1];
						}
						break;
				}
			}
		}
		return $filterContent;
	}


	/*
	 * function renderSelect
	 * @param $arg
	 */
	public function renderSelect($filterUid, $options) {

		$filterSubpart = '###SUB_FILTER_SELECT###';
		$optionSubpart = '###SUB_FILTER_SELECT_OPTION###';

		// add standard option "all"
		$optionsContent .= $this->cObj->getSubpart($this->templateCode,$optionSubpart);
		$optionsContent = $this->cObj->substituteMarker($optionsContent,'###TITLE###', $this->filters[$filterUid]['title']);
		$optionsContent = $this->cObj->substituteMarker($optionsContent,'###VALUE###', '');
		$optionsContent = $this->cObj->substituteMarker($optionsContent,'###SELECTED###','');
		$optionsContent = $this->cObj->substituteMarker($optionsContent,'###CSS_CLASS###', 'class="label" ' );

		// loop through options
		if (is_array($options)) {
			foreach ($options as $key => $data) {
				$optionsContent .= $this->cObj->getSubpart($this->templateCode, $optionSubpart);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###ONCLICK###', $this->onclickFilter);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###TITLE###', $data['title']);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###VALUE###', $data['value']);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###SELECTED###', $data['selected'] ? ' selected="selected" ' : '');
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###CSS_CLASS###', ' ' );
				$optionsCount++;
			}
		}

		// modify filter options by hook
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$optionsContent .= $_procObj->modifyFilterOptions(
					$filterUid,
					$optionsContent,
					$optionsCount,
					$this
				);
			}
		}

		// fill markers
		$filterContent = $this->cObj->getSubpart($this->templateCode, $filterSubpart);
		$filterContent = $this->cObj->substituteSubpart ($filterContent, $optionSubpart, $optionsContent, $recursive=1);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $this->filters[$filterUid]['title']);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERNAME###', 'tx_kesearch_pi1[filter]['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERID###', 'filter['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###DISABLED###', $optionsCount > 0 ? '' : ' disabled="disabled" ');

		// set onclick actions for different rendering methods
		if ($this->conf['renderMethod'] == 'static') {
			$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCHANGE###', '');
		} else {
			$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCHANGE###', $this->onclickFilter);
		}

		return $filterContent;
	}

	/*
	 * function renderList
	 * @param $arg
	 */
	public function renderList($filterUid, $options) {
		// onClick don't works in static mode
		if($this->conf['renderMethod'] == 'static') {
			return $this->renderSelect($filterUid, $options);
		}

		$filterSubpart = '###SUB_FILTER_LIST###';
		$optionSubpart = '###SUB_FILTER_LIST_OPTION###';

		$optionsCount = 0;

		// loop through options
		if (is_array($options)) {
			foreach ($options as $key => $data) {

				$onclick = '';
				$tempField = strtolower(t3lib_div::removeXSS($this->piVars['orderByField']));
				$tempDir = strtolower(t3lib_div::removeXSS($this->piVars['orderByDir']));
				if($tempField != '' && $tempDir != '') {
					$onclick = 'setOrderBy(' . $tempField . ', ' . $tempDir . ');';
				}
				$onclick = $onclick . ' document.getElementById(\'filter['.$filterUid.']\').value=\''.$data['value'].'\'; ';
				$onclick .= ' document.getElementById(\'pagenumber\').value=\'1\'; ';
				$onclick .= $this->onclickFilter;

				$optionsContent .= $this->cObj->getSubpart($this->templateCode, $optionSubpart);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###ONCLICK###', $onclick);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###TITLE###', $data['title']);
				$cssClass = 'option ';
				$cssClass .= $data['selected'] ? 'selected' : '';
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONCSSCLASS###', $cssClass);

				$optionsCount++;

			}
		}

		// modify filter options by hook
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$optionsContent .= $_procObj->modifyFilterOptions(
					$filterUid,
					$optionsContent,
					$optionsCount,
					$this
				);
			}
		}

		// fill markers
		$filterContent = $this->cObj->getSubpart($this->templateCode, $filterSubpart);
		$filterContent = $this->cObj->substituteSubpart ($filterContent, $optionSubpart, $optionsContent);

		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $this->filters[$filterUid]['title']);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $this->filters[$filterUid]['title']);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERNAME###', 'tx_kesearch_pi1[filter]['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERID###', 'filter['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCHANGE###', $this->onclickFilter);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCLICK_RESET###', $this->onclickFilter);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###DISABLED###', $optionsCount > 0 ? '' : ' disabled="disabled" ');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###VALUE###', $this->piVars['filter'][$filterUid]);

		// bullet
		unset($imageConf);
		$bulletSrc = $this->filters[$filterUid]['expandbydefault'] ? 'list-head-expanded.gif' : 'list-head-closed.gif';
		$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/'.$bulletSrc;
		$imageConf['params'] = 'class="bullet" id="bullet_filter['.$filterUid.']" ';
		$filterContent = $this->cObj->substituteMarker($filterContent,'###BULLET###', $this->cObj->IMAGE($imageConf));

		// expand by default ?
		$class = $this->filters[$filterUid]['expandbydefault'] || !empty($this->piVars['filter'][$filterUid]) || $this->conf['renderMethod'] == 'static' ? 'expanded' : 'closed';
		$filterContent = $this->cObj->substituteMarker($filterContent,'###LISTCSSCLASS###', $class);

		// special css class (outer options list for scrollbox)
		$filterContent = $this->cObj->substituteMarker($filterContent,'###SPECIAL_CSS_CLASS###', $this->filters[$filterUid]['cssclass'] ? $this->filters[$filterUid]['cssclass'] : '');

		return $filterContent;

	}


	/**
	 * renders the filters which are in checkbox mode
	 *
	 * @param $filterUid UID of the filter which we have to render
	 * @param $options contains all options which are found in the seach result
	 * @return $string HTML of rendered checkbox filter
	 */
	public function renderCheckbox($filterUid, $options) {
		// getFiltersFromFlexform is much faster than an additional SQL-Query
		$filters = $this->getFiltersFromFlexform();
		$allOptionsOfCurrentFilter = $this->getFilterOptions($filters[$filterUid]['options']);

		// getSubparts
		$template['filter'] = $this->cObj->getSubpart($this->templateCode, '###SUB_FILTER_CHECKBOX###');
		$template['options'] = $this->cObj->getSubpart($this->templateCode, '###SUB_FILTER_CHECKBOX_OPTION###');

		// loop through options
		if(is_array($allOptionsOfCurrentFilter)) {
			foreach($allOptionsOfCurrentFilter as $key => $data) {
				$checkBoxParams['selected'] = '';
				$checkBoxParams['disabled'] = '';
				$isOptionInOptionArray = 0;

				// check if current option (of searchresults) is in array of all possible options
				foreach($options as $optionKey => $optionValue) {
					if(is_array($options[$optionKey]) && t3lib_div::inArray($options[$optionKey], $data['title'])) {
						$isOptionInOptionArray = 1;
						break;
					}
				}

				// if option is in optionArray, we have to mark the checkboxes
				if($isOptionInOptionArray) {
					// if user has selected a checkbox it must be selected on the resultpage, too.
					if($this->piVars['filter'][$filterUid][$key]) {
						$checkBoxParams['selected'] = 'checked="checked"';
					}

					// mark all checkboxes if set and no search string was given
					if($this->isEmptySearch && $this->filters[$filterUid]['markAllCheckboxes']) {
						$checkBoxParams['selected'] = 'checked="checked"';
					}

					// always mark checkboxes which are preselected
					if($this->preselectedFilter[$filterUid][$data['uid']]) {
						$checkBoxParams['selected'] = 'checked="checked"';
					}
				} else { // if an option was not found in the search results
					$checkBoxParams['disabled'] = 'disabled="disabled"';
				}

				$markerArray['###TITLE###'] = $data['title'];
				$markerArray['###VALUE###'] = $data['tag'];
				$markerArray['###OPTIONKEY###'] = $key;
				$markerArray['###OPTIONID###'] = 'filter[' . $filterUid . '][' . $key . ']';
				$markerArray['###OPTIONCSSCLASS###'] = 'optionCheckBox optionCheckBox' . $key;
				$markerArray['###OPTIONNAME###'] = 'optionCheckBox' . $filterUid;
				$markerArray['###OPTIONSELECT###'] = $checkBoxParams['selected'];
				$markerArray['###OPTIONDISABLED###'] = $checkBoxParams['disabled'];
				$contentOptions .= $this->cObj->substituteMarkerArray($template['options'], $markerArray);
			}
			$optionsCount = count($allOptionsOfCurrentFilter);
		}

		// modify filter options by hook
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$contentOptions .= $_procObj->modifyFilterOptions(
					$filterUid,
					$contentOptions,
					$optionsCount,
					$this
				);
			}
		}

		unset($markerArray);

		// render filter
		$contentFilters = $this->cObj->substituteSubpart($template['filter'], '###SUB_FILTER_CHECKBOX_OPTION###', $contentOptions);

		// get title
		$filterTitle = $this->filters[$filterUid]['title'];

		// get bullet image
		$bulletSrc = $this->filters[$filterUid]['expandbydefault'] ? 'list-head-expanded.gif' : 'list-head-closed.gif';
		$bulletConf['file'] = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/' . $bulletSrc;
		$bulletConf['params'] = 'class="bullet" id="bullet_filter['.$filterUid.']" ';
		$bulletImage = $this->cObj->IMAGE($bulletConf);

		/**
		 * if "expand by default" is set
		 * if value in current filter is not empty
		 * if we are in static mode
		 */
		if($this->filters[$filterUid]['expandbydefault'] || !empty($this->piVars['filter'][$filterUid]) || $this->conf['renderMethod'] == 'static') {
			$class = 'expanded';
		} else $class = 'closed';

		// fill markers
		$markerArray['###LABEL_ALL###'] = $this->pi_getLL('label_all');
		$markerArray['###FILTERTITLE###'] = $filterTitle;
		$markerArray['###FILTERNAME###'] = 'tx_kesearch_pi1[filter]['.$filterUid.']';
		$markerArray['###FILTERID###'] = 'filter['.$filterUid.']';
		$markerArray['###FILTER_UID###'] = $filterUid;
		$markerArray['###ONCHANGE###'] = $this->onclickFilter;
		$markerArray['###ONCLICK_RESET###'] = $this->onclickFilter;
		$markerArray['###DISABLED###'] = $optionsCount > 0 ? '' : ' disabled="disabled" ';
		$markerArray['###BULLET###'] = $bulletImage;
		$markerArray['###LISTCSSCLASS###'] = $class;
		$markerArray['###SPECIAL_CSS_CLASS###'] = $this->filters[$filterUid]['cssclass'] ? $this->filters[$filterUid]['cssclass'] : '';
		$contentFilters = $this->cObj->substituteMarkerArray($contentFilters, $markerArray);

		return $contentFilters;
	}


	public function renderTextlinks($filterUid, $options) {
		// getFiltersFromFlexform is much faster than an additional SQL-Query
		$filters = $this->getFiltersFromFlexform();
		$allOptionsOfCurrentFilter = $this->getFilterOptions($filters[$filterUid]['options']);
		$allOptionsOfCurrentFilter = t3lib_div::array_merge_recursive_overrule((array)$allOptionsOfCurrentFilter, (array)$options);
		$allOptionKeys = array_keys($allOptionsOfCurrentFilter);

		// sorting multidimensional array
		foreach((array)$allOptionsOfCurrentFilter as $key => $array) {
			$results[$key] = $array['results'];
			$tags[$key] = $array['tag'];
		}
		array_multisort($results, SORT_DESC, SORT_NUMERIC, $tags, SORT_ASC, SORT_STRING, $allOptionKeys, SORT_DESC, SORT_NUMERIC, $allOptionsOfCurrentFilter);

		// after multisort all keys are 0,1,2,3. So we have to restore our old keys
		$allOptionsOfCurrentFilter = array_combine($allOptionKeys, array_values($allOptionsOfCurrentFilter));

		// getSubparts
		$template['filter'] = $this->cObj->getSubpart($this->templateCode, '###SUB_FILTER_TEXTLINKS###');
		$template['options'] = $this->cObj->getSubpart($this->templateCode, '###SUB_FILTER_TEXTLINK_OPTION###');

		// loop through options
		if(is_array($allOptionsOfCurrentFilter)) {
			$counter = 0;
			$countActives = 0;
			foreach($allOptionsOfCurrentFilter as $key => $data) {
				// hook: modifyOptionForTextlinks
				if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyOptionForTextlinks'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyOptionForTextlinks'] as $_classRef) {
						$_procObj = &t3lib_div::getUserObj($_classRef);
						$_procObj->modifyOptionForTextlinks($key, $data, $this->conf, $this);
					}
				}

				$isOptionInOptionArray = 0;

				// check if current option (of searchresults) is in array of all possible options
				foreach((array)$options as $optionKey => $optionValue) {
					if(is_array($options[$optionKey]) && t3lib_div::inArray($options[$optionKey], $data['tag'])) {
						$isOptionInOptionArray = 1;
						break;
					}
				}

				// if multi is set, then more than one entry can be selected
				if($this->piVars['multi'] && $this->piVars['filter'][$filterUid][$key]) {
					if($isOptionInOptionArray) {
						$countActives++;
					}
					$markerArray['###TEXTLINK###'] = $data['title'];
					$markerArray['###CLASS###'] = 'active';
					$hiddenFields .= '<input type="hidden" name="tx_kesearch_pi1[filter][' . $filterUid . '][' . $key . ']" id="tx_kesearch_pi1[filter][' . $filterUid . '][' . $key . ']" value="' . $data['tag'] . '" />';					$contentOptions .= $this->cObj->substituteMarkerArray($template['options'], $markerArray);
					continue;
				}
				// if option is in optionArray, we have to mark the selection
				if($isOptionInOptionArray && $counter < (int)$filters[$filterUid]['amount']) {
					// if user has clicked on a link it must be selected on the resultpage, too.
					if($this->piVars['filter'][$filterUid][$key]) {
						$countActives++;
						$markerArray['###CLASS###'] = 'active';
						$markerArray['###TEXTLINK###'] = $options[$optionKey]['title'];
						$hiddenFields .= '<input type="hidden" name="tx_kesearch_pi1[filter][' . $filterUid . '][' . $key . ']" id="tx_kesearch_pi1[filter][' . $filterUid . '][' . $key . ']" value="' . $data['tag'] . '" />';
						$contentOptions .= $this->cObj->substituteMarkerArray($template['options'], $markerArray);
					}
					if(empty($this->piVars['filter'][$filterUid])) {
						$markerArray['###CLASS###'] = 'normal';
						$markerArray['###TEXTLINK###'] = $this->cObj->typoLink(
							$options[$optionKey]['title'] . '<span> (' . $options[$optionKey]['results'] . ')</span>',
							array(
								'parameter' => $this->conf['resultPage'],
								'additionalParams' => '&tx_kesearch_pi1[filter][' . $filterUid . '][' . $key . ']=' . $data['tag'] . '&tx_kesearch_pi1[page]=1' . $this->addParam,
								'addQueryString' => 1,
								'addQueryString.' => array(
									'exclude' => 'uid,sortByField,sortByDir'
								)
							)
						);
						$counter++;
						$contentOptions .= $this->cObj->substituteMarkerArray($template['options'], $markerArray);
					}
				}
			}
			$optionsCount = count($allOptionsOfCurrentFilter);
		}

		// modify filter options by hook
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptions'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$contentOptions .= $_procObj->modifyFilterOptions(
					$filterUid,
					$contentOptions,
					$optionsCount,
					$this
				);
			}
		}

		unset($markerArray);

		// render filter
		$contentFilters = $this->cObj->substituteSubpart(
			$template['filter'],
			'###SUB_FILTER_TEXTLINK_OPTION###',
			$contentOptions
		);

		// get title
		$filterTitle = $this->filters[$filterUid]['title'];
		$this->filters[$filterUid]['target_pid'] = ($this->filters[$filterUid]['target_pid']) ? $this->filters[$filterUid]['target_pid'] : $this->conf['resultPage'];

		// fill markers
		$markerArray['###FILTERTITLE###'] = $filterTitle;
		$markerArray['###HIDDEN_FIELDS###'] = $hiddenFields;

		if(count($this->piVars['filter']) <= 1) {
			$exclude = 'tx_kesearch_pi1[page],tx_kesearch_pi1[multi],tx_kesearch_pi1[filter][' . $filterUid . ']';
		} else $exclude = 'tx_kesearch_pi1[page],tx_kesearch_pi1[filter][' . $filterUid . ']';

		if($countActives) {
			$markerArray['###LINK_MULTISELECT###'] = '';
			$markerArray['###LINK_RESET_FILTER###'] = $this->cObj->typoLink(
				$this->pi_getLL('reset_filter'),
				array(
					'parameter' => $this->conf['resultPage'],
					'addQueryString' => 1,
					'addQueryString.' => array(
						'exclude' => $exclude
					)
				)
			);
		} else {
			// check if there is a special translation for current filter
			$linkTextMore = $this->pi_getLL('linktext_more_' . $filterUid, $this->pi_getLL('linktext_more'));
			$markerArray['###LINK_MULTISELECT###'] = $this->cObj->typoLink(
				sprintf($linkTextMore, $filterTitle),
				array(
					'parameter' => $this->filters[$filterUid]['target_pid'],
					'addQueryString' => 1,
					'addQueryString.' => array(
						'exclude' => 'id,tx_kesearch_pi1[page]'
					)
				)
			);
			$markerArray['###LINK_RESET_FILTER###'] = '';
		}

		$contentFilters = $this->cObj->substituteMarkerArray($contentFilters, $markerArray);

		return $contentFilters;
	}


	/*
	 * function checkIfFilterMatchesRecords
	 */
	public function checkIfTagMatchesRecords($tag, $mode='multi', $filterId) {
		$tagChar = $this->extConf['prePostTagChar'];
		// get all tags of current searchresult
		if(!is_array($this->tagsInSearchResult)) {
			// conv boolean to array
			$this->tagsInSearchResult = array();

			// build words search phrase
			$searchWordInformation = $this->div->buildSearchphrase();
			$this->sword = $searchWordInformation['sword'];
			$this->swords = $searchWordInformation['swords'];
			$this->wordsAgainst = $searchWordInformation['wordsAgainst'];

			// get filter list
			$filterList = explode(',', $this->conf['filters']);

			// extend against-clause for multi check (in condition with other selected filters)
			if ($mode == 'multi' && is_array($filterList)) {
				$tagsAgainst = '';
				// get all filteroptions from URL
				foreach ($filterList as $key => $foreignFilterId) {
					if(is_array($this->piVars['filter'][$foreignFilterId])) {
						foreach($this->piVars['filter'][$foreignFilterId] as $optionKey => $optionValue) {
							if(!empty($this->piVars['filter'][$foreignFilterId][$optionKey])) {
								// Don't add a "+", because we are here in checkbox mode
								$tagsAgainst .= ' "' . $tagChar . $GLOBALS['TYPO3_DB']->quoteStr($this->piVars['filter'][$foreignFilterId][$optionKey], 'tx_kesearch_index') . $tagChar . '" ';
							}
						}
					} else {
						if(!empty($this->piVars['filter'][$foreignFilterId])) {
							$tagsAgainst .= ' +"' . $tagChar . $GLOBALS['TYPO3_DB']->quoteStr($this->piVars['filter'][$foreignFilterId], 'tx_kesearch_index') . $tagChar . '" ';
						}
					}
				}
			}
			$tagsAgainst = $this->div->removeXSS($tagsAgainst);

			// chooseBestIndex is only needed for MySQL-Search. Not for Sphinx
			if(!t3lib_extMgm::isLoaded('ke_search_premium')) {
				$this->db->chooseBestIndex($this->wordsAgainst, $tagsAgainst);
			}

			$fields = 'uid';
			$table = 'tx_kesearch_index';
			$where = '1=1';
			$countMatches = 0;
			if($tagsAgainst) {
				$where .= ' AND MATCH (tags) AGAINST (\''.$tagsAgainst.'\' IN BOOLEAN MODE) ';
				$countMatches++;
			}
			if(count($this->swords)) {
				$where .= ' AND MATCH (content) AGAINST (\''.$this->wordsAgainst.'\' IN BOOLEAN MODE) ';
				$countMatches++;
			}

			// add language
			$lang = intval($GLOBALS['TSFE']->sys_language_uid);
			$where .= ' AND language = ' . $lang . ' ';

			$where .= $this->cObj->enableFields($table);

			// which index to use
			if($countMatches == 2) {
				$index = ' USE INDEX (' . $this->indexToUse . ')';
			} else $index = '';

			$query = $GLOBALS['TYPO3_DB']->SELECTquery(
				'uid, REPLACE(tags, "' . $tagChar . $tagChar . '", "' . $tagChar . ',' . $tagChar .'") as tags',
				'tx_kesearch_index' . $index,
				$where,
				'','',''
			);

			$tagChar = $this->extConf['prePostTagChar'];

			if(t3lib_extMgm::isLoaded('ke_search_premium') && !$this->isEmptySearch) {
				require_once(t3lib_extMgm::extPath('ke_search_premium') . 'class.user_kesearchpremium.php');
				$sphinx = t3lib_div::makeInstance('user_kesearchpremium');
				$sphinx->setLimit(0, 10000, 10000);
				$queryForSphinx = '';

				if($this->wordsAgainst) $queryForSphinx .= ' @(title,content) ' . $this->wordsAgainst;
				if(count($this->tagsAgainst)) {
					foreach($this->tagsAgainst as $value) {
						// in normal case only checkbox mode has spaces
						$queryForSphinx .= ' @tags ' . str_replace('" "', '" | "', trim($value));
					}
				}
				$queryForSphinx .= ' @(language) _language_' . $GLOBALS['TSFE']->sys_language_uid;
				$queryForSphinx .= ' @(fe_group) _group_NULL | _group_0';

				// add fe_groups to query
				if(!empty($GLOBALS['TSFE']->gr_list)) {
					$feGroups = t3lib_div::trimExplode(',', $GLOBALS['TSFE']->gr_list, 1);
					foreach($feGroups as $key => $group) {
						if(t3lib_div::intval_positive($group)) {
							$feGroups[$key] = '_group_' . $group;
						} else unset($feGroups[$key]);
					}
					if(is_array($feGroups) && count($feGroups)) $queryForSphinx .= ' | ' . implode(' | ', $feGroups);
				}

				// hook for appending additional where clause to sphinx query
				if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['appendWhereToSphinx'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['appendWhereToSphinx'] as $_classRef) {
						$_procObj = & t3lib_div::getUserObj($_classRef);
						$queryForSphinx = $_procObj->appendWhereToSphinx($queryForSphinx, $sphinx, $this);
					}
				}
				$res = $sphinx->getResForSearchResults($queryForSphinx, '*', 'uid, tags');
			} else {
				$res = $GLOBALS['TYPO3_DB']->sql_query($query);
			}

			$i = 1;
			while($tags = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
				foreach(explode(',', $tags['tags']) as $value) {
					$this->tagsInSearchResult[$value] += 1;
				}
			}
			$GLOBALS['TSFE']->fe_user->setKey('ses', 'ke_search.tagsInSearchResults', $this->tagsInSearchResult);
		}

		return array_key_exists($tagChar . $tag . $tagChar, $this->tagsInSearchResult);
	}


	/**
	 * get all filters configured in FlexForm
	 *
	 * @return array Array with filter UIDs
	 */
	public function getFiltersFromFlexform() {
		if(!empty($this->conf['filters']) && count($this->filtersFromFlexform) == 0) {
			$fields = '*';
			$table = 'tx_kesearch_filters';
			$where = 'pid in ('.$GLOBALS['TYPO3_DB']->quoteStr($this->startingPoints, $table).')';
			$where .= ' AND uid in ('.$GLOBALS['TYPO3_DB']->quoteStr($this->conf['filters'], 'tx_kesearch_filters').')';
			$where .= $this->cObj->enableFields($table);
			$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where);
			while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
				// Perform overlay on each record
				if(is_array($row) && $GLOBALS['TSFE']->sys_language_contentOL) {
					$row = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
						'tx_kesearch_filters',
						$row,
						$GLOBALS['TSFE']->sys_language_content,
						$GLOBALS['TSFE']->sys_language_contentOL
					);
				}
				$this->filtersFromFlexform[$row['uid']] = $row;
			}
		}
		return $this->filtersFromFlexform;
	}

	/**
	 * get optionrecords of given list of option-IDs
	 *
	 * @param string $optionList
	 * @param boolean $returnSortedByTitle Default: Sort by the exact order as they appear in optionlist. This is usefull if the customer want's the same ordering as in the filterRecord (inline)
	 * @return array Filteroptions
	 */
	public function getFilterOptions($optionList, $returnSortedByTitle = false) {
		// check/convert if list contains only integers
		$optionIdArray = t3lib_div::intExplode(',', $optionList, true);
		$optionList = implode(',', $optionIdArray);
		if($returnSortedByTitle) {
			$sortBy = 'title';
		} else $sortBy = 'FIND_IN_SET(uid, "' . $GLOBALS['TYPO3_DB']->quoteStr($optionList, 'tx_kesearch_filteroptions') . '")';

		// search for filteroptions
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'*',
			'tx_kesearch_filteroptions',
			'pid in ('.$this->startingPoints.') ' .
			'AND FIND_IN_SET(uid, "' . $GLOBALS['TYPO3_DB']->quoteStr($optionList, 'tx_kesearch_filteroptions') . '") ' .
			$this->cObj->enableFields('tx_kesearch_filteroptions'),
			'', $sortBy, ''
		);
		while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			// Perform overlay on each record
			if(is_array($row) && $GLOBALS['TSFE']->sys_language_contentOL) {
				$row = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
					'tx_kesearch_filteroptions',
					$row,
					$GLOBALS['TSFE']->sys_language_content,
					$GLOBALS['TSFE']->sys_language_contentOL
				);
			}
			$optionArray[$row['uid']] = $row;
		}

		return $optionArray;
	}


	/**
	 * Init XAJAX
	 */
	public function initXajax()	{
		// Include xaJax
		if(!class_exists('xajax')) {
			require_once(t3lib_extMgm::extPath('xajax') . 'class.tx_xajax.php');
		}
		// Make the instance
		$this->xajax = t3lib_div::makeInstance('tx_xajax');
		// Decode form vars from utf8
		$this->xajax->decodeUTF8InputOn();
		// Encoding of the response to utf-8.
		$this->xajax->setCharEncoding('utf-8');
		// $this->xajax->setCharEncoding('iso-8859-1');
		// To prevent conflicts, prepend the extension prefix.
		$this->xajax->setWrapperPrefix($this->prefixId);
		// Do you want messages in the status bar?
		$this->xajax->statusMessagesOn();
		// Turn only on during testing
		// $this->xajax->debugOn();

		// Register the names of the PHP functions you want to be able to call through xajax
		$this->xajax->registerFunction(array('refresh', &$this, 'refresh'));
		if ($this->conf['renderMethod'] != 'static') {
			$this->xajax->registerFunction(array('refreshFiltersOnLoad', &$this, 'refreshFiltersOnLoad'));
		}
		// $this->xajax->registerFunction(array('resetSearchbox', &$this, 'resetSearchbox'));

		// If this is an xajax request call our registered function, send output and exit
		$this->xajax->processRequests();

		// Create javacript and add it to the normal output
		$GLOBALS['TSFE']->additionalHeaderData['xajax_search_filters'] = $this->xajax->getJavascript(t3lib_extMgm::siteRelPath('xajax'));
	}


	/**
	 * This function will be called from AJAX directly, so this must be public
	 *
	 * @param $data
	 */
	public function refresh($data) {
		// initializes plugin configuration
		$this->init();

			// set pivars
		foreach($data[$this->prefixId] as $key => $value) {
			if(is_array($data[$this->prefixId][$key])) {
				foreach($data[$this->prefixId][$key] as $subkey => $subtag)  {
					$this->piVars[$key][$subkey] = $subtag;
				}
			} else {
				$this->piVars[$key] = $value;
			}
		}

		// create a list of all filters in piVars
		if (is_array($this->piVars['filter'])) {
			foreach($this->piVars['filter'] as $key => $value) {
				if(is_array($this->piVars['filter'][$key])) {
					$filterString .= implode($this->piVars['filter'][$key]);
				} else {
					$filterString .= $this->piVars['filter'][$key];
				}
			}
		}

		// generate onload image
		$onloadSrc = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/blank.gif';
		$this->onloadImage = '<img src="'.$onloadSrc.'?ts='.time().'" onload="hideSpinner();" alt="" />';
		if ($GLOBALS['TSFE']->id != $this->conf['resultPage']) {
			$this->onloadImage = '<img src="'.$onloadSrc.'?ts='.time().'" onload="hideSpinnerFiltersOnly();" alt="" /> ';
		}

		// init javascript onclick actions
		$this->initOnclickActions();

		// reset filters?
		if ($this->piVars['resetFilters'] && is_array($this->piVars['filter'])) {
			foreach ($this->piVars['filter'] as $key => $value) {
				// do not reset the preselected filters
				if ($this->preselectedFilter[$key]) {
					$this->piVars['filter'][$key] = $this->preselectedFilter[$key];
				}
			}
		}

		// make xajax response object
		$objResponse = new tx_xajax_response();

		if(!$filterString && !$this->piVars['sword'] && $this->conf['showTextInsteadOfResults']) {
			$objResponse->addAssign('kesearch_results', 'innerHTML', $this->pi_RTEcssText($this->conf['textForResults']));
			$objResponse->addAssign('kesearch_query_time', 'innerHTML', '');
			$objResponse->addAssign('kesearch_ordering', 'innerHTML', '');
			$objResponse->addAssign('kesearch_pagebrowser_top', 'innerHTML', '');
			$objResponse->addAssign('kesearch_pagebrowser_bottom', 'innerHTML', '');
			$objResponse->addAssign('kesearch_updating_results', 'innerHTML', '');
			$objResponse->addAssign('kesearch_num_results', 'innerHTML', '');
			$objResponse->addAssign('kesearch_filters', 'innerHTML', $this->renderFilters() . $this->onloadImage);
		} else {
			// set search results
			// process if on result page
			if ($GLOBALS['TSFE']->id == $this->conf['resultPage']) {
				$objResponse->addAssign('kesearch_results', 'innerHTML', $this->getSearchResults() . $this->onloadImage);
				$objResponse->addAssign('kesearch_num_results', 'innerHTML', $this->pi_getLL('num_results') . $this->numberOfResults);
				$objResponse->addAssign('kesearch_ordering', 'innerHTML', $this->renderOrdering());
			}

			// set pagebrowser
			if ($GLOBALS['TSFE']->id == $this->conf['resultPage']) {
				if ($this->conf['pagebrowserOnTop'] || $this->conf['pagebrowserAtBottom']) {
					$pagebrowserContent = $this->renderPagebrowser();
				}
				if ($this->conf['pagebrowserOnTop']) {
					$objResponse->addAssign('kesearch_pagebrowser_top', 'innerHTML', $pagebrowserContent);
				} else {
					$objResponse->addAssign('kesearch_pagebrowser_top', 'innerHTML', '');
				}
				if ($this->conf['pagebrowserAtBottom']) {
					$objResponse->addAssign('kesearch_pagebrowser_bottom', 'innerHTML', $pagebrowserContent);
				} else {
					$objResponse->addAssign('kesearch_pagebrowser_bottom', 'innerHTML', '');
				}
			}

			// set filters
			$objResponse->addAssign('kesearch_filters', 'innerHTML', $this->renderFilters() . $this->onloadImage);

			// set end milliseconds for query time calculation
			if ($this->conf['showQueryTime']) {
				// Calculate Querytime
				// we have two plugin. That's why we work with register here.
				$GLOBALS['TSFE']->register['ke_search_queryTime'] = (t3lib_div::milliseconds() - $GLOBALS['TSFE']->register['ke_search_queryStartTime']);
				$objResponse->addAssign('kesearch_query_time', 'innerHTML', sprintf($this->pi_getLL('query_time'), $GLOBALS['TSFE']->register['ke_search_queryTime']));
			}

			// Show error message
			if ($this->div->showShortMessage) {
				$errorMessage = $this->cObj->getSubpart($this->templateCode,'###GENERAL_MESSAGE###');
				// attention icon
				unset($imageConf);
				$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/attention.gif';
				$imageConf['altText'] = $this->pi_getLL('searchword_length_error');
				$errorMessage = $this->cObj->substituteMarker($errorMessage,'###IMAGE###', $this->cObj->IMAGE($imageConf));
				$errorMessage = $this->cObj->substituteMarker($errorMessage,'###MESSAGE###', $this->pi_getLL('searchword_length_error'));

				$objResponse->addAssign("kesearch_error", "innerHTML", $errorMessage);
			} else {
				$objResponse->addAssign("kesearch_error", "innerHTML", '');
			}
		}
		// return response xml
		return $objResponse->getXML();
	}

	/*
	 * function refresh
	 * @param $arg
	 */
	public function refreshFiltersOnload($data) {
		// initializes plugin configuration
		$this->init();

		// set pivars
		$this->piVars = $data[$this->prefixId];
		foreach ($this->piVars as $key => $value) {
			$this->piVars[$key] = $value;
		}

		// init javascript onclick actions
		$this->initOnclickActions();

		// reset filters?
		if ($this->piVars['resetFilters'] && is_array($this->piVars['filter'])) {
			foreach ($this->piVars['filter'] as $key => $value) {
				// do not reset the preselected filters
				if ($this->preselectedFilter[$key]) {
					$this->piVars['filter'][$key] = $this->preselectedFilter[$key];
				}
				else {
					$this->piVars['filter'][$key] = '';
				}
			}
		}

		// make xajax response object
		$objResponse = new tx_xajax_response();

		// generate onload image
		$onloadSrc = t3lib_extMgm::siteRelPath($this->extKey).'res/img/blank.gif';
		$this->onloadImage = '<img src="'.$onloadSrc.'?ts='.time().'" onload="hideSpinner();" alt="" />';
		if ($GLOBALS['TSFE']->id != $this->conf['resultPage']) {
			$this->onloadImage = '<img src="'.$onloadSrc.'?ts='.time().'" onload="hideSpinnerFiltersOnly();" alt="" /> ';
		}

		// set filters
		$objResponse->addAssign('kesearch_filters', 'innerHTML', $this->renderFilters().$this->onloadImage );

		// return response xml
		return $objResponse->getXML();
	}


	/*
	 * function refresh
	 * @param $arg
	 */
	/*
	public function resetSearchbox($data) {

		// initializes plugin configuration
		$this->init();

		$this->piVars = $data[$this->prefixId];
		foreach ($this->piVars as $key => $value) {
			$this->piVars[$key] = $this->div->removeXSS($value);
		}

		// onclick javascript actions
		$this->initOnclickActions();

		// reset filters?
		if ($this->piVars['resetFilters'] && is_array($this->piVars['filter'])) {
			foreach ($this->piVars['filter'] as $key => $value) {
				//$testcontent .= '<p>'.$key.': '.$value;
				// do not reset the preselected filters

				if ($this->preselectedFilter[$key]) {
					//$testcontent .= ' : '.$this->preselectedFilter[$key].'</p>';
					$this->piVars['filter'][$key] = $this->preselectedFilter[$key];
				}
				else {
					// reset filter value to 'all'
					$this->piVars['filter'][$key] = '';
				}
			}
		}

		// make xajax response object
		$objResponse = new tx_xajax_response();

		// set filters
		$objResponse->addAssign("kesearch_filters", "innerHTML", $this->renderFilters());

		// return response xml
		return $objResponse->getXML();
	}
	*/

	/*
	 * function getSearchResults
	 */
	public function getSearchResults() {
		// generate and add onload image
		$onloadSrc = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/blank.gif';
		$this->onloadImage = '<img src="'.$onloadSrc.'?ts='.time().'" onload="hideSpinner();" alt="" />';
		if ($GLOBALS['TSFE']->id != $this->conf['resultPage']) {
			$this->onloadImage = '<img src="'.$onloadSrc.'?ts='.time().'" onload="hideSpinnerFiltersOnly();" alt="" /> ';
		}

		// use sphinx mode only when a searchstring is given.
		// TODO: Sphinx has problems to show results when no query is given
		if(t3lib_extMgm::isLoaded('ke_search_premium') && !$this->isEmptySearch) {
			require_once(t3lib_extMgm::extPath('ke_search_premium') . 'class.user_kesearchpremium.php');
			$this->user_kesearchpremium = t3lib_div::makeInstance('user_kesearchpremium');

			// set ordering
			$this->user_kesearchpremium->setSorting($this->db->getOrdering());

			// set limit
			$limit = $this->db->getLimit();
			$this->user_kesearchpremium->setLimit($limit[0], $limit[1]);

			// generate query
			$queryForSphinx = '';
			if($this->wordsAgainst) $queryForSphinx .= ' @(title,content) ' . $this->wordsAgainst;
			if(count($this->tagsAgainst)) {
				foreach($this->tagsAgainst as $value) {
					// in normal case only checkbox mode has spaces
					$queryForSphinx .= ' @tags ' . str_replace('" "', '" | "', trim($value));
				}
			}
			$queryForSphinx .= ' @language _language_' . $GLOBALS['TSFE']->sys_language_uid;
			$queryForSphinx .= ' @fe_group _group_NULL | _group_0';

			// add fe_groups to query
			if(!empty($GLOBALS['TSFE']->gr_list)) {
				$feGroups = t3lib_div::trimExplode(',', $GLOBALS['TSFE']->gr_list, 1);
				foreach($feGroups as $key => $group) {
					if(t3lib_div::intval_positive($group)) {
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
			$res = $this->user_kesearchpremium->getResForSearchResults($queryForSphinx);
			//t3lib_utility_Debug::debug($this->user_kesearchpremium->getLastWarning());
			//t3lib_utility_Debug::debug($this->user_kesearchpremium->getLastError());

			// get number of records
			$this->numberOfResults = $this->user_kesearchpremium->getTotalFound();
		} else {
			// get search results
			$query = $this->db->generateQueryForSearch();

			$res = $GLOBALS['TYPO3_DB']->sql_query($query);
			// get number of records
			$this->numberOfResults = $this->db->getAmountOfSearchResults();
		}

		// count searchword with ke_stats
		$this->countSearchWordWithKeStats($this->sword);

		// count search phrase in ke_search statistic tables
		if ($this->conf['countSearchPhrases']) {
			$this->countSearchPhrase($this->sword, $this->swords, $this->numberOfResults, $this->tagsAgainst);
		}
		if($this->numberOfResults == 0) {

			// get subpart for general message
			$content = $this->cObj->getSubpart($this->templateCode, '###GENERAL_MESSAGE###');

			// no results found
			if($this->conf['showNoResultsText']) {
				// use individual text set in flexform
				$noResultsText = $this->pi_RTEcssText($this->conf['noResultsText']);
				$attentionImage = '';
			} else {
				// use general text
				$noResultsText = $this->pi_getLL('no_results_found');
				// attention icon
				unset($imageConf);
				$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/attention.gif';
				$imageConf['altText'] = $this->pi_getLL('no_results_found');
				$attentionImage=$this->cObj->IMAGE($imageConf);
			}

			// hook to implement your own idea of a no result message
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['noResultsHandler'])) {
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['noResultsHandler'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$noResultsText = $_procObj->noResultsHandler($noResultsText, $this);
				}
			}

			// set text for "no results found"
			$content = $this->cObj->substituteMarker($content,'###MESSAGE###', $noResultsText);
			// set attention icon?
			$content = $this->cObj->substituteMarker($content,'###IMAGE###', $attentionImage);

			// add query
			if ($this->conf['showQuery']) {
				$content .= '<br />'.$query.'<br />';
			}

			// add onload image if in AJAX mode
			if($this->conf['renderMethod'] != 'static') {
				$content .= $this->onloadImage;
			}

			return $content;
		}

		if($this->hasTooShortWords) {
			// get subpart for general message
			$content = $this->cObj->getSubpart($this->templateCode, '###GENERAL_MESSAGE###');
			$content = $this->cObj->substituteMarker($content, '###MESSAGE###', $this->pi_getLL('searchword_length_error'));

			// attention icon
			unset($imageConf);
			$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/attention.gif';
			$imageConf['altText'] = $this->pi_getLL('no_results_found');
			$attentionImage=$this->cObj->IMAGE($imageConf);

			// set attention icon?
			$content = $this->cObj->substituteMarker($content,'###IMAGE###', $attentionImage);
		}

		// loop through results
		// init results counter
		$resultCount = 1;
		while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {

			// build link and url
			unset($linkconf);
			$linkconf['parameter'] = $row['targetpid'];
			// add params to result link
			if (!empty($row['params'])) $linkconf['additionalParams'] = $row['params'];
			// add chash
			$linkconf['useCacheHash'] = true;
			$linkconf['target'] = $this->conf['resultLinkTarget'];

			// set result title
			$linktext = $row['title'];
			$linktext = strip_tags($linktext);
			$linktext = $this->div->removeXSS($linktext);
			//$linktext = htmlentities($linktext);

			// highlight hits in result title?
			if($this->conf['highlightSword'] && count($this->swords)) {
				foreach($this->swords as $word) {
					$word = str_replace('/', '\/', $word);
					$linktextReplaced = preg_replace('/(' . $word . ')/iu','<span class="hit">\0</span>', $linktext);
					if(!empty($linktextReplaced)) $linktext = $linktextReplaced;
				}
			}

			$resultLink = $this->cObj->typoLink($linktext, $linkconf);
			$resultUrl = t3lib_div::getIndpEnv('TYPO3_SITE_URL') . $this->cObj->typoLink_URL($linkconf);
			$this->resultUrl = $resultUrl;
			$resultUrlLink = $this->cObj->typoLink($resultUrl, $linkconf);

			// generate row content
			$tempContent = $this->cObj->getSubpart($this->templateCode, '###RESULT_ROW###');

			// result preview - as set in pi config
			if ($this->conf['previewMode'] == 'abstract') {

				// always show abstract
				if (!empty($row['abstract'])) {
					$teaserContent = nl2br($row['abstract']);
					$teaserContent = $this->buildTeaserContent($teaserContent);
				} else  {
					// build teaser from content
					$teaserContent = $this->buildTeaserContent($row['content']);
				}

			} else if ($this->conf['previewMode'] == 'hit' || $this->conf['previewMode'] == '') {
				if (!empty($row['abstract'])) {
					// show abstract if it contains sword, otherwise show content
					$abstractHit = false;
					foreach($this->swords as $word) {
						if (preg_match('/('.$word.')/iu', $row['abstract'])) {
							$abstractHit = true;
						}
					}
					if ($abstractHit) {
						$teaserContent = nl2br($row['abstract']);
						$teaserContent = $this->buildTeaserContent($teaserContent);
					} else {
						// sword was not found in abstract
						$teaserContent = $this->buildTeaserContent($row['content']);
					}
				} else {
					// sword was not found in abstract
					$teaserContent = $this->buildTeaserContent($row['content']);
				}
			}

			$tempMarkerArray = array(
				'title' => $resultLink,
				'teaser' => $teaserContent,
			);

			// hook for additional markers in result
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['additionalResultMarker'])) {
					// make curent row number available to hook
				$this->currentRowNumber = $resultCount;
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['additionalResultMarker'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$_procObj->additionalResultMarker(
						$tempMarkerArray,
						$row,
						$this
					);
				}
				unset($this->currentRowNumber);
			}


			$tempContent = $this->cObj->substituteMarkerArray($tempContent,$tempMarkerArray,$wrap='###|###',$uppercase=1);

			// show result url?
			if ($this->conf['showResultUrl']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_RESULTURL###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_RESULTURL###', $this->pi_getLL('label_resulturl'));
				$subContent = $this->cObj->substituteMarker($subContent,'###RESULTURL###', $resultUrlLink);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_RESULTURL###', $subContent, $recursive=1);

			// show result numeration?
			if ($this->conf['resultsNumeration']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_NUMERATION###');
				$subContent = $this->cObj->substituteMarker($subContent,'###NUMBER###', $resultCount + ($this->piVars['page'] * $this->conf['resultsPerPage']) - $this->conf['resultsPerPage']);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_NUMERATION###', $subContent, $recursive=1);

			// show score?
			if ($this->conf['showScore'] && $row['score']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_SCORE###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_SCORE###', $this->pi_getLL('label_score'));
				$subContent = $this->cObj->substituteMarker($subContent,'###SCORE###', number_format($row['score'],2,',',''));
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_SCORE###', $subContent, $recursive=1);

			// show date?
			if ($this->conf['showDate'] && $row['sortdate']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_DATE###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_DATE###', $this->pi_getLL('label_date'));
				$subContent = $this->cObj->substituteMarker($subContent,'###DATE###', date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], $row['sortdate']));
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_DATE###', $subContent, $recursive=1);

			// show percental score?
			if ($this->conf['showPercentalScore'] && $row['percent']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_SCORE_PERCENT###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_SCORE_PERCENT###', $this->pi_getLL('label_score_percent'));
				$subContent = $this->cObj->substituteMarker($subContent,'###SCORE_PERCENT###', $row['percent']);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_SCORE_PERCENT###', $subContent, $recursive=1);

			// show score scale?
			if ($this->conf['showScoreScale'] && $row['percent']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_SCORE_SCALE###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_SCORE_SCALE###', $this->pi_getLL('label_score_scale'));
				$scoreScale = $this->renderSVGScale($row['percent']);
				$subContent = $this->cObj->substituteMarker($subContent,'###SCORE_SCALE###', $scoreScale);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_SCORE_SCALE###', $subContent, $recursive=1);

			// show tags?
			if ($this->conf['showTags']) {
				$tags = $row['tags'];
				$tags = str_replace('#', ' ', $tags);
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_TAGS###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_TAGS###', $this->pi_getLL('label_tags'));
				$subContent = $this->cObj->substituteMarker($subContent,'###TAGS###', $tags);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_TAGS###', $subContent, $recursive=1);

			// show query?
			if ($this->conf['showQuery']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_QUERY###');
				$subContent = $this->cObj->substituteMarker($subContent,'###LABEL_QUERY###', $this->pi_getLL('label_query'));
				$subContent = $this->cObj->substituteMarker($subContent,'###QUERY###', $query);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_QUERY###', $subContent, $recursive=1);

			// type icon
			if ($this->conf['showTypeIcon']) {
				$subContent = $this->cObj->getSubpart($this->templateCode,'###SUB_TYPE_ICON###');
				$subContent = $this->cObj->substituteMarker($subContent,'###TYPE_ICON###', $this->renderTypeIcon($row['type']));
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart ($tempContent, '###SUB_TYPE_ICON###', $subContent, $recursive=1);

			// add temp content to result list
			$content .= $tempContent;

			// increase result counter
			$resultCount++;
		}

		// hook for additional content AFTER the result list
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['additionalContentAfterResultList'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['additionalContentAfterResultList'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$content .= $_procObj->additionalContentAfterResultList($this);
			}
		}

		// add onload image if in AJAX mode
		if($this->conf['renderMethod'] != 'static') {
			$content .= $this->onloadImage;
		}

		return $content;
	}



	/**
 	* Counts searchword and -phrase if ke_stats is installed
 	*
 	* @param   string $searchphrase
 	* @return  void
 	* @author  Christian Buelter <buelter@kennziffer.com>
 	* @since   Tue Mar 01 2011 12:34:25 GMT+0100
 	*/
	public function countSearchWordWithKeStats($searchphrase='') {

		$searchphrase = trim($searchphrase);
		if (t3lib_extMgm::isLoaded('ke_stats') && !empty($searchphrase)) {
			$keStatsObj = t3lib_div::getUserObj('EXT:ke_stats/pi1/class.tx_kestats_pi1.php:tx_kestats_pi1');
			$keStatsObj->initApi();

				// count words
			$wordlist = t3lib_div::trimExplode(' ', $searchphrase, true);
			foreach ($wordlist as $singleword) {
				$keStatsObj->increaseCounter(
					'ke_search Words',
					'element_title,year,month',
					$singleword,
					0,
					$this->firstStartingPoint,
					$GLOBALS['TSFE']->sys_page->sys_language_uid,
					0,
					'extension'
				);
			}

				// count phrase
			$keStatsObj->increaseCounter(
				'ke_search Phrases',
				'element_title,year,month',
				$searchphrase,
				0,
				$this->firstStartingPoint,
				$GLOBALS['TSFE']->sys_page->sys_language_uid,
				0,
				'extension'
			);

			unset($wordlist);
			unset($singleword);
			unset($keStatsObj);
		}
	}


	/*
	 * function buildTeaserContent
	 */
	public function buildTeaserContent($resultText) {

		// calculate substring params
		// switch through all swords and use first word found for calculating
		$resultPos = 0;
		if(count($this->swords)) {
			for($i = 0; $i < count($this->swords); $i++) {
				$newResultPos = intval(stripos($resultText, (string)$this->swords[$i]));
				if($resultPos == 0) {
					$resultPos = $newResultPos;
				}
			}
		}

		$startPos = $resultPos - (ceil($this->conf['resultChars'] / 2));
		if($startPos < 0) $startPos = 0;
		$teaser = substr($resultText, $startPos);

		// crop til whitespace reached
		$cropped = false;
		if ($startPos != 0 && $teaser[0] != " " ) {
			$pos = strpos($teaser, ' ');
			if ($pos === false) {
				$teaser = ' ' . $teaser;
			} else {
				$teaser = substr($teaser, $pos);
				$cropped = true;
			}
		}

		// append dots when cropped
		if ($startPos > 0) $teaser = '...' . $teaser;
		$teaser = $this->betterSubstr($teaser, $this->conf['resultChars']);

		// highlight hits?
		if ($this->conf['highlightSword'] && count($this->swords)) {
			foreach ($this->swords as $word) {
				$word = str_replace('/', '\/', $word);
				$teaser = preg_replace('/('.$word.')/iu','<span class="hit">\0</span>',$teaser);
			}
		}

		return $teaser;
	}


	/**
	 * Fetches configuration value given its name.
	 * Merges flexform and TS configuration values.
	 *
	 * @param	string	$param	Configuration value name
	 * @return	string	Parameter value
	 */
	public function fetchConfigurationValue($param, $sheet = 'sDEF') {
		$value = trim($this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'], $param, $sheet)
		);
		return $value ? $value : $this->conf[$param];
	}


	/*
	 * function betterSubstr
	 *
	 * better substring function
	 *
	 * @param $str
	 * @param $length
	 * @param $minword
	 */
	public function betterSubstr($str, $length, $minword = 3) {
		$sub = '';
		$len = 0;
		foreach (explode(' ', $str) as $word) {
			$part = (($sub != '') ? ' ' : '') . $word;
			$sub .= $part;
			$len += strlen($part);
			if (strlen($word) > $minword && strlen($sub) >= $length) {
				break;
			}
		}
		return $sub . (($len < strlen($str)) ? '...' : '');
	}


	/*
	 * function renderPagebrowser
	 * @param $arg
	 */
	public function renderPagebrowser() {

		$this->initOnclickActions();

		$numberOfResults = $this->numberOfResults;
		$resultsPerPage = $this->conf['resultsPerPage'];
		$maxPages = $this->conf['maxPagesInPagebrowser'];

		// get total number of items to show
		if ($numberOfResults > $resultsPerPage) {
			// show pagebrowser if there are more entries that are
			// shown on one page
			$this->limit = $resultsPerPage;
		} else {
			// do not show pagebrowser
			return '';
		}

		// set db limit
		$start = ($this->piVars['page'] * $resultsPerPage) - $resultsPerPage;
		$this->dbLimit = $start.','.$resultsPerPage;

		// number of pages
		$pagesTotal = ceil($numberOfResults/ $resultsPerPage);

		$interval = ceil($maxPages/2);

		$startPage = $this->piVars['page'] - ceil(($maxPages/2));
		$endPage = $startPage + $maxPages - 1;
		if ($startPage < 1) {
			$startPage = 1;
			$endPage = $startPage + $maxPages -1;
		}
		if ($startPage > $pagesTotal) {
			$startPage = $pagesTotal - $maxPages + 1;
			$endPage = $pagesTotal;
		}
		if ($endPage > $pagesTotal) {
			$startPage = $pagesTotal - $maxPages + 1;
			$endPage = $pagesTotal;
		}

		// render pages list
		for ($i=1; $i<=$pagesTotal; $i++) {
			if ($i >= $startPage && $i <= $endPage) {

				// render static version
				unset($linkconf);
				$linkconf['parameter'] = $GLOBALS['TSFE']->id;
				$linkconf['addQueryString'] = 1;
				$linkconf['addQueryString.']['exclude'] = 'id';
				$linkconf['additionalParams'] = '&tx_kesearch_pi1[sword]='.$this->piVars['sword'];
				$linkconf['additionalParams'] .= '&tx_kesearch_pi1[page]='.intval($i);
				$filterArray = $this->getFiltersFromFlexform();

				if (is_array($this->piVars['filter'])) {
					foreach($this->piVars['filter'] as $filterId => $data) {
						if(is_array($data)) {
							foreach($data as $tagKey => $tag) {
								$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.'][' . $tagKey . ']='.$tag;
							}
						} else $linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.']='.$this->piVars['filter'][$filterId];
					}
				}

				if ($this->piVars['page'] == $i) $linkconf['ATagParams'] = 'class="current" ';
				$tempContent .= $this->cObj->typoLink($i, $linkconf);
			}
		}

		// end
		$end = ($start+$resultsPerPage > $numberOfResults) ? $numberOfResults : ($start+$resultsPerPage);

		// previous image with link
		if ($this->piVars['page'] > 1) {

			$previousPage = $this->piVars['page']-1;

			// get static version
			unset($linkconf);
			$linkconf['parameter'] = $GLOBALS['TSFE']->id;
			$linkconf['addQueryString'] = 1;
			$linkconf['additionalParams'] = '&tx_kesearch_pi1[sword]='.$this->piVars['sword'];
			$linkconf['additionalParams'] .= '&tx_kesearch_pi1[page]='.intval($previousPage);
			$filterArray = $this->getFiltersFromFlexform();

			if (is_array($this->piVars['filter'])) {
				foreach($this->piVars['filter'] as $filterId => $data) {
					if(is_array($data)) {
						foreach($data as $tagKey => $tag) {
							$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.'][' . $tagKey . ']='.$tag;
						}
					} else $linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.']='.$this->piVars['filter'][$filterId];
				}
			}

			$linkconf['ATagParams'] = 'class="prev" ';
			$previous = $this->cObj->typoLink(' ', $linkconf);
		} else {
			$previous = '';
		}

		// next image with link
		if ($this->piVars['page'] < $pagesTotal) {
			$nextPage = $this->piVars['page']+1;

			// get static version
			unset($linkconf);
			$linkconf['parameter'] = $GLOBALS['TSFE']->id;
			$linkconf['addQueryString'] = 1;
			$linkconf['additionalParams'] = '&tx_kesearch_pi1[sword]='.$this->piVars['sword'];
			$linkconf['additionalParams'] .= '&tx_kesearch_pi1[page]='.intval($nextPage);
			$filterArray = $this->getFiltersFromFlexform();

			if (is_array($this->piVars['filter'])) {
				foreach($this->piVars['filter'] as $filterId => $data) {
					if(is_array($data)) {
						foreach($data as $tagKey => $tag) {
							$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.'][' . $tagKey . ']='.$tag;
						}
					} else $linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.']='.$this->piVars['filter'][$filterId];
				}
			}

			$linkconf['ATagParams'] = 'class="next" ';
			$next = $this->cObj->typoLink(' ', $linkconf);
		} else {
			$next = '';
		}


		// render pagebrowser content
		$content = $this->cObj->getSubpart($this->templateCode, '###PAGEBROWSER###');
		$markerArray = array(
			'current' => $this->piVars['page'],
			'pages_total' => $pagesTotal,
			'pages_list' => $tempContent,
			'start' => $start+1,
			'end' => $end,
			'total' => $numberOfResults,
			'previous' => $previous,
			'next' => $next,
			'results' => $this->pi_getLL('results'),
			'until' => $this->pi_getLL('until'),
			'of' => $this->pi_getLL('of'),
		);
		$content = $this->cObj->substituteMarkerArray($content,$markerArray,$wrap='###|###',$uppercase=1);

		return $content;
	}


	public function renderOrdering() {
		$sortObj = t3lib_div::makeInstance('tx_kesearch_lib_sorting', $this);
		return $sortObj->renderSorting();
	}


	/*
	 * function renderSVGScale
	 * @param $arg
	 */
	public function renderSVGScale($percent) {
		$svgScriptPath = t3lib_extMgm::siteRelPath($this->extKey).'res/scripts/SVG.php?p='.$percent;
		return '<embed src="'.$svgScriptPath.'" style="margin-top: 5px;" width="50" height="12" type="image/svg+xml"	pluginspage="http://www.adobe.com/svg/viewer/install/" />';
	}


	/*
	 * function renderTypeIcon
	 * @param $type string
	 */
	public function renderTypeIcon($type) {
		$type = $this->div->removeXSS($type);
		unset($imageConf);
		$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/types/'.$type.'.gif';
		$image=$this->cObj->IMAGE($imageConf);
		return $image;
	}

	/*
	 * function initDomReadyAction
	 */
	public function initDomReadyAction() {

		// is current page the result page?
		$resultPage = ($GLOBALS['TSFE']->id == $this->conf['resultPage']) ? TRUE : FALSE;

		switch ($this->conf['renderMethod']) {
			case 'ajax_after_reload':
				// refresh results only if we are on the defined result page
				// do not refresh results if default text is shown (before filters and swords are sent)
				if ($resultPage) {
					if($this->isEmptySearch && $this->conf['showTextInsteadOfResults']) {
						$domReadyAction = 'onloadFilters();';
					} else {
						$domReadyAction = 'onloadFiltersAndResults();';
					}
				} else {
					$domReadyAction = 'onloadFilters();';
				}
				break;
			case 'static':
			default:
				$domReadyAction = '';
				break;
		}
		$this->onDomReady = empty($domReadyAction) ? '' : 'domReady(function() {'.$domReadyAction.'});';
	}


	/*
	 * count searchwords and phrases in statistic tables
	 *
	 * @param $searchPhrase string
	 * @param $searchWordsArray array
	 * @param $hits int
	 * @param $this->tagsAgainst string
	 * @return void
	 *
	 */
	public function countSearchPhrase($searchPhrase, $searchWordsArray, $hits, $tagsAgainst) {

		// prepare "tagsAgainst"
		$search = array('"', ' ', '+');
		$replace = array('', '', '');
		$tagsAgainst = str_replace($search, $replace, implode(' ', $tagsAgainst));

		// count search phrase
		if (!empty($searchPhrase)) {
			$table = 'tx_kesearch_stat_search';
			$fields_values = array(
				'pid' => $this->firstStartingPoint,
				'searchphrase' => strtolower($searchPhrase),
				'tstamp' => time(),
				'hits' => $hits,
				'tagsagainst' => $tagsAgainst,
			);
			$GLOBALS['TYPO3_DB']->exec_INSERTquery($table,$fields_values,$no_quote_fields=FALSE);
		}

		// count single words
		foreach ($searchWordsArray as $searchWord) {
			$table = 'tx_kesearch_stat_word';
			$timestamp = time();
			if (!empty($searchWord)) {
				$fields_values = array(
					'pid' => $this->firstStartingPoint,
					'word' => strtolower($searchWord),
					'tstamp' => $timestamp,
					'pageid' => $GLOBALS['TSFE']->id,
					'resultsfound' => $hits ? 1 : 0,
				);
				$GLOBALS['TYPO3_DB']->exec_INSERTquery($table,$fields_values,$no_quote_fields=FALSE);
			}
		}
	}


	/**
	 * gets all preselected filters from flexform
	 *
	 * @return none but fills global var with needed data
	 */
	public function getFilterPreselect() {
		// get definitions from plugin settings
		// and proceed only when preselectedFilter was not set
		// this reduces the amount of sql queries, too
		if($this->conf['preselected_filters'] && count($this->preselectedFilter) == 0) {
			$preselectedArray = t3lib_div::trimExplode(',', $this->conf['preselected_filters'], true);
			foreach ($preselectedArray as $option) {
				$option = intval($option);
				$fields = '
					tx_kesearch_filters.uid as filteruid,
					tx_kesearch_filteroptions.uid as optionuid,
					tx_kesearch_filteroptions.tag
				';
				$table = 'tx_kesearch_filters, tx_kesearch_filteroptions';
				$where = $GLOBALS['TYPO3_DB']->listQuery('tx_kesearch_filters.options', $option, 'tx_kesearch_filters');
				$where .= ' AND tx_kesearch_filteroptions.uid = ' . $option;
				$where .= $this->cObj->enableFields('tx_kesearch_filters');
				$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
				while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
					//$this->preselectedFilter[$row['filteruid']][] = $row['tag'];
					$this->preselectedFilter[$row['filteruid']][$row['optionuid']] = $row['tag'];
				}
			}
		}
	}


	/**
	 * function isEmptySearch
	 * checks if an empty search was loaded / submitted
	 *
	 * @return boolean true if no searchparams given; otherwise false
	 */
	public function isEmptySearch() {
		// check if searchword is emtpy or equal with default searchbox value
		$emptySearchword = (empty($this->sword) || $this->sword == $this->pi_getLL('searchbox_default_value')) ? true : false;

		// check if filters are set
		$this->filters = $this->getFiltersFromFlexform();
		$filterSet = false;
		if(is_array($this->filters))  {
			//TODO: piVars filter is a multidimensional array
			foreach($this->filters as $uid => $data)  {
				if(!empty($this->piVars['filter'][$uid])) $filterSet = true;
			}
		}

		if($emptySearchword && !$filterSet) return true;
		else return false;
	}


	/**
	 * function includeJavascript
	 */
	public function addHeaderParts() {
		// build target URL if not result page
		unset($linkconf);
		$linkconf['parameter'] = $this->conf['resultPage'];
		$linkconf['additionalParams'] = '';
		$linkconf['useCacheHash'] = false;
		$targetUrl = t3lib_div::locationHeaderUrl($this->cObj->typoLink_URL($linkconf));

		$content = $this->cObj->getSubpart($this->templateCode, '###JS_SEARCH_ALL###');
		if($this->conf['renderMethod'] != 'static' ) {
			$content .= $this->cObj->getSubpart($this->templateCode, '###JS_SEARCH_NON_STATIC###');
		}

		// include js for "ajax after page reload" mode
		if ($this->conf['renderMethod'] == 'ajax_after_reload') {
			$content .= $this->cObj->getSubpart($this->templateCode, '###JS_SEARCH_AJAX_RELOAD###');
		}

		// loop through LL and fill $markerArray
		array_key_exists($this->LLkey, $this->LOCAL_LANG) ? $langKey = $this->LLkey : $langKey = 'default';
		foreach($this->LOCAL_LANG[$langKey] as $key => $value) {
			$markerArray['###' . strtoupper($key) . '###'] = $value;
		}

		// define some additional markers
		$markerArray['###SITE_REL_PATH###'] = t3lib_extMgm::siteRelPath($this->extKey);
		$markerArray['###TARGET_URL###'] = $targetUrl;
		$markerArray['###PREFIX_ID###'] = $this->prefixId;
		$markerArray['###SEARCHBOX_DEFAULT_VALUE###'] = $this->pi_getLL('searchbox_default_value');
		$markerArray['###DOMREADYACTION###'] = $this->onDomReady;

		$content = $this->cObj->substituteMarkerArray($content, $markerArray);

		// add JS to page header
		$GLOBALS['TSFE']->additionalHeaderData['jsContent'] = $content;
	}


	public function sortArrayRecursive($array, $field) {
		#debug ($array);

		$sortArray = Array();
		$mynewArray = Array();

		$i=1;
		foreach ($array as $point) {
			$sortArray[] = $point[$field].$i;
			$i++;
		}
		rsort($sortArray);

		foreach ($sortArray as $sortet) {
			$i=1;
			foreach ($array as $point) {
				$newpoint[$field]= $point[$field].$i;
				if ($newpoint[$field]==$sortet) $mynewArray[] = $point;
				$i++;
			}
		}
		return $mynewArray;

	}


	public function sortArrayRecursive2($wert_a, $wert_b) {
		// Sortierung nach dem zweiten Wert des Array (Index: 1)
		$a = $wert_a[2];
		$b = $wert_b[2];

		if ($a == $b) {
			return 0;
		}

		return ($a < $b) ? -1 : +1;
	}
}
?>
