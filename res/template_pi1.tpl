<!-- ###SEARCHBOX### start -->
    <form id="xajax_form_kesearch_pi1" name="xajax_form_kesearch_pi1" method="post" onsubmit="return false;">
	<div class="searchbox">
	    <input type="text" name="tx_kesearch_pi1[sword]" value="###SWORD_VALUE###" onkeypress="keyPressAction(event);" />
	    <input type="image" src="typo3conf/ext/ke_search/res/img/go.gif" id="kesearch_submit" name="tx_kesearch_pi1[submit]" class="submit" value="###SUBMIT_VALUE###" onclick="document.getElementById('pagenumber').value=1; ###ONCLICK###" />
	    <input id="pagenumber" type="hidden" name="tx_kesearch_pi1[page]" value="###HIDDEN_PAGE_VALUE###">
	    <input id="resetFilters" type="hidden" name="tx_kesearch_pi1[resetFilters]" value="0">
	</div>
	<div id="kesearch_filters">
	    ###FILTER###
	</div>
	<div id="kesearch_updating_filters">
	    <center>
		###SPINNER###<br />
		<!-- <b>###LOADING###</b> -->
	    </center>
	</div>
    </form>
    
<!-- ###SEARCHBOX### end -->

<!-- ###RESULT_LIST### start -->
    <div id="kesearch_pagebrowser_top">###PAGEBROWSER_TOP###</div>
    <ul id="kesearch_results"><li>###MESSAGE###</li></ul>
    ###ONLOAD_IMAGE_RESULTS###
    <div id="kesearch_updating_results">
	<center>
	    ###SPINNER###<br />
	    <!-- <b>###LOADING###</b> -->
	</center>
    </div>
    <div id="kesearch_pagebrowser_bottom">###PAGEBROWSER_BOTTOM###</div>
    <!-- ###SUB_QUERY_TIME### start --><div id="kesearch_query_time">###QUERY_TIME###</div><!-- ###SUB_QUERY_TIME### end -->
    ###ONLOAD_IMAGE###
    
    <!-- <div id="testbox">--DEBUG BOX --</div> -->
    
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


<!-- ###RESULT_ROW### start -->
    <li>
	<!-- ###SUB_NUMERATION### start -->###NUMBER###.<!-- ###SUB_NUMERATION### end -->
	<b>###TITLE###</b>
	<!-- ###SUB_SCORE_SCALE### start --><span style="float:right;">###SCORE_SCALE###</span><!-- ###SUB_SCORE_SCALE### end -->
	<span class="clearer">&nbsp;</span>
	<!-- ###SUB_TYPE_ICON### start --><span class="teaser_icon">###TYPE_ICON###</span><!-- ###SUB_TYPE_ICON### end -->
	###TEASER###<br />
	<div class="add-info">
	    <!-- ###SUB_RESULTURL### start -->
		<i>###LABEL_RESULTURL###:</i> ###RESULTURL###<br />
	    <!-- ###SUB_RESULTURL### end -->
	    <!-- ###SUB_SCORE### start -->
		<i>###LABEL_SCORE###:</i> ###SCORE###<br />
	    <!-- ###SUB_SCORE### end -->
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
    </li>
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
	<!-- <b>###FILTERTITLE###:</b><br /> -->
	<select id="###FILTERID###" name="###FILTERNAME###" onchange="document.getElementById('pagenumber').value=1; ###ONCHANGE###" ###DISABLED###>
	    <!-- ###SUB_FILTER_SELECT_OPTION### start -->
		<option value="###VALUE###" ###SELECTED###>###TITLE###</option>
	    <!-- ###SUB_FILTER_SELECT_OPTION### end -->
	</select>
	<br /><br />
    </div>
<!-- ###SUB_FILTER_SELECT### end -->
