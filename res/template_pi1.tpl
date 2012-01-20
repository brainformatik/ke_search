
<!-- ###JS_SEARCH_ALL### begin -->
<script type="text/javascript">
//<![CDATA[
// js for all render methods
function searchboxFocus(searchbox) {
	if(searchbox.value == "###SEARCHBOX_DEFAULT_VALUE###") {
		searchbox.value = "";
	}
}

function enableCheckboxes(filter) {
	allLi = document.getElementsByName("optionCheckBox" + filter);
	allCb = new Array();
	for(i = 0; i < allLi.length; i++) {
		allCb[i] = allLi[i].getElementsByTagName("input");
	}
	allCbChecked = true;
	for(i = 0; i < allCb.length; i++) {
		if(!allCb[i][0].checked) {
			allCbChecked = false;
		}
	}
	if(allCbChecked) {
		for(i = 0; i < allCb.length; i++) {
			allCb[i][0].checked = false;
		}
	} else {
		for(i = 0; i < allCb.length; i++) {
			allCb[i][0].checked = true;
		}
	}
}
//]]>
</script>
<!-- ###JS_SEARCH_ALL### end -->

<!-- ###JS_SEARCH_NON_STATIC### begin -->
<script type="text/javascript">
//<![CDATA[
function switchArea(objid) {
	if (document.getElementById("options_" + objid).className == "expanded") {
		document.getElementById("options_" + objid).className = "closed";
		document.getElementById("bullet_" + objid).src="###SITE_REL_PATH###res/img/list-head-closed.gif";
	} else {
		document.getElementById("options_" + objid).className = "expanded";
		document.getElementById("bullet_" + objid).src="###SITE_REL_PATH###res/img/list-head-expanded.gif";
	}
}

function hideSpinnerFiltersOnly() {
	document.getElementById("kesearch_filters").style.display="block";
	document.getElementById("kesearch_updating_filters").style.display="none";
	document.getElementById("resetFilters").value=0;
}

function pagebrowserAction() {
	document.getElementById("kesearch_results").style.display="none";
	document.getElementById("kesearch_updating_results").style.display="block";
	document.getElementById("kesearch_pagebrowser_top").style.display="none";
	document.getElementById("kesearch_pagebrowser_bottom").style.display="none";
	document.getElementById("kesearch_query_time").style.display="none";
}

// refresh result list onload
function onloadFilters() {
	document.getElementById("kesearch_filters").style.display="none";
	document.getElementById("kesearch_updating_filters").style.display="block";
	tx_kesearch_pi1refreshFiltersOnLoad(xajax.getFormValues("xajax_form_kesearch_pi1"));
}
//]]>
</script>
<!-- ###JS_SEARCH_NON_STATIC### end -->

<!-- ###JS_SEARCH_AJAX_RELOAD### begin -->
<script type="text/javascript">
//<![CDATA[
// refresh result list onload
function onloadResults() {
	document.getElementById("kesearch_pagebrowser_top").style.display="none";
	document.getElementById("kesearch_pagebrowser_bottom").style.display="none";
	document.getElementById("kesearch_results").style.display="none";
	document.getElementById("kesearch_updating_results").style.display="block";
	document.getElementById("kesearch_query_time").style.display="none";
	tx_kesearch_pi1refreshResultsOnLoad(xajax.getFormValues("xajax_form_kesearch_pi1"));
}

function onloadFiltersAndResults() {
	document.getElementById("kesearch_filters").style.display="none";
	document.getElementById("kesearch_updating_filters").style.display="block";
	document.getElementById("kesearch_results").style.display="none";
	document.getElementById("kesearch_updating_results").style.display="block";
	document.getElementById("kesearch_pagebrowser_top").style.display="none";
	document.getElementById("kesearch_pagebrowser_bottom").style.display="none";
	document.getElementById("kesearch_query_time").style.display="none";
	tx_kesearch_pi1refresh(xajax.getFormValues("xajax_form_kesearch_pi1"));
}

function hideSpinner() {
	document.getElementById("kesearch_filters").style.display="block";
	document.getElementById("kesearch_updating_filters").style.display="none";
	document.getElementById("kesearch_results").style.display="block";
	document.getElementById("kesearch_updating_results").style.display="none";
	document.getElementById("kesearch_pagebrowser_top").style.display="block";
	document.getElementById("kesearch_pagebrowser_bottom").style.display="block";
	document.getElementById("kesearch_query_time").style.display="block";
}


// domReady function
!function (context, doc) {
  var fns = [], ol, f = false,
      testEl = doc.documentElement,
      hack = testEl.doScroll,
      domContentLoaded = 'DOMContentLoaded',
      addEventListener = 'addEventListener',
      onreadystatechange = 'onreadystatechange',
      loaded = /^loade|c/.test(doc.readyState);

  function flush(i) {
    loaded = 1;
    while (i = fns.shift()) { i() }
  }
  doc[addEventListener] && doc[addEventListener](domContentLoaded, function fn() {
    doc.removeEventListener(domContentLoaded, fn, f);
    flush();
  }, f);


  hack && doc.attachEvent(onreadystatechange, (ol = function ol() {
    if (/^c/.test(doc.readyState)) {
      doc.detachEvent(onreadystatechange, ol);
      flush();
    }
  }));

  context['domReady'] = hack ?
    function (fn) {
      self != top ?
        loaded ? fn() : fns.push(fn) :
        function () {
          try {
            testEl.doScroll('left');
          } catch (e) {
            return setTimeout(function() { domReady(fn) }, 50);
          }
          fn();
        }()
    } :
    function (fn) {
      loaded ? fn() : fns.push(fn);
    };

}(this, document);

// domReadyAction
###DOMREADYACTION###
//]]>
</script>
<!-- ###JS_SEARCH_AJAX_RELOAD### end -->

<!-- ###SEARCHBOX_STATIC### start -->
<form method="get" id="xajax_form_kesearch_pi1" name="xajax_form_kesearch_pi1"  action="###FORM_ACTION###" class="static" ###ONSUBMIT###>
	<input type="hidden" name="id" value="###FORM_TARGET_PID###" />
	###HIDDENFIELDS###

	<div class="kesearchbox">
		<input type="text" id="ke_search_sword" name="tx_kesearch_pi1[sword]"  value="###SWORD_VALUE###" onfocus="###SWORD_ONFOCUS###" /></b>
		<input type="image" id="kesearch_submit" src="typo3conf/ext/ke_search/res/img/kesearch_submit.png" class="submit" value="###SUBMIT_VALUE###" onclick="document.getElementById('pagenumber').value=1; document.getElementById('xajax_form_kesearch_pi1').submit();" />
		<div class="clearer">&nbsp;</div>
	</div>

	<input id="pagenumber" type="hidden" name="tx_kesearch_pi1[page]" value="###HIDDEN_PAGE_VALUE###" />
	<input id="resetFilters" type="hidden" name="tx_kesearch_pi1[resetFilters]" value="0" />
	<input id="sortByField" type="hidden" name="tx_kesearch_pi1[sortByField]" value="###SORTBYFIELD###" />
	<input id="sortByDir" type="hidden" name="tx_kesearch_pi1[sortByDir]" value="###SORTBYDIR###" />

	<div id="kesearch_filters">###FILTER###</div>
	<!-- ###SHOW_SPINNER### begin -->
	<div id="kesearch_updating_filters"><center>###SPINNER###<br /></center></div>
	<!-- ###SHOW_SPINNER### end -->
	###RESET###
</form>
<!-- ###SEARCHBOX_STATIC### end -->


<!-- ###RESULT_LIST### start -->
	<span id="kesearch_error"></span>
	<div id="kesearch_num_results">###NUMBER_OF_RESULTS###</div>

	<div id="kesearch_pagebrowser_top">###PAGEBROWSER_TOP###</div>
	<div id="kesearch_ordering">###ORDERING###</div>
	
	<div id="kesearch_results">###MESSAGE###</div>

	<div id="kesearch_updating_results"><center>###SPINNER###<br /></center></div>
	<div id="kesearch_pagebrowser_bottom">###PAGEBROWSER_BOTTOM###</div>
	<!-- ###SUB_QUERY_TIME### start -->
	<div id="kesearch_query_time">###QUERY_TIME###</div>
	<!-- ###SUB_QUERY_TIME### end -->
<!-- ###RESULT_LIST### end -->


<!-- ###PAGEBROWSER### start -->
<div class="pages_total">
	###RESULTS### ###START### ###UNTIL### ###END### ###OF### ###TOTAL###<br />
	<table cellpadding="2" align="center">
		<tr>
			<td nowrap="nowrap">###PREVIOUS###</td>
			<td nowrap="nowrap">###PAGES_LIST###</td>
			<td nowrap="nowrap">###NEXT###</td>
		</tr>
	</table>
</div>
<!-- ###PAGEBROWSER### end -->


<!-- ###ORDERNAVIGATION### start -->
<div class="ordering">
	<ul>
		<li><strong>###LABEL_SORT###</strong></li>
		<!-- ###SORT_LINK### begin -->
			<li class="sortlink sortlink-###FIELDNAME###">###URL###<span class="###CLASS###"></span></li>
		<!-- ###SORT_LINK### end -->
	</ul>
	<div class="clearer"></div>
</div>
<!-- ###ORDERNAVIGATION### end -->


<!-- ###RESULT_ROW### start -->
<div class="result-list-item">
	<!-- ###SUB_NUMERATION### start -->###NUMBER###.<!-- ###SUB_NUMERATION### end -->
	<b>###TITLE###</b>
	<!-- ###SUB_SCORE_SCALE### start -->
		<span class="scoreBar">
			<span class="score" style="width: ###SCORE###%;"></span>
		</span>
	<!-- ###SUB_SCORE_SCALE### end -->
	<span class="clearer">&nbsp;</span>
	<!-- ###SUB_TYPE_ICON### start --><span class="teaser_icon">###TYPE_ICON###</span><!-- ###SUB_TYPE_ICON### end -->
	###TEASER###
	<span class="clearer">&nbsp;</span>
	<div class="add-info">
	    <!-- ###SUB_RESULTURL### start -->
		<i>###LABEL_RESULTURL###:</i> ###RESULTURL###<br />
	    <!-- ###SUB_RESULTURL### end -->
	    <!-- ###SUB_SCORE### start -->
		<i>###LABEL_SCORE###:</i> ###SCORE###<br />
	    <!-- ###SUB_SCORE### end -->
	    <!-- ###SUB_DATE### start -->
		<i>###LABEL_DATE###:</i> ###DATE###<br />
	    <!-- ###SUB_DATE### end -->
	    <!-- ###SUB_SCORE_PERCENT### start -->
		<i>###LABEL_SCORE_PERCENT###:</i> ###SCORE_PERCENT### %<br />
	    <!-- ###SUB_SCORE_PERCENT### end -->
	    <!-- ###SUB_TAGS### start -->
		<i>###LABEL_TAGS###:</i> ###TAGS###<br />
	    <!-- ###SUB_TAGS### end -->
	    <!-- ###SUB_QUERY### start -->
		<i>###LABEL_QUERY###:</i> ###QUERY###
	    <!-- ###SUB_QUERY### end -->
	</div>
</div>
<!-- ###RESULT_ROW### end -->


<!-- ###GENERAL_MESSAGE### start -->
    <div class="general-message">
	<div class="image">###IMAGE###</div>
	<div class="message">###MESSAGE###</div>
	<div class="clearer">&nbsp;</div>
    </div>
<!-- ###GENERAL_MESSAGE### end -->


<!-- ###SUB_FILTER_SELECT### start -->
    <div>
	<select id="###FILTERID###" name="###FILTERNAME###" onchange="document.getElementById('pagenumber').value=1; ###ONCHANGE###" ###DISABLED###>
	    <!-- ###SUB_FILTER_SELECT_OPTION### start -->
		<option value="###VALUE###" ###SELECTED###>###TITLE###</option>
	    <!-- ###SUB_FILTER_SELECT_OPTION### end -->
	</select>
    </div>
<!-- ###SUB_FILTER_SELECT### end -->


<!-- ###SUB_FILTER_LIST### start -->
    <div class="list" id="list_###FILTERID###">
	<span class="head">
	    ###BULLET###
	    <a href="javascript:switchArea('###FILTERID###');">###FILTERTITLE###</a>
	</span>
	<ul id="options_###FILTERID###" class="###LISTCSSCLASS###">
	    <span class="###SPECIAL_CSS_CLASS###">
		<!-- ###SUB_FILTER_LIST_OPTION### start -->
		<li class="###OPTIONCSSCLASS###" onclick="###ONCLICK###">###TITLE###</li>
		<!-- ###SUB_FILTER_LIST_OPTION### end -->
	    </span>
	    <span class="resetlink" onclick="document.getElementById('###FILTERID###').value=''; ###ONCLICK_RESET###">Filter zur&uuml;cksetzen</span>
	</ul>
	<input type="hidden" name="###FILTERNAME###" id="###FILTERID###" value="###VALUE###" />
	</div>
<!-- ###SUB_FILTER_LIST### end -->

<!-- ###SUB_FILTER_CHECKBOX### start -->
    <div class="list" id="list_###FILTERID###">
	<span class="head">
	    ###BULLET###
	    <a href="javascript:switchArea('###FILTERID###');">###FILTERTITLE###</a>
	</span>
	<ul id="options_###FILTERID###" class="###LISTCSSCLASS###">
	    <span class="###SPECIAL_CSS_CLASS###">
	    <li class="checkboxLabel"><label onclick="enableCheckboxes(###FILTER_UID###)">###LABEL_ALL###</label></li>
		<!-- ###SUB_FILTER_CHECKBOX_OPTION### start -->
		<li name="###OPTIONNAME###" class="###OPTIONCSSCLASS###"><input type="checkbox" name="###FILTERNAME###[###OPTIONKEY###]" id="###OPTIONID###" value="###VALUE###" ###OPTIONSELECT### ###OPTIONDISABLED### /><label for="###OPTIONID###">###TITLE###</label></li>
		<!-- ###SUB_FILTER_CHECKBOX_OPTION### end -->
	    </span>
		<li class="clearer"></li>
	    <span class="resetlink" onclick="countInput=document.getElementById('list_###FILTERID###').getElementsByTagName('input').length;for(i=0;i<countInput;i++){document.getElementById('###FILTERID###['+i+']').value='';} ###ONCLICK_RESET###">Filter zur&uuml;cksetzen</span>
	</ul>
	<!-- <input type="hidden" name="###FILTERNAME###" id="###FILTERID###" value="###VALUE###" /> -->
    </div>
<!-- ###SUB_FILTER_CHECKBOX### end -->


<!-- ###SUB_FILTER_TEXTLINKS### begin ### -->
<div class="textlinks">
	###HIDDEN_FIELDS###
	<h3>###FILTERTITLE###</h3>
	<ul>
	<!-- ###SUB_FILTER_TEXTLINK_OPTION### begin -->
		<li class="###CLASS###">###TEXTLINK###</li>
	<!-- ###SUB_FILTER_TEXTLINK_OPTION### end -->
	</ul>
	<div>###LINK_MULTISELECT###</div>
	<div class="resetlink">###LINK_RESET_FILTER###</div>
</div>
<!-- ###SUB_FILTER_TEXTLINKS### end ### -->


<!-- ###SUB_FILTER_MULTISELECT### begin -->
<div class="multiselect">
	<form method="get" action="###FORM_ACTION###">
		<input type="hidden" name="id" value="###PAGEID###" />
		<input type="hidden" name="tx_kesearch_pi1[multi]" value="1" />
		<input type="hidden" name="tx_kesearch_pi1[sword]" value="###SWORD###" />
		<input type="hidden" name="tx_kesearch_pi1[page]" value="1" />

		<!-- ###SUB_FILTER_MULTISELECT_HIDDEN### begin -->
			<input type="hidden" name="###NAME###" value="###VALUE###" />
		<!-- ###SUB_FILTER_MULTISELECT_HIDDEN### end -->

		<!-- ###SUB_FILTER_MULTISELECT_FILTER### begin -->
			<h3>###TITLE###</h3>
			<!-- ###SUB_FILTER_MULTISELECT_OPTION### begin -->
				<div class="multi-option###ADDCLASS###">
					<input type="checkbox" name="###FILTERNAME###[###OPTIONKEY###]" id="###FILTERNAME###[###OPTIONKEY###]" value="###OPTIONTAG###" ###SELECTED### /><label for="###FILTERNAME###[###OPTIONKEY###]">###OPTIONTITLE###</label>
				</div>
			<!-- ###SUB_FILTER_MULTISELECT_OPTION### end -->
			<div class="clearer"></div>
		<!-- ###SUB_FILTER_MULTISELECT_FILTER### end -->
		<div class="submit"><input type="submit" value="###SHOW_RESULTS###" /></div>
		<div class="back">###LINK_BACK###</div>
	</form>
</div>
<!-- ###SUB_FILTER_MULTISELECT### end -->
