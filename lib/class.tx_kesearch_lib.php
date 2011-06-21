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
	var $prefixId           = 'tx_kesearch_pi1';		// Same as class name
	var $extKey             = 'ke_search';	// The extension key.

	var $sword              = ''; // cleaned searchword (karl-heinz => karl heinz)
	var $swords             = ''; // searchwords as array
	var $wordsAgainst       = ''; // searchphrase for boolean mode (+karl* +heinz*)
	var $tagsAgainst        = ''; // tagsphrase for boolean mode (+#category_213# +#city_42#)
	var $scoreAgainst       = ''; // searchphrase for score/non boolean mode (karl heinz)
	var $isEmptySearch      = true; // true if no searchparams given; otherwise false

	var $templateFile       = ''; // Template file
	var $templateCode       = ''; // content of template file

	var $startingPoints     = 0; // comma seperated list of startingPoints
	var $firstStartingPoint = 0; // comma seperated list of startingPoints
	var $UTF8QuirksMode     = false; // if set non utf8 values will be converted to utf8
	var $conf               = array(); // FlexForm-Configuration
	var $extConf            = array(); // Extension-Configuration
	var $numberOfResults    = 0; // count search results
	var $indexToUse         = ''; // it's for 'USE INDEX ($indexToUse)' to speed up queries
	var $tagsInSearchResult = false; // contains all tags of current search result
	var $preselectedFilter  = array(); // preselected filters by flexform

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
	 * Initializes flexform, conf vars and some more
	 *
	 * @return nothing
	 */
	protected function init() {
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

		// get preselected filter from rootline
		$this->getFilterPreselect();

		// prepare database object
		$this->db = t3lib_div::makeInstance('tx_kesearch_db', $this);

		// add stdWrap properties to each config value
		foreach($this->conf as $key => $value) {
			$this->conf[$key] = $this->cObj->stdWrap($value, $this->conf[$key . '.']);
		}

		// set some default values (this part have to be after stdWrap!!!)
		if(!$this->conf['resultPage']) $this->conf['resultPage'] = $GLOBALS['TSFE']->id;
		if(!isset($this->piVars['page'])) $this->piVars['page'] = 1;

		// hook: modifyFlexFormData
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFlexFormData'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFlexFormData'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->modifyFlexFormData($this->conf, $this->cObj, $this->piVars);
			}
		}

		// set startingPoints
		$this->startingPoints = $this->div->getStartingPoint();

		// get extension configuration array
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
		$this->UTF8QuirksMode = $this->extConf['useUTF8QuirksMode'];
		$this->extConf['multiplyValueToTitle'] = ($this->extConf['multiplyValueToTitle']) ? $this->extConf['multiplyValueToTitle'] : 1;

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

		// precount results to find the best index
		$this->db->chooseBestIndex($this->wordsAgainst, $this->tagsAgainst);
	}


	/**
	 * Move all FlexForm data of current record to conf array
	 */
	protected function moveFlexFormDataToConf() {
		// don't move this to init
		$this->pi_initPIflexForm();

		$piFlexForm = $this->cObj->data['pi_flexform'];
		if(is_array($piFlexForm['data'])) {
			foreach($piFlexForm['data'] as $sheetKey => $sheet) {
				foreach($sheet as $lang) {
					foreach($lang as $key => $value) {
						$this->conf[$key] = $this->fetchConfigurationValue($key, $sheetKey);
					}
				}
			}
		}
	}


	/*
	 * function initOnclickActions
	 */
	protected function initOnclickActions() {

		switch ($this->conf['renderMethod']) {

			// AJAX after reload version
			case 'ajax_after_reload':

				// set pagebrowser onclick
				$this->onclickPagebrowser = 'pagebrowserAction(); ';

				// $this->onclickFilter = 'this.form.submit();';
				$this->onclickFilter = 'document.getElementById(\'xajax_form_kesearch_pi1\').submit();';

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
	protected function getSearchboxContent() {

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



		// get filters
		$content = $this->cObj->substituteMarker($content, '###FILTER###', $this->renderFilters());

		// set form action pid
		$content = $this->cObj->substituteMarker($content,'###FORM_TARGET_PID###', $this->conf['resultPage']);
		// set form action
		$content = $this->cObj->substituteMarker($content,'###FORM_ACTION###', t3lib_div::getIndpEnv('TYPO3_SITE_URL'));

		// set other hidden fields
		$hiddenFieldsContent = '';
		// language parameter
		$lParam = t3lib_div::_GET('L');
		if (isset($lParam)) {
			$hiddenFieldValue = $this->div->removeXSS($lParam);
			$hiddenFieldsContent .= '<input type="hidden" name="L" value="'.$hiddenFieldValue.'" />';
		}
		// mountpoint parameter
		$mpParam = t3lib_div::_GET('MP');
		if (isset($mpParam)) {
			$hiddenFieldValue = t3lib_div::_GET('MP');
			$hiddenFieldValue = $this->div->removeXSS($hiddenFieldValue);
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
	protected function renderFilters() {

		// get filters from db
		$this->filters = $this->getFiltersFromFlexform();

		if (!empty($this->conf['filters'])) {
			$filterList = explode(',', $this->conf['filters']);

			foreach ($filterList as $key => $filterUid) {

				unset($options);

				// current filter has options
				if (!empty($this->filters[$filterUid]['options'])) {

					// get filter options
					$fields = '*';
					$table = 'tx_kesearch_filteroptions';
					$where = 'uid in ('.$this->filters[$filterUid]['options'].')';
					$where .= ' AND pid in ('.$this->startingPoints.')';
					$where .= $this->cObj->enableFields($table);
					$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');

					// loop through filteroptions
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

						// reset options count
						$optionsCount = 0;

						// check filter availability?
						if ($this->conf['checkFilterCondition'] != 'none') {
							if ($this->checkIfTagMatchesRecords($row['tag'],$this->conf['checkFilterCondition'], $filterUid)) {
								// process check in condition to other filters or without condition

								// selected / preselected?
								$selected = 0;

								if ($this->piVars['filter'][$filterUid] == $row['tag']) {
									$selected = 1;
								} else if (is_array($this->piVars['filter'][$filterUid])) {
									if(t3lib_div::inArray($this->piVars['filter'][$filterUid], $row['tag'])) {
										$selected = 1;
									}
								} else if (!isset($this->piVars['filter'][$filterUid]) && !is_array($this->piVars['filter'][$filterUid])) {
									if (is_array($this->preselectedFilter) && in_array($row['tag'], $this->preselectedFilter)) {
										$selected = 1;
										$this->piVars['filter'][$filterUid] = $row['tag'];
									}
								}
								$options[$row['uid']] = array(
									'title' => $row['title'],
									'value' => $row['tag'],
									'selected' => $selected,
								);
								$optionsCount++;
							}
						} else {
							// do not process check; show all filters
							$options[$row['uid']] = array(
								'title' => $row['title'],
								'value' => $row['tag'],
								'selected' => $selected,
							);
							$optionsCount++;
						}
					}
				}

				// sorting of options as set in filter record by IRRE
				$sorting = t3lib_div::trimExplode(',', $this->filters[$filterUid]['options'], true);
				foreach ($sorting as $key => $uid) {
					if (is_array($options[$uid])) {
						$sortedOptions[] = $options[$uid];
					}
				}
				$options = $sortedOptions;
				unset($sortedOptions);

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
	protected function renderSelect($filterUid, $options) {

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
	protected function renderList($filterUid, $options) {
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

		if ($this->UTF8QuirksMode) $filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', utf8_encode($this->filters[$filterUid]['title']));
		else $filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $this->filters[$filterUid]['title']);

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




	/*
	 * function renderCheckbox
	 * @param $arg
	 */
	protected function renderCheckbox($filterUid, $options) {

		$filters = $this->getFiltersFromFlexform();
		$allOptionsOfCurrentFilter = $this->getFilterOptions($filters[$filterUid]['options']);

		$filterSubpart = '###SUB_FILTER_CHECKBOX###';
		$optionSubpart = '###SUB_FILTER_CHECKBOX_OPTION###';

		$optionsCount = 0;

		// loop through options
		if(is_array($allOptionsOfCurrentFilter)) {
			foreach($allOptionsOfCurrentFilter as $key => $data) {
				$checkBoxParams['selected'] = '';
				$checkBoxParams['disabled'] = '';

				// check if current option (of searchresults) is in array of all possible options
				$isOptionInOptionArray = 0;
				foreach($options as $optionKey => $optionValue) {
					if(is_array($options[$optionKey]) && t3lib_div::inArray($options[$optionKey], $data['title'])) {
						$isOptionInOptionArray = 1;
						break;
					}
				}
				
				// if option is in optionArray, we have to mark the checkboxes
				// but only if customer has searched for filters
				if($isOptionInOptionArray) {
					$checkBoxParams['selected'] = ($this->piVars['filter'][$filterUid][$key]) ? 'checked="checked"' : '';
					if(!is_array($this->piVars['filter'][$filterUid]) && $this->filters[$filterUid]['markAllCheckboxes']) {
						$checkBoxParams['selected'] = 'checked="checked"';
					}
					$checkBoxParams['disabled'] = '';
				} else {
					$checkBoxParams['disabled'] = 'disabled="disabled"';
				}

				$optionsContent .= $this->cObj->getSubpart($this->templateCode, $optionSubpart);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###TITLE###', $data['title']);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###VALUE###', $data['tag']);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONKEY###', $key);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONID###', 'filter[' . $filterUid . '][' . $key . ']');
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONCSSCLASS###', 'optionCheckBox optionCheckBox' . $key);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONNAME###', 'optionCheckBox' . $filterUid);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONSELECT###', $checkBoxParams['selected']);
				$optionsContent = $this->cObj->substituteMarker($optionsContent,'###OPTIONDISABLED###', $checkBoxParams['disabled']);

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

		if ($this->UTF8QuirksMode) $filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', utf8_encode($this->filters[$filterUid]['title']));
		else $filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $this->filters[$filterUid]['title']);

		$filterContent = $this->cObj->substituteMarker($filterContent,'###LABEL_ALL###', $this->pi_getLL('label_all'));
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERTITLE###', $this->filters[$filterUid]['title']);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERNAME###', 'tx_kesearch_pi1[filter]['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTERID###', 'filter['.$filterUid.']');
		$filterContent = $this->cObj->substituteMarker($filterContent,'###FILTER_UID###', $filterUid);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCHANGE###', $this->onclickFilter);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###ONCLICK_RESET###', $this->onclickFilter);
		$filterContent = $this->cObj->substituteMarker($filterContent,'###DISABLED###', $optionsCount > 0 ? '' : ' disabled="disabled" ');

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


	/*
	 * function checkIfFilterMatchesRecords
	 */
	public function checkIfTagMatchesRecords($tag, $mode='multi', $filterId) {

		// get all tags of current searchresult
		if(!is_array($this->tagsInSearchResult)) {

			$fields = 'uid';
			$table = 'tx_kesearch_index';
			$where = '1=1';
			$countMatches = 0;
			if($this->tagsAgainst) {
				$where .= ' AND MATCH (tags) AGAINST (\''.$this->tagsAgainst.'\' IN BOOLEAN MODE) ';
				$countMatches++;
			}
			if(count($this->swords)) {
				$where .= ' AND MATCH (content) AGAINST (\''.$this->wordsAgainst.'\' IN BOOLEAN MODE) ';
				$countMatches++;
			}
			$where .= $this->cObj->enableFields($table);

			// which index to use
			if($countMatches == 2) {
				$index = ' USE INDEX (' . $this->indexToUse . ')';
			} else $index = '';

			$query = $GLOBALS['TYPO3_DB']->SELECTquery(
				'uid, REPLACE(tags, "##", "#~~~#") as tags',
				'tx_kesearch_index' . $index,
				$where,
				'','',''
			);

			if(t3lib_extMgm::isLoaded('ke_search_premium') && !$this->isEmptySearch) {
				require_once(t3lib_extMgm::extPath('ke_search_premium') . 'class.user_kesearchpremium.php');
				$sphinx = t3lib_div::makeInstance('user_kesearchpremium');
				$queryForShinx = '';
				if($this->wordsAgainst) $queryForShinx .= ' @(title,content) ' . $this->wordsAgainst;
				if($this->tagsAgainst) $queryForShinx .= ' @(tags) ' . implode(' ', $this->tagsAgainst);
				$res = $sphinx->getResForSearchResults($queryForShinx, '*', 'uid, REPLACE(tags, "##", "#~~~#") as tags');
			} else {
				$res = $GLOBALS['TYPO3_DB']->sql_query($query);
			}

			while($tags = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
				foreach(explode('~~~', $tags['tags']) as $value) {
					$tagTempArray[] = $value;
				}
			}

			// the following is much faster than array_unique()
			$tagArray = array();
			foreach($tagTempArray as $key => $val) {
				$tagArray[$val] = true;
			}
			$this->tagsInSearchResult = array_keys($tagArray);
		}

		if(array_search('#' . $tag . '#', $this->tagsInSearchResult) === false) {
			return false;
		} else {
			return true;
		}
	}


	/**
	 * get all filters configured in FlexForm
	 *
	 * @return array Array with filter UIDs
	 */
	protected function getFiltersFromFlexform() {
		if(!empty($this->conf['filters'])) {
			$fields = '*';
			$table = 'tx_kesearch_filters';
			$where = 'pid IN (' . $this->startingPoints . ')';
			$where .= 'AND uid IN (' . $this->conf['filters'] . ')';
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
				$results[$row['uid']] = $row;
			}
		}
		return $results;
	}

	/**
	 * get optionrecords of given list of option-IDs
	 *
	 * @param string $optionList
	 * @return array Filteroptions
	 */
	protected function getFilterOptions($optionList) {
		// check/convert if list contains only integers
		$optionIdArray = t3lib_div::intExplode(',', $optionList, true);
		$optionList = implode(',', $optionIdArray);

		// search for filteroptions
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'*',
			'tx_kesearch_filteroptions',
			'pid in ('.$this->startingPoints.') ' .
			'AND uid in (' . $optionList . ') ' .
			$this->cObj->enableFields('tx_kesearch_filteroptions'),
			'', '', ''
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
			$optionArray[] = $row;
		}

		return $optionArray;
	}


	/**
	 * Init XAJAX
	 */
	protected function initXajax()	{
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
					$this->piVars[$key][$subkey] = $this->div->removeXSS($subtag);
				}
			} else {
				$this->piVars[$key] = $this->div->removeXSS($value);
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
			$objResponse->addAssign("kesearch_results", "innerHTML", $this->pi_RTEcssText($this->conf['textForResults']));
			$objResponse->addAssign("kesearch_query_time", "innerHTML", '');
			$objResponse->addAssign("kesearch_ordering", "innerHTML", '');
			$objResponse->addAssign("kesearch_pagebrowser_top", "innerHTML", '');
			$objResponse->addAssign("kesearch_pagebrowser_bottom", "innerHTML", '');
			$objResponse->addAssign("kesearch_updating_results", "innerHTML", '');
			$objResponse->addAssign("kesearch_filters", "innerHTML", $this->renderFilters() . $this->onloadImage);
		} else {
			// set search results
			// process if on result page
			$start = t3lib_div::milliseconds();
			if ($GLOBALS['TSFE']->id == $this->conf['resultPage']) {
				$objResponse->addAssign('kesearch_results', 'innerHTML', $this->getSearchResults() . $this->onloadImage);
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

				if ($this->UTF8QuirksMode) $objResponse->addAssign("kesearch_error", "innerHTML", utf8_encode($errorMessage));
				else $objResponse->addAssign("kesearch_error", "innerHTML", $errorMessage);

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
			$this->piVars[$key] = $this->div->removeXSS($value);
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
	protected function getSearchResults() {
		// get search results
		$query = $this->db->generateQueryForSearch();
		//t3lib_div::devLog('db', 'db', -1, array('Query: ' . $query));

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
			$sphinx = t3lib_div::makeInstance('user_kesearchpremium');

			// set ordering
			$sphinx->setSorting($this->db->getOrdering());

			// set limit
			$limit = $this->db->getLimit();
			$sphinx->setLimit($limit[0], $limit[1]);

			// generate query
			$queryForShinx = '';
			if($this->wordsAgainst) $queryForShinx .= ' @(title,content) ' . $this->wordsAgainst;
			if($this->tagsAgainst) $queryForShinx .= ' @(tags) ' . implode(' ', $this->tagsAgainst);
			$res = $sphinx->getResForSearchResults($queryForShinx);
			// get number of records
			$this->numberOfResults = $sphinx->getTotalFound();
		} else {
			$res = $GLOBALS['TYPO3_DB']->sql_query($query);
			// get number of records
			$this->numberOfResults = $this->db->getAmountOfSearchResults();
		}

		// Calculate Querytime
		// we have two plugin. That's why we work with register here.
		$GLOBALS['TSFE']->register['ke_search_queryTime'] = (t3lib_div::milliseconds() - $GLOBALS['TSFE']->register['ke_search_queryStartTime']);

		// count searchword with ke_stats
		$this->countSearchWordWithKeStats($this->sword);

		// count search phrase in ke_search statistic tables
		if ($this->conf['countSearchPhrases']) {
			$this->countSearchPhrase($this->sword, $this->swords, $this->numberOfResults, $this->tagsAgainst);
		}

		if($this->numberOfResults == 0) {

			// get subpart for general message
			$content = $this->cObj->getSubpart($this->templateCode, '###GENERAL_MESSAGE###');

			// check if searchwords were too short
			if(!count($this->swords)) {
				$content = $this->cObj->substituteMarker($content, '###MESSAGE###', $this->pi_getLL('searchword_length_error'));
			}

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
			// set text for "no results found"
			$content = $this->cObj->substituteMarker($content,'###MESSAGE###', $noResultsText);
			// set attention icon?
			$content = $this->cObj->substituteMarker($content,'###IMAGE###', $attentionImage);

			// add query
			if ($this->conf['showQuery']) {
				$content .= '<br />'.$query.'<br />';
			}

			// add onload image
			$content .= $this->onloadImage;

			return $content;
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

		// add onload image
		$content .= $this->onloadImage;

		if ($this->UTF8QuirksMode) return utf8_encode($content);
		else return $content;
	}



	/**
 	* Counts searchword and -phrase if ke_stats is installed
 	*
 	* @param   string $searchphrase
 	* @return  void
 	* @author  Christian Buelter <buelter@kennziffer.com>
 	* @since   Tue Mar 01 2011 12:34:25 GMT+0100
 	*/
	protected function countSearchWordWithKeStats($searchphrase='') {

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
	protected function buildTeaserContent($resultText) {

		// calculate substring params
		// switch through all swords and use first word found for calculating
		$resultPos = 0;
		if(count($this->swords)) {
			for($i = 0; $i < count($this->swords); $i++) {
				$newResultPos = intval(stripos($resultText, $this->swords[$i]));
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
	protected function fetchConfigurationValue($param, $sheet = 'sDEF') {
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
	protected function betterSubstr($str, $length, $minword = 3) {
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
	protected function renderPagebrowser() {

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
						$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.']='.$this->piVars['filter'][$filterId];
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
					$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.']='.$this->piVars['filter'][$filterId];
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
					$linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter]['.$filterId.']='.$this->piVars['filter'][$filterId];
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


	protected function renderOrdering() {
		// show ordering only if is set in FlexForm
		if($this->conf['showSortInFrontend'] && $this->conf['sortByVisitor'] != '' && $this->numberOfResults) {
			$subpartArray['###ORDERNAVIGATION###'] = $this->cObj->getSubpart($this->templateCode, '###ORDERNAVIGATION###');
			$subpartArray['###SORT_LINK###'] = $this->cObj->getSubpart($subpartArray['###ORDERNAVIGATION###'], '###SORT_LINK###');

			if($this->conf['showSortInFrontend']) {
				$orderByDir = strtolower($this->div->removeXSS($this->piVars['orderByDir']));
				$orderByField = strtolower($this->div->removeXSS($this->piVars['orderByField']));
				if(!$orderByField) {
					$orderByField = $this->conf['sortWithoutSearchword'];
					$orderByField = str_replace(' UP', '', $this->conf['sortWithoutSearchword']);
					$orderByField = str_replace(' DOWN', '', $this->conf['sortWithoutSearchword']);
				}
				if($orderByDir != 'desc' && $orderByDir != 'asc') $orderByDir = 'desc';
				if($orderByDir == 'desc') {
					$orderByDir = 'asc';
				} else {
					$orderByDir = 'desc';
				}
			} else {
				$orderByDir = 'desc';
			}

			$orderBy = t3lib_div::trimExplode(',', $this->conf['sortByVisitor']);

			// loop all allowed orderings
			foreach($orderBy as $value) {
				// we can't sort by score if there is no sword given
				if($this->sword != '' || $value != 'score') {
					$markerArray['###FIELDNAME###'] = $value;

					// generate link for static and after reload mode
					$markerArray['###URL###'] = $this->cObj->typoLink(
						$this->pi_getLL('orderlink_' . $value, $value),
						array(
							'parameter' => $GLOBALS['TSFE']->id,
							'addQueryString' => 1,
							'additionalParams' => '&' . $this->prefixId . '[orderByField]=' . $value . '&' . $this->prefixId . '[orderByDir]=' . $orderByDir,
							'section' => 'kesearch_ordering'
						)
					);

					// add classname for sorting arrow
					if($value == $orderByField) {
						if($orderByDir == 'asc') {
							$markerArray['###CLASS###'] = 'down';
						} else {
							$markerArray['###CLASS###'] = 'up';
						}
					} else {
						$markerArray['###CLASS###'] = '';
					}

					$links .= $this->cObj->substituteMarkerArray($subpartArray['###SORT_LINK###'], $markerArray);
				}
			}

			$content = $this->cObj->substituteSubpart($subpartArray['###ORDERNAVIGATION###'], '###SORT_LINK###', $links);
			$content = $this->cObj->substituteMarker($content, '###LABEL_SORT###', $this->pi_getLL('label_sort'));

			return $content;
		} else {
			return '';
		}
	}



	/*
	 * function renderSVGScale
	 * @param $arg
	 */
	protected function renderSVGScale($percent) {
		$svgScriptPath = t3lib_extMgm::siteRelPath($this->extKey).'res/scripts/SVG.php?p='.$percent;
		return '<embed src="'.$svgScriptPath.'" style="margin-top: 5px;" width="50" height="12" type="image/svg+xml"	pluginspage="http://www.adobe.com/svg/viewer/install/" />';
	}


	/*
	 * function renderTypeIcon
	 * @param $type string
	 */
	protected function renderTypeIcon($type) {
		$type = $this->div->removeXSS($type);
		unset($imageConf);
		$imageConf['file'] = t3lib_extMgm::siteRelPath($this->extKey).'res/img/types/'.$type.'.gif';
		$image=$this->cObj->IMAGE($imageConf);
		return $image;
	}

	/*
	 * function initDomReadyAction
	 */
	protected function initDomReadyAction() {

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
	protected function countSearchPhrase($searchPhrase, $searchWordsArray, $hits, $tagsAgainst) {

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


	/*
	 * function getFilterPreselect
	 */
	protected function getFilterPreselect() {
		// get definitions from plugin settings
		if($this->conf['preselected_filters']) {
			$preselectedArray = t3lib_div::trimExplode(',', $this->conf['preselected_filters'], true);
			foreach ($preselectedArray as $key => $option) {
				$fields = '*, tx_kesearch_filters.uid as filteruid';
				$table = 'tx_kesearch_filters, tx_kesearch_filteroptions';
				$where = $GLOBALS['TYPO3_DB']->listQuery('options', $option, 'tx_kesearch_filters');
				$where .= ' AND tx_kesearch_filteroptions.uid = '.$option;
				$where .= $this->cObj->enableFields('tx_kesearch_filters');
				$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
				while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
					$this->preselectedFilter[$row['filteruid']] = $row['tag'];
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
	protected function isEmptySearch() {
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
	protected function addHeaderParts() {
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

		// minify JS?
		if(version_compare(TYPO3_version, '4.2.0', '>=')) {
			$content = t3lib_div::minifyJavaScript($content);
		}

		// add JS to page header
		$GLOBALS['TSFE']->additionalHeaderData['jsContent'] = $content;
	}
}
?>
