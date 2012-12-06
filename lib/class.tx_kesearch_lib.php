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
	var $extConfPremium      = array(); // Extension-Configuration of ke_search_premium if installed
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
	 * @var tx_kesearch_lib_div
	 */
	var $div;

	/**
	 * @var user_kesearchpremium
	 */
	var $user_kesearchpremium;

	/**
	 * @var tx_kesearch_lib_searchresult
	 */
	var $searchResult;

	/**
	 * @var tx_kesearch_filters
	 */
	var $filters;





	/**
	 * Initializes flexform, conf vars and some more
	 *
	 * @return nothing
	 */
	public function init() {
		// get some helper functions
		$this->div = t3lib_div::makeInstance('tx_kesearch_lib_div', $this);

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
		if(!$this->conf['resultPage']) $this->conf['resultPage'] = $GLOBALS['TSFE']->id;
		if(!isset($this->piVars['page'])) $this->piVars['page'] = 1;
		if(!empty($this->conf['additionalPathForTypeIcons'])) {
			$this->conf['additionalPathForTypeIcons'] = rtrim($this->conf['additionalPathForTypeIcons'], '/') . '/';
		}

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

		$this->filters = t3lib_div::makeInstance('tx_kesearch_filters');
		$this->filters->initialize($this);

		// get extension configuration array
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
		// sphinx has problems with # in query string.
		// so you have the possibility to change # against another char
		if(t3lib_extMgm::isLoaded('ke_search_premium')) {
			$this->extConfPremium = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search_premium']);
			if(!$this->extConfPremium['prePostTagChar']) $this->extConfPremium['prePostTagChar'] = '_';
			$this->extConf['prePostTagChar'] = $this->extConfPremium['prePostTagChar'];
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
		$searchPhrase = t3lib_div::makeInstance('tx_kesearch_lib_searchphrase');
		$searchPhrase->initialize($this);
		$searchWordInformation = $searchPhrase->buildSearchPhrase();

		// Hook: modifySearchWords
		if(isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifySearchWords'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifySearchWords'] as $classRef) {
				$hookObj = t3lib_div::getUserObj($classRef);
				if(method_exists($hookObj, 'modifySearchWords')) {
					$hookObj->modifySearchWords($searchWordInformation, $this);
				}
			}
		}

		// set searchword and tag information
		$this->sword = $searchWordInformation['sword'];
		$this->swords = $searchWordInformation['swords'];
		$this->wordsAgainst = $searchWordInformation['wordsAgainst'];
		$this->tagsAgainst = $searchWordInformation['tagsAgainst'];
		$this->scoreAgainst = $searchWordInformation['scoreAgainst'];

		$this->isEmptySearch = $this->isEmptySearch();

		// chooseBestIndex is only needed for MySQL-Search. Not for Sphinx
		if(!$this->extConfPremium['enableSphinxSearch']) {
			// precount results to find the best index
			$this->db->chooseBestIndex($this->wordsAgainst, $this->tagsAgainst);
		}

		// add cssTag to header if set
		$cssFile = $GLOBALS['TSFE']->tmpl->getFileName($this->conf['cssFile']);
		if(!empty($cssFile)) {
			$cssTag = $this->cObj->wrap($cssFile, '<link rel="stylesheet" type="text/css" href="|" />');
            if ($this->div->getNumericTYPO3versionNumber() >= 6000000) {
                $GLOBALS['TSFE']->getPageRenderer()->addCssFile($cssFile);
            } else {
				$GLOBALS['TSFE']->additionalHeaderData[$this->prefixId . '_css'] = $cssTag;
			}
		}
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
		$searchString = trim($this->piVars['sword']);

		if(!empty($searchString) && $searchString != $this->pi_getLL('searchbox_default_value')) {
			$this->swordValue = $searchString ? str_replace('"', '&quot;', $searchString) : '';
			$searchboxFocusJS = '';
		} else {
			$this->swordValue = $this->pi_getLL('searchbox_default_value');

			// set javascript for resetting searchbox value
			$searchboxFocusJS = ' searchboxFocus(this);  ';
		}

		$content = $this->cObj->substituteMarker($content,'###SWORD_VALUE###', $this->swordValue);
		$content = $this->cObj->substituteMarker($content,'###SWORD_ONFOCUS###', $searchboxFocusJS);
		$content = $this->cObj->substituteMarker($content,'###SORTBYFIELD###', $this->piVars['sortByField']);
		$content = $this->cObj->substituteMarker($content,'###SORTBYDIR###', $this->piVars['sortByDir']);

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

		// type param
		$typeParam = t3lib_div::_GP('type');
		if ($typeParam) {
			$hiddenFieldValue = intval($typeParam);
			$typeContent = $this->cObj->getSubpart($this->templateCode,'###SUB_PAGETYPE###');
			$typeContent = $this->cObj->substituteMarker($typeContent,'###PAGETYPE###',$typeParam);
		} else $typeContent = '';
		$content = $this->cObj->substituteSubpart ($content, '###SUB_PAGETYPE###', $typeContent, $recursive=1);

		// add submit button in static mode
		if ($this->conf['renderMethod'] == 'static') {
			$submitButton = '<input type="submit" value="' . $this->pi_getLL('submit') . '" />';
		} else {
			$submitButton = '';
		}
		$content = $this->cObj->substituteMarker($content,'###SUBMIT###',$submitButton);

		// set reset link
		unset($linkconf);
		$linkconf['parameter'] = $this->conf['resultPage'];
		$resetUrl = $this->cObj->typoLink_URL($linkconf);
		$resetLink = '<a href="'.$resetUrl.'" class="resetButton"><span>'.$this->pi_getLL('reset_button').'</span></a>';
		$content = $this->cObj->substituteMarker($content,'###RESET###',$resetLink);

		// init onDomReadyAction
		$this->initDomReadyAction();


		return $content;
	}


	/**
	 * loop through all available filters and render them individually
	 *
	 * @return string HTML-Content concatenated for each filter
	 */
	public function renderFilters() {
		foreach($this->filters->getFilters() as $filter) {
			$options = array(); // reset variable for each loop
			foreach($filter['options'] as $option) {
				// check filter availability?
				if($this->conf['checkFilterCondition'] != 'none') {
					if($this->filters->checkIfTagMatchesRecords($option['tag'])) {
						// Is the filter option selected in the frontend via piVars
						// or in the backend via flexform configuration?
						$selected = 0;

						if($this->piVars['filter'][$filter['uid']] == $option['tag']) {
							$selected = 1;
						} elseif(is_array($this->piVars['filter'][$filter['uid']])) {
							if(t3lib_div::inArray($this->piVars['filter'][$filter['uid']], $option['tag'])) {
								$selected = 1;
							}
							// check preselected filter options
						} elseif(!isset($this->piVars['filter'][$filter['uid']]) && !is_array($this->piVars['filter'][$filter['uid']])) {
							if (is_array($this->preselectedFilter) && $this->in_multiarray($option['tag'], $this->preselectedFilter)) {
								$selected = 1;
								// add preselected filter to piVars
								$this->piVars['filter'][$filter['uid']] = array($option['uid'] => $option['tag']);
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
					// do not process check; show all filter options
					$options[$option['uid']] = array(
						'title' => $option['title'],
						'value' => $option['tag'],
						'selected' => $selected,
					);
				}
			}

			// hook for modifying filter options
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptionsArray'])) {
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFilterOptionsArray'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$options = $_procObj->modifyFilterOptionsArray($filter['uid'], $options, $this);
				}
			}

			// render "wrap"
			if($filter['wrap']) {
				$wrap = t3lib_div::trimExplode('|', $filter['wrap']);
			} else {
				$wrap = array(
					0 => '',
					1 => ''
				);
			}

			// get subparts corresponding to render type
			switch($filter['rendertype']) {

				case 'select':
				default:
					$filterContent .= $wrap[0] . $this->renderSelect($filter['uid'], $options) . $wrap[1];
					break;

				case 'list':
					$filterContent .= $wrap[0] . $this->renderList($filter['uid'], $options) . $wrap[1];
					break;

				case 'checkbox':
					$filterContent .= $wrap[0] . $this->renderCheckbox($filter['uid'], $options) . $wrap[1];
					break;

				case 'textlinks':
					$textLinkObj = t3lib_div::makeInstance('tx_kesearch_lib_filters_textlinks', $this);
					$filterContent .= $wrap[0] . $textLinkObj->renderTextlinks($filter['uid'], $options) . $wrap[1];
					break;
				// use custom render code
				default:
						// hook for custom filter renderer
					$customFilterContent = '';
					if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customFilterRenderer'])) {
						foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customFilterRenderer'] as $_classRef) {
							$_procObj = & t3lib_div::getUserObj($_classRef);
							$customFilterContent .= $_procObj->customFilterRenderer($filter['uid'], $options, $this);
						}
					}
					if ($customFilterContent) {
						$filterContent .= $wrap[0] . $customFilterContent . $wrap[1];
					}
					break;
			}
		}

		return $filterContent;
	}


	/*
	 * function renderSelect
	 * @param $arg
	 */
	public function renderSelect($filterUid, $options) {
		$filters = $this->filters->getFilters();
		$filterSubpart = '###SUB_FILTER_SELECT###';
		$optionSubpart = '###SUB_FILTER_SELECT_OPTION###';

		// add standard option "all"
		$optionsContent .= $this->cObj->getSubpart($this->templateCode, $optionSubpart);
		$optionsContent = $this->cObj->substituteMarker($optionsContent,'###TITLE###', $filters[$filterUid]['title']);
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
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $filters[$filterUid]['title']);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERNAME###', 'tx_kesearch_pi1[filter]['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERID###', 'filter_' . $filterUid);
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
		$filters = $this->filters->getFilters();
		$filterSubpart = '###SUB_FILTER_LIST###';
		$optionSubpart = '###SUB_FILTER_LIST_OPTION###';

		$optionsCount = 0;


		if($this->conf['renderMethod'] == 'static') {
			// STATIC MODE
			// in static mode, the list filter can not submit other filter values
			// it submits only the current filter value that is clicked, other
			// filters are ignored
			if (is_array($options)) {
				foreach ($options as $key => $data) {

					$onclick = '';

					// build filter link
					$optionLink = '';
					unset($linkconf);
					$linkconf['parameter'] = $GLOBALS['TSFE']->id;
					$linkconf['additionalParams'] = '&tx_kesearch_pi1[sword]='.$this->piVars['sword'].'&tx_kesearch_pi1[filter]['.$filterUid.']='.$data['value'];
					$linkconf['useCacheHash'] = false;
					$optionLink = $this->cObj->typoLink($data['title'],$linkconf);

					$optionsContent .= $this->cObj->getSubpart($this->templateCode, $optionSubpart);
					$optionsContent = $this->cObj->substituteMarker($optionsContent,'###ONCLICK###', '');
					$optionsContent = $this->cObj->substituteMarker($optionsContent,'###TITLE###', $optionLink);
					$cssClass = 'option ';
					$cssClass .= $data['selected'] ? 'selected' : '';
					$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONCSSCLASS###', $cssClass);

					$optionsCount++;
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

				// build link to reset filter
				unset($linkconf);
				$linkconf['parameter'] = $GLOBALS['TSFE']->id;
				$linkconf['additionalParams'] = '&tx_kesearch_pi1[sword]='.$this->piVars['sword'];
				$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterUid.']=';
				foreach ($this->piVars['filter'] as $key => $value) {
					if ($key != $filterUid) {
						$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$key.']='.$value.'';
					}
				}
				$resetFilterLink = $this->cObj->typoLink($this->pi_getLL('reset_filter'),$linkconf);

				// fill markers
				$filterContent = $this->cObj->getSubpart($this->templateCode, $filterSubpart);
				$filterContent = $this->cObj->substituteSubpart ($filterContent, $optionSubpart, $optionsContent);
				$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $filters[$filterUid]['title']);
				$filterContent = $this->cObj->substituteMarker($filterContent,'###SWITCH_AREA_START###', '');
				$filterContent = $this->cObj->substituteMarker($filterContent,'###SWITCH_AREA_END###', '');
				$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERNAME###', 'tx_kesearch_pi1[filter]['.$filterUid.']');
				$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERID###', 'filter_' . $filterUid);
				$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCHANGE###', '');
				$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCLICK_RESET###', '');
				$filterContent = $this->cObj->substituteMarker($filterContent,'###RESET_FILTER###', $resetFilterLink);
				$filterContent = $this->cObj->substituteMarker($filterContent,'###DISABLED###', $optionsCount > 0 ? '' : ' disabled="disabled" ');
				$filterContent = $this->cObj->substituteMarker($filterContent,'###VALUE###', $this->piVars['filter'][$filterUid]);
			}
			// return $this->renderSelect($filterUid, $options);
		} else {
			// AJAX -MODE
			// use javascript onclick action for submitting the whole form
			// if in ajax-mode
			// loop through options

			if (is_array($options)) {
				foreach ($options as $key => $data) {

					$onclick = '';
					$tempField = strtolower(t3lib_div::removeXSS($this->piVars['orderByField']));
					$tempDir = strtolower(t3lib_div::removeXSS($this->piVars['orderByDir']));
					if($tempField != '' && $tempDir != '') {
						$onclick = 'setOrderBy(' . $tempField . ', ' . $tempDir . ');';
					}
					$onclick = $onclick . ' document.getElementById(\'filter_' . $filterUid . '\').value=\''.$data['value'].'\'; ';
					$onclick .= ' document.getElementById(\'pagenumber\').value=\'1\'; ';
					$onclick .= $this->onclickFilter;
					$onclick = 'onclick="'.$onclick.'"';

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

			// build onclick reset
			$onclickReset = 'onclick="document.getElementById(\'filter_' . $filterUid . '\').value=\'\'; '.$this->onclickFilter.' "';

			// fill markers
			$filterContent = $this->cObj->getSubpart($this->templateCode, $filterSubpart);
			$filterContent = $this->cObj->substituteSubpart ($filterContent, $optionSubpart, $optionsContent);
			$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $filters[$filterUid]['title']);
			$filterContent = $this->cObj->substituteMarker($filterContent,'###SWITCH_AREA_START###', '<a href="javascript:switchArea(\'filter_'.$filterUid.'\')">');
			$filterContent = $this->cObj->substituteMarker($filterContent,'###SWITCH_AREA_END###', '</a>');
			$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERNAME###', 'tx_kesearch_pi1[filter]['.$filterUid.']');
			$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERID###', 'filter_' . $filterUid);
			$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCHANGE###', $this->onclickFilter);
			$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCLICK_RESET###', $onclickReset );
			$filterContent = $this->cObj->substituteMarker($filterContent,'###RESET_FILTER###', $this->pi_getLL('reset_filter'));
			$filterContent = $this->cObj->substituteMarker($filterContent,'###DISABLED###', $optionsCount > 0 ? '' : ' disabled="disabled" ');
			$filterContent = $this->cObj->substituteMarker($filterContent,'###VALUE###', $this->piVars['filter'][$filterUid]);
		}

		// bullet
		unset($imageConf);
		$bulletSrc = $filters[$filterUid]['expandbydefault'] ? 'list-head-expanded.gif' : 'list-head-closed.gif';
		$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/'.$bulletSrc;
		$imageConf['params'] = 'class="bullet" id="bullet_filter_' . $filterUid . '" ';
		$filterContent = $this->cObj->substituteMarker($filterContent,'###BULLET###', $this->cObj->IMAGE($imageConf));

		// expand by default ?
		$class = $filters[$filterUid]['expandbydefault'] || !empty($this->piVars['filter'][$filterUid]) || $this->conf['renderMethod'] == 'static' ? 'expanded' : 'closed';
		$filterContent = $this->cObj->substituteMarker($filterContent,'###LISTCSSCLASS###', $class);

		// special css class (outer options list for scrollbox)
		$filterContent = $this->cObj->substituteMarker($filterContent,'###SPECIAL_CSS_CLASS###', $filters[$filterUid]['cssclass'] ? $filters[$filterUid]['cssclass'] : '');

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
		$filters = $this->filters->getFilters();
		$allOptionsOfCurrentFilter = $filters[$filterUid]['options'];

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
					// options which have been preselected in the backend are already in $this->piVars['filter'][$filterUid]
					if($this->piVars['filter'][$filterUid][$key]) {
						$checkBoxParams['selected'] = 'checked="checked"';
					}

					// mark all checkboxes if that config options is set and no search string was given and there
					// are no preselected filters given for that filter
					if($this->isEmptySearch && $filters[$filterUid]['markAllCheckboxes'] && empty($this->preselectedFilter[$filterUid])) {
						$checkBoxParams['selected'] = 'checked="checked"';
					}

				} else { // if an option was not found in the search results
					$checkBoxParams['disabled'] = 'disabled="disabled"';
				}

				$markerArray['###TITLE###'] = $data['title'];
				$markerArray['###VALUE###'] = $data['tag'];
				$markerArray['###OPTIONKEY###'] = $key;
				$markerArray['###OPTIONID###'] = 'filter_' . $filterUid . '_' . $key;
				$markerArray['###OPTIONCSSCLASS###'] = 'optionCheckBox optionCheckBox' . $filterUid . ' optionCheckBox' . $filterUid . '_' . $key;
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
		$filterTitle = $filters[$filterUid]['title'];

		// get bullet image
		$bulletSrc = $filters[$filterUid]['expandbydefault'] ? 'list-head-expanded.gif' : 'list-head-closed.gif';
		$bulletConf['file'] = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/' . $bulletSrc;
		$bulletConf['params'] = 'class="bullet" id="bullet_filter_' . $filterUid . '" ';
		$bulletImage = $this->cObj->IMAGE($bulletConf);

		/**
		 * if "expand by default" is set
		 * if value in current filter is not empty
		 * if we are in static mode
		 */
		if($filters[$filterUid]['expandbydefault'] || !empty($this->piVars['filter'][$filterUid]) || $this->conf['renderMethod'] == 'static') {
			$class = 'expanded';
		} else $class = 'closed';

		// fill markers
		$markerArray['###LABEL_ALL###'] = $this->pi_getLL('label_all');
		$markerArray['###FILTERTITLE###'] = $filterTitle;
		$markerArray['###FILTERNAME###'] = 'tx_kesearch_pi1[filter]['.$filterUid.']';
		$markerArray['###FILTERID###'] = 'filter_' . $filterUid;
		$markerArray['###FILTER_UID###'] = $filterUid;
		$markerArray['###ONCHANGE###'] = $this->onclickFilter;
		$markerArray['###ONCLICK_RESET###'] = $this->onclickFilter;
		$markerArray['###DISABLED###'] = $optionsCount > 0 ? '' : ' disabled="disabled" ';
		$markerArray['###BULLET###'] = $bulletImage;
		$markerArray['###LISTCSSCLASS###'] = $class;
		$markerArray['###SPECIAL_CSS_CLASS###'] = $filters[$filterUid]['cssclass'] ? $filters[$filterUid]['cssclass'] : '';
		$markerArray['###SWITCH_AREA_START###'] = $this->conf['renderMethod'] != 'static' ? '<a href="javascript:switchArea(\'filter_'.$filterUid.'\')">' : '';
		$markerArray['###SWITCH_AREA_END###'] = $this->conf['renderMethod'] != 'static' ? '</a>' : '';
		$contentFilters = $this->cObj->substituteMarkerArray($contentFilters, $markerArray);

		// show checkbox switch only in ajax mode (needs javascript)
		if ($this->conf['renderMethod'] != 'static') {
			$checkboxSwitch  = $this->cObj->getSubpart($this->templateCode,'###SUB_CHECKBOX_SWITCH###');
			$markerArray = array(
				'###FILTER_UID###' => $filterUid,
				'###LABEL_ALL###' => $this->pi_getLL('label_all'),
			);
			$checkboxSwitch = $this->cObj->substituteMarkerArray($checkboxSwitch,$markerArray);
		} else $checkboxSwitch = '';
		$contentFilters = $this->cObj->substituteSubpart($contentFilters, '###SUB_CHECKBOX_SWITCH', $checkboxSwitch);

		// show checkbox reset link only in ajax mode (needs javascript)
		if ($this->conf['renderMethod'] != 'static') {
			$checkboxReset  = $this->cObj->getSubpart($this->templateCode,'###SUB_CHECKBOX_RESET###');
			$markerArray = array(
				'###FILTER_UID###' => $filterUid,
				'###ONCLICK_RESET###' => $this->onclickFilter,
				'###RESET_FILTER###' => $this->pi_getLL('reset_filter'),
			);
			$checkboxReset = $this->cObj->substituteMarkerArray($checkboxReset,$markerArray);
		} else $checkboxReset = '';
		$contentFilters = $this->cObj->substituteSubpart($contentFilters, '###SUB_CHECKBOX_RESET', $checkboxReset);

		// submit checkbox filter link only in ajax mode (needs javascript)
		if ($this->conf['renderMethod'] != 'static') {
			$checkboxSubmit  = $this->cObj->getSubpart($this->templateCode,'###SUB_CHECKBOX_SUBMIT###');
			$markerArray = array(
				'###ONCLICK_RESET###' => $this->onclickFilter,
				'###CHECKBOX_SUBMIT###' => $this->pi_getLL('checkbox_submit'),
			);
			$checkboxSubmit = $this->cObj->substituteMarkerArray($checkboxSubmit,$markerArray);
		} else $checkboxSubmit = '';
		$contentFilters = $this->cObj->substituteSubpart($contentFilters, '###SUB_CHECKBOX_SUBMIT', $checkboxSubmit);

		return $contentFilters;
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
			// if t3lib_extMgm::extPath does not exist (as in TYPO3 6.0 Beta2),
			// assume the default path
			if (function_exists('t3lib_extMgm::extPath')) {
				$path_to_xajax = t3lib_extMgm::extPath('xajax') . 'class.tx_xajax.php';
			} else {
				$path_to_xajax = 'typo3conf/ext/xajax/class.tx_xajax.php';
			}
			require_once($path_to_xajax);
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
		$jsCode = $this->xajax->getJavascript(t3lib_extMgm::siteRelPath('xajax'));
		if ($this->div->getNumericTYPO3versionNumber() >= 6000000) {
			$GLOBALS['TSFE']->getPageRenderer()->addHeaderData($jsCode);
		} else {
			$GLOBALS['TSFE']->additionalHeaderData['xajax_search_filters'] = $jsCode;
		}
	}


	/**
	 * create hide spinner img-tag
	 * this is needed to make results and filters visible in AJAX-Mode
	 *
	 * @return string HTML IMG-Tag
	 */
	public function createHideSpinner() {
		// generate onload image
		$path = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/blank.gif';
		if ($GLOBALS['TSFE']->id != $this->conf['resultPage']) {
			$spinnerFunction = 'hideSpinnerFiltersOnly()';
		} else $spinnerFunction = 'hideSpinner()';
		return $this->cObj->fileResource($path, 'onload="' . $spinnerFunction . ';" alt="" title=""');
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
		$this->onloadImage = $this->createHideSpinner();

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
				$objResponse->addAssign('kesearch_num_results', 'innerHTML', sprintf($this->pi_getLL('num_results'), $this->numberOfResults));
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
		$this->onloadImage = $this->createHideSpinner();

		// set filters
		$objResponse->addAssign('kesearch_filters', 'innerHTML', $this->renderFilters().$this->onloadImage );

		// return response xml
		return $objResponse->getXML();
	}


	/*
	 * function getSearchResults
	 */
	public function getSearchResults() {
		// generate and add onload image
		$this->onloadImage = $this->createHideSpinner();

		$limit = $this->db->getLimit();
		$rows = $this->db->getSearchResults();
		$rows = array_slice($rows, $limit[0], $limit[1]);
		$this->numberOfResults = $this->db->getAmountOfSearchResults();

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
					$_procObj->noResultsHandler($noResultsText, $this);
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
			$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/attention.gif';
			$imageConf['altText'] = $this->pi_getLL('no_results_found');
			$attentionImage=$this->cObj->IMAGE($imageConf);

			// set attention icon?
			$content = $this->cObj->substituteMarker($content, '###IMAGE###', $attentionImage);
		}

		// loop through results
		// init results counter
		$resultCount = 1;
		$this->searchResult = t3lib_div::makeInstance('tx_kesearch_lib_searchresult', $this);
		foreach($rows as $row) {
			// generate row content
			$tempContent = $this->cObj->getSubpart($this->templateCode, '###RESULT_ROW###');
			$this->searchResult->setRow($row);

			$tempMarkerArray = array(
				'title' => $this->searchResult->getTitle(),
				'teaser' => $this->searchResult->getTeaser(),
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

			// add type marker
			$tempMarkerArray['type'] = str_replace(' ', '_', $row['type']);

			// replace markers
			$tempContent = $this->cObj->substituteMarkerArray($tempContent, $tempMarkerArray, $wrap='###|###', $uppercase=1);

			// show result url?
			if ($this->conf['showResultUrl']) {
				$subContent = $this->cObj->getSubpart($this->templateCode, '###SUB_RESULTURL###');
				$subContent = $this->cObj->substituteMarker($subContent, '###LABEL_RESULTURL###', $this->pi_getLL('label_resulturl'));
				$subContent = $this->cObj->substituteMarker($subContent, '###RESULTURL###', $this->searchResult->getResultUrl($this->conf['renderResultUrlAsLink']));
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart($tempContent, '###SUB_RESULTURL###', $subContent, $recursive=1);

			// show result numeration?
			if ($this->conf['resultsNumeration']) {
				$subContent = $this->cObj->getSubpart($this->templateCode, '###SUB_NUMERATION###');
				$subContent = $this->cObj->substituteMarker($subContent, '###NUMBER###', $resultCount + ($this->piVars['page'] * $this->conf['resultsPerPage']) - $this->conf['resultsPerPage']);
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart($tempContent, '###SUB_NUMERATION###', $subContent, $recursive=1);

			// show score?
			if ($this->conf['showScore'] && $row['score']) {
				$subContent = $this->cObj->getSubpart($this->templateCode, '###SUB_SCORE###');
				$subContent = $this->cObj->substituteMarker($subContent, '###LABEL_SCORE###', $this->pi_getLL('label_score'));
				$subContent = $this->cObj->substituteMarker($subContent, '###SCORE###', number_format($row['score'],2,',',''));
			} else {
				$subContent = '';
			}
			$tempContent = $this->cObj->substituteSubpart($tempContent, '###SUB_SCORE###', $subContent, $recursive=1);

			// show date?
			if ($this->conf['showDate'] && $row['sortdate']) {
				$subContent = $this->cObj->getSubpart($this->templateCode, '###SUB_DATE###');
				$subContent = $this->cObj->substituteMarker($subContent, '###LABEL_DATE###', $this->pi_getLL('label_date'));
				$subContent = $this->cObj->substituteMarker($subContent, '###DATE###', date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], $row['sortdate']));
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
				$subContent = $this->cObj->getSubpart($this->templateCode, '###SUB_SCORE_SCALE###');
				$subContent = $this->cObj->substituteMarker($subContent, '###SCORE###', $row['percent']);
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

		// hook for third party pagebrowsers or for modification of build in browser
		// if the hook return content then return that content
		$content = '';
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['renderPagebrowserInit'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['renderPagebrowserInit'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$content = $_procObj->renderPagebrowserInit($this);
			}
		}
		if($content) {
			return $content;
		}

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
				$linkconf['additionalParams'] = '&tx_kesearch_pi1[page]=' . intval($i);
				$filterArray = $this->filters->getFilters();

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
				$tempContent .= $this->cObj->typoLink($i, $linkconf) . ' ';
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
			$filterArray = $this->filters->getFilters();

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
			$filterArray = $this->filters->getFilters();

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

		// hook for additional markers in pagebrowse
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['pagebrowseAdditionalMarker'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['pagebrowseAdditionalMarker'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->pagebrowseAdditionalMarker(
					$markerArray,
					$this
				);
			}
		}

		$content = $this->cObj->substituteMarkerArray($content,$markerArray,$wrap='###|###',$uppercase=1);

		return $content;
	}


	public function renderOrdering() {
		$sortObj = t3lib_div::makeInstance('tx_kesearch_lib_sorting', $this);
		return $sortObj->renderSorting();
	}


	/**
	 * renders an image tag which will prepend the teaser if activated by user.
	 *
	 * @param $type string A value like page, dam, tt_address
	 */
	public function renderTypeIcon($type) {
		$type = $this->div->removeXSS($type);
		$imageConf['file'] = str_replace(PATH_site, '', t3lib_div::getFileAbsFileName($this->conf['additionalPathForTypeIcons'] . $type . '.gif'));
		if(!is_file(PATH_site . $imageConf['file'])) {
			$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey) . 'res/img/types/' . $type . '.gif';
		}
		return $this->cObj->IMAGE($imageConf);
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
		$filters = $this->filters->getFilters();
		$filterSet = false;
		if(is_array($filters))  {
			//TODO: piVars filter is a multidimensional array
			foreach($filters as $filter)  {
				if(!empty($this->piVars['filter'][$filter['uid']])) $filterSet = true;
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
		if ($this->div->getNumericTYPO3versionNumber() >= 6000000) {
			$GLOBALS['TSFE']->getPageRenderer()->addHeaderData($content);
		} else {
			$GLOBALS['TSFE']->additionalHeaderData['jsContent'] = $content;
		}
	}


	public function sortArrayRecursive($array, $field) {

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

	/**
	 * implements a recursive in_array function
	 *
	 * @param mixed $needle
	 * @param array $array
	 * @return boolean
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 11.07.12
	 */
	public function in_multiarray($needle, $haystack) {
		foreach ($haystack as $value) {
			if (is_array($value)) {
				if ($this->in_multiarray($needle, $value)) {
					return true;
				}
			} else if ($value == $needle) {
				return true;
			}
		}
		return false;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_lib.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_lib.php']);
}
?>