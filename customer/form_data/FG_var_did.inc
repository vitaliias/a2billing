<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * This file is part of A2Billing (http://www.a2billing.net/)
 *
 * A2Billing, Commercial Open Source Telecom Billing platform,   
 * powered by Star2billing S.L. <http://www.star2billing.com/>
 * 
 * @copyright   Copyright (C) 2004-2009 - Star2billing S.L. 
 * @author      Belaid Arezqui <areski@gmail.com>
 * @license     http://www.fsf.org/licensing/licenses/agpl-3.0.html
 * @package     A2Billing
 *
 * Software License Agreement (GNU Affero General Public License)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * 
**/



getpost_ifset(array('id', 'billingtype', 'did', 'startingdate', 'expirationdate', 'id_cc_didgroup', 'id_cc_country', 'activated', 'fixrate', 'id_trunk', 'choose_country', 'choose_did', 'assign', 'countrycode', 'arecode', 'phonenumber', 'voip_call', 'destination', 'choose_did_rate', 'new_did_page', 'confirm_buy_did', 'action_release'));


$HD_Form = new FormHandler("cc_did_destination",gettext("Destination"));

$HD_Form -> FG_DEBUG = 0;
$HD_Form -> FG_TABLE_DEFAULT_ORDER = "did DESC, priority";
$HD_Form -> FG_TABLE_DEFAULT_SENS = "ASC";

$HD_Form -> FG_EDITION = true;
$HD_Form -> FG_DELETION = true;
$HD_Form -> FG_ADDITION = true;
$HD_Form -> FG_OTHER_BUTTON1 = false;

$actived_list = Constants::getActivationList();
$billingtype_list = Constants::getBillingTypeList();

// -- billtype: 0 = fix per month + dialoutrate, 1= fix per month, 2 = dialoutrate, 3 = free
$billingtype_list_short = Constants::getBillingTypeShortList();

$priority_list = array();
for ($k=1;$k<=5;$k++) {
    $priority_list["$k"]  = array( "Priority : $k", "$k");
}
$actived_list = array(); 
$actived_list["1"] = array( gettext("Active"), "1"); 
$actived_list["0"]  = array( gettext("Inactive"), "0");

$validated_list = array();
$validated_list["0"] = array( gettext("Pending"), "0");
$validated_list["1"] = array( gettext("Validated"), "1");

$HD_Form -> AddViewElement(gettext("DID"), "id_cc_did", "15%", "center", "sort", "15", "lie", "cc_did", "did", "id='%id'", "%1");
$HD_Form -> AddViewElement(gettext("ACTIVATED"), "t1.activated", "10%", "center", "sort", "", "list", $actived_list);
$HD_Form -> AddViewElement(gettext("PRIORITY"), "priority", "10%", "center", "sort", "", "list", $priority_list);
$HD_Form -> AddViewElement(gettext("DESTINATION"), "destination", "20%", "center", "sort");
$HD_Form -> AddViewElement(gettext("USED MINUTE"), "t1.secondusedreal", "10%", "center", "SORT", "30", "", "", "", "", "", "display_minute");
$HD_Form -> AddViewElement(gettext("COUNTRY"), "id_cc_country", "15%", "center", "sort", "15", "lie", "cc_country", "countryname", "id='%id'", "%1");
$HD_Form -> AddViewElement(gettext("VALIDATED"), "validated", "10%", "center", "sort", "", "list", $validated_list);


if (isset($form_action) && ($form_action=='ask-edit' || $form_action=='edit')) {
	$HD_Form -> FG_TABLE_NAME = "cc_did_destination AS t1";
	$country_field = '';
	$validated = 1;
	$DBHandle_max = DbConnect();
	$resmax = $DBHandle_max -> Execute("SELECT destination, validated, voip_call FROM cc_did_destination WHERE id='$id'");
	$row = $resmax->fetchRow();
	
	if ($voip_call == 1) {
	    // It's a Voip Call
	    if (!(strpos(substr($destination, strpos($destination, '/')),'.') && (stripos($destination,'SIP/') === 0 || stripos($destination,'IAX2/') === 0 || stripos($destination,'H323/') === 0) && stripos($row[0],'Local/') === false)) {
		$destination = $row[0];
		$validated = $row[1];
		$voip_call = $row[2];
	    } else {
		$numrow = 0;
		$resmax = $DBHandle_max -> Execute("SELECT * FROM cc_did_destination WHERE id='$id' AND destination='$destination' AND validated=1");
	
		if ( $resmax )
		    $numrow = $resmax -> RecordCount( );
		$validated = ($numrow == 1) ? 1 : 0;
	
		if ($validated)
		    NotificationsDAO::AddNotification("did_destination_edited_cust", Notification::$LOW, Notification::$CUST, $_SESSION['card_id'], Notification::$LINK_DID_DESTINATION, $id);
	
		//$HD_Form->_processed['destination'] = intval($destination);
	    }
	} else {
	    // It's not a Voip call, check if we don't try to inject
	    $destination = (intval($destination) > 0) ? preg_replace("|\D|", "", $destination) : 'no valid';
	    //echo "Validity check ($destination)";
	}
	
} else {
	$HD_Form -> FG_TABLE_NAME = "cc_did_destination AS t1, cc_did AS t2";
	$HD_Form -> FG_TABLE_CLAUSE = " id_cc_card='".$_SESSION["card_id"]."' AND t1.id_cc_did=t2.id";
	$country_field = ' id_cc_country,';
	$validated = 0;
}

$HD_Form -> FG_COL_QUERY = 'id_cc_did, t1.activated, priority, destination, t1.secondusedreal,'. $country_field .'  t1.validated, t1.id ';

$HD_Form -> CV_NO_FIELDS  = gettext("There are no destinations created");
$HD_Form -> CV_DISPLAY_LINE_TITLE_ABOVE_TABLE = false;
$HD_Form -> CV_TEXT_TITLE_ABOVE_TABLE = '';
$HD_Form -> CV_DISPLAY_FILTER_ABOVE_TABLE = false;

$HD_Form -> FG_EDITION = true;
$HD_Form -> FG_DELETION = true;
$HD_Form -> FG_VIEW_TABLE_WITDH = '80%';

$HD_Form -> AddEditElement (gettext("ACTIVATED"),
	"activated",
	'1',
	"RADIOBUTTON",
	"",
	"",
	gettext("Choose if you want to activate this card"),
	"" , "", "", gettext("Yes").":1, - ".gettext("No").":0", "", "", "", "");
	

if (isset($form_action) && ($form_action=='ask-edit' || $form_action=='ask-delete')) {

    $HD_Form -> AddEditElement (gettext("DESTINATION"),
	    "destination",
	    '$value',
	    "INPUT",
	    "size=50 maxlength=120",
	    "0",
	    "Insert the Destination",
	    "" , "", "", "", "" , "", "", gettext("Enter here the phonenumber you wish to call or the SIP/IAX/H323 peer to reach (ie: 347894999 or SIP/jeremy@182.212.1.45). To call SIP/IAX/H323 peer, you need to enable the voip_call below (voip_call = Yes) "));
}

$HD_Form -> AddEditElement (gettext("TIMEOUT"),
	"timeout",
	'$value',
	"INPUT",
	"size=50 maxlength=3",
	"22",
	gettext("Insert max 3 digits"),
	"", "", "", "", "", "", "", gettext("Maximum time, in seconds, that the Dial command is to wait for a channel to answer"));

$HD_Form -> AddEditElement (gettext("VOIP_CALL"),
	"voip_call",
	'1',
	"RADIOBUTTON",
	"",
	"",
	gettext("Choose if you want to not use the trunk and let the asterisk call directly the destination (ie, Destination : SIP/jeremy@182.212.1.45)"),
	"" , "",  "", gettext("Yes").":1, - ".gettext("No").":0",  "", "" , "" , "");

$HD_Form -> AddEditElement (gettext("PRIORITY"),
	"priority",
	"",
	"SELECT",
	"",
	"",
	"",
	"list" , "", "",  "", $priority_list, "%1" , "" , "");

$HD_Form -> AddEditElement (gettext("ANSWER"),
	"answer",
	'0',
	"RADIOBUTTON",
	"",
	"",
	gettext("Choose 'Yes' if you want to answer on the call"),
	"" , "", "", gettext("Yes").":1, - ".gettext("No").":0", "", "", "", gettext("Choose if you want to answer on the call before playing PLAY_SOUND"));

$HD_Form -> AddEditElement (gettext("PLAY_SOUND"),
	"playsound",
	'$value',
	"INPUT",
	"size=50 maxlength=50",
	"",
	gettext("Insert the path and name of sound file"),
	"" , "", "", "", "", "", "", gettext("Play sound before calling DESTINATION. ie 'ivr/wait-please'"));

if (isset($form_action) && ($form_action=='ask-edit' || $form_action=='ask-delete')) {
    $HD_Form -> FieldEditElement ('activated, destination, timeout, voip_call, priority, answer, playsound');
} else {
    $HD_Form -> FG_QUERY_EDITION_HIDDEN_FIELDS = "destination, validated, voip_call";
    $HD_Form -> FG_QUERY_EDITION_HIDDEN_VALUE = "$destination, $validated, $voip_call";
}

$HD_Form -> FG_INTRO_TEXT_EDITION= '';
$HD_Form -> FG_INTRO_TEXT_ASK_DELETION = gettext("If you really want remove this")." ".$HD_Form->FG_INSTANCE_NAME.", ".gettext("click on the delete button.");
$HD_Form -> FG_INTRO_TEXT_ADD = gettext("you can add easily a new")." ".$HD_Form->FG_INSTANCE_NAME.".<br>".gettext("Fill the following fields and confirm by clicking on the button add.");

$HD_Form -> FG_INTRO_TEXT_ADITION = '';
$HD_Form -> FG_TEXT_ADITION_CONFIRMATION = gettext("Your new")." ".$HD_Form->FG_INSTANCE_NAME." ".gettext("has been inserted.")."<br>";

$HD_Form -> FG_BUTTON_EDITION_SRC = $HD_Form -> FG_BUTTON_ADITION_SRC  = Images_Path."/cormfirmboton.gif";
$HD_Form -> FG_BUTTON_EDITION_BOTTOM_TEXT = $HD_Form -> FG_BUTTON_ADITION_BOTTOM_TEXT = gettext("Setup those values to create or edit the ")." ".$HD_Form->FG_INSTANCE_NAME;

$HD_Form -> FG_GO_LINK_AFTER_ACTION_ADD = $_SERVER['PHP_SELF']."?atmenu=document&stitle=Document&wh=AC&id=";
$HD_Form -> FG_GO_LINK_AFTER_ACTION_EDIT = $_SERVER['PHP_SELF']."?atmenu=document&stitle=Document&wh=AC&id=";
$HD_Form -> FG_GO_LINK_AFTER_ACTION_DELETE = $_SERVER['PHP_SELF']."?atmenu=document&stitle=Document&wh=AC&id=";
