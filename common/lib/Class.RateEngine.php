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
 * @contributor Steve Dommett <steve@st4vs.net>
 *              Belaid Rachid <rachid.belaid@gmail.com>
 *              Nick Mitin <nixon@komp.com.ua>
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

include_once (FSROOT."lib/Misc.php");

class RateEngine
{
	var $debug_st = 0;

	var $ratecard_obj = array();

	var $freetimetocall_left = array();
	var $freecall = array();
	var $package_to_apply = array();

	var $number_trunk		= 0;
	var $lastcost			= 0;
	var $lastbuycost		= 0;
	var $answeredtime		= 0;
	var $real_answeredtime		= 0;
	var $dialstatus			= 0;
	var $usedratecard		= 0;
	var $webui			= 1;
	var $usedtrunk			= 0;
	var $freetimetocall_used	= 0;

	// List of dialstatus
	var $dialstatus_rev_list;
	

	/* CONSTRUCTOR */
	function RateEngine ()
	{
		$this -> dialstatus_rev_list = Constants::getDialStatus_Revert_List();
	}

	/* Reinit */
	function Reinit ()
	{
		$this -> number_trunk = 0;
		$this -> answeredtime = 0;
		$this -> real_answeredtime = 0;
		$this -> dialstatus = '';
		$this -> usedratecard = '';
		$this -> usedtrunk = '';
		$this -> lastcost = '';
		$this -> lastbuycost = '';
		
	}

	/*
		RATE ENGINE
		CALCUL THE RATE ACCORDING TO THE RATEGROUP, LCR - RATECARD
	*/
	function rate_engine_findrates (&$A2B, $phonenumber, $tariffgroupid)
	{
		global $agi;

		// Check if we want to force the call plan
		if (is_numeric($A2B->agiconfig['force_callplan_id']) && ($A2B->agiconfig['force_callplan_id'] > 0)) {
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "force the call plan : ".$A2B->agiconfig['force_callplan_id']);
			$tariffgroupid = $A2B->tariff = $A2B->agiconfig['force_callplan_id'];
		}

		if ($this->webui) {
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_asterisk_rate-engine: ($tariffgroupid, $phonenumber)]");
		}


		/***  0 ) CODE TO RETURN THE DAY OF WEEK + CREATE THE CLAUSE  ***/

		$daytag = date("w", time()); // Day of week ( Sunday = 0 .. Saturday = 6 )
		$hours = date("G", time()); // Hours in 24h format ( 0-23 )
		$minutes = date("i", time()); // Minutes (00-59)
		if (!$daytag) $daytag=6;
		else $daytag--;
		if ($this -> debug_st) echo "$daytag $hours $minutes <br>";
		// Race condiction on $minutes ?!
		$minutes_since_monday = ($daytag * 1440) + ($hours * 60) + $minutes;
		if ($this -> debug_st) echo "$minutes_since_monday<br> ";
		
		$sql_clause_days = " AND (starttime <= ".$minutes_since_monday." AND endtime >=".$minutes_since_monday.") ";

		$mydnid = $mycallerid = "";
		if (strlen($A2B->dnid)>=1) $mydnid = $A2B->dnid;
		if (strlen($A2B->CallerID)>=1) $mycallerid = $A2B->CallerID;

		if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_asterisk_rate-engine - CALLERID : ".$A2B->CallerID."]",0);

		$DNID_SUB_QUERY = "AND 0 = (SELECT COUNT(dnidprefix) FROM cc_tariffgroup_plan RIGHT JOIN cc_tariffplan ON cc_tariffgroup_plan.idtariffplan=cc_tariffplan.id WHERE dnidprefix=SUBSTRING('$mydnid',1,length(dnidprefix)) AND idtariffgroup=$tariffgroupid ) ";

		$CID_SUB_QUERY = "AND 0 = (SELECT count(calleridprefix) FROM cc_tariffgroup_plan RIGHT JOIN cc_tariffplan ON cc_tariffgroup_plan.idtariffplan=cc_tariffplan.id WHERE calleridprefix=SUBSTRING('$mycallerid',1,length(calleridprefix)) AND idtariffgroup=$tariffgroupid )";
		
		// $prefixclause to allow good DB servers to use an index rather than sequential scan
		// justification at http://forum.asterisk2billing.org/viewtopic.php?p=9620#9620
		$max_len_prefix = min(strlen($phonenumber), 15);	// don't match more than 15 digits (the most I have on my side is 8 digit prefixes)
		$prefixclause = '(';
		while ($max_len_prefix > 0 ) {
			$prefixclause .= "dialprefix='".substr($phonenumber,0,$max_len_prefix)."' OR ";
			$max_len_prefix--;
		}
		$prefixclause .= "dialprefix='defaultprefix')";
		
		// match Asterisk/POSIX regex prefixes,  rewrite the Asterisk '_XZN.' characters to
		// POSIX equivalents, and test each of them against the dialed number
		$prefixclause .= " OR (dialprefix LIKE '&_%' ESCAPE '&' AND '$phonenumber' ";
		$prefixclause .= "REGEXP REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CONCAT('^', dialprefix, '$'), ";
		$prefixclause .= "'X', '[[:digit:]]'), 'Z', '[1-9]'), 'N', '[2-9]'), '.', '.+'), '_', ''))";

        // select group by 5 ... more easy to count
		$QUERY = "SELECT
		tariffgroupname, lcrtype, idtariffgroup, cc_tariffgroup_plan.idtariffplan, tariffname, 
        destination, cc_ratecard.id, dialprefix, destination, buyrate,
        buyrateinitblock, buyrateincrement, rateinitial, initblock, billingblock,
		connectcharge, disconnectcharge, stepchargea, chargea, timechargea,
        billingblocka, stepchargeb, chargeb,timechargeb, billingblockb, 
        stepchargec, chargec, timechargec, billingblockc,cc_tariffplan.id_trunk AS tp_id_trunk,
        tp_trunk.trunkprefix AS tp_trunk, tp_trunk.providertech AS tp_providertech,tp_trunk.providerip AS tp_providerip, tp_trunk.removeprefix AS tp_removeprefix,cc_ratecard.id_trunk AS rc_id_trunk,
        rt_trunk.trunkprefix AS rc_trunkprefix, rt_trunk.providertech AS rc_providertech,rt_trunk.providerip AS rc_providerip, rt_trunk.removeprefix AS rc_removeprefix, musiconhold,
		tp_trunk.failover_trunk AS tp_failover_trunk, rt_trunk.failover_trunk AS rt_failover_trunk,tp_trunk.addparameter AS tp_addparameter_trunk, rt_trunk.addparameter AS rt_addparameter_trunk, id_outbound_cidgroup,
        id_cc_package_offer, tp_trunk.status, rt_trunk.status, tp_trunk.inuse, rt_trunk.inuse,
        IF(tp_trunk.wrapnexttime>NOW(),0,tp_trunk.maxuse), IF(rt_trunk.wrapnexttime>NOW(),0,rt_trunk.maxuse), tp_trunk.if_max_use, rt_trunk.if_max_use, cc_ratecard.rounding_calltime AS rounding_calltime,
        cc_ratecard.rounding_threshold AS rounding_threshold, cc_ratecard.additional_block_charge AS additional_block_charge, cc_ratecard.additional_block_charge_time AS additional_block_charge_time,
		cc_ratecard.additional_grace AS additional_grace, cc_ratecard.minimal_cost AS minimal_cost,disconnectcharge_after,announce_time_correction, rt_trunk.dialprefixmain,
	rt_trunk.perioda, rt_trunk.maxsecperperioda, rt_trunk.periodcounta, UNIX_TIMESTAMP(rt_trunk.periodexpirya), rt_trunk.failover_trunka, UNIX_TIMESTAMP(rt_trunk.startdatea), UNIX_TIMESTAMP(rt_trunk.stopdatea), rt_trunk.timelefta,
	rt_trunk.periodb, rt_trunk.maxsecperperiodb, rt_trunk.periodcountb, UNIX_TIMESTAMP(rt_trunk.periodexpiryb), rt_trunk.failover_trunkb, UNIX_TIMESTAMP(rt_trunk.startdateb), UNIX_TIMESTAMP(rt_trunk.stopdateb), rt_trunk.timeleftb,
	tp_trunk.outbound_cidgroup_id, rt_trunk.outbound_cidgroup_id, rt_trunk.trunkcode, tp_trunk.wrapuptime, rt_trunk.wrapuptime

		FROM cc_tariffgroup
		RIGHT JOIN cc_tariffgroup_plan ON cc_tariffgroup_plan.idtariffgroup=cc_tariffgroup.id
		INNER JOIN cc_tariffplan ON (cc_tariffplan.id=cc_tariffgroup_plan.idtariffplan )
		LEFT JOIN cc_ratecard ON cc_ratecard.idtariffplan=cc_tariffplan.id
		LEFT JOIN cc_trunk AS rt_trunk ON cc_ratecard.id_trunk=rt_trunk.id_trunk
		LEFT JOIN cc_trunk AS tp_trunk ON cc_tariffplan.id_trunk=tp_trunk.id_trunk

		WHERE cc_tariffgroup.id=$tariffgroupid AND ($prefixclause)
		AND startingdate<= CURRENT_TIMESTAMP AND (expirationdate > CURRENT_TIMESTAMP OR expirationdate IS NULL)
		AND startdate<= CURRENT_TIMESTAMP AND (stopdate > CURRENT_TIMESTAMP OR stopdate IS NULL)
		$sql_clause_days
		AND idtariffgroup='$tariffgroupid'
		AND ( dnidprefix=SUBSTRING('$mydnid',1,length(dnidprefix)) OR (dnidprefix='all' $DNID_SUB_QUERY))
		AND ( calleridprefix=SUBSTRING('$mycallerid',1,length(calleridprefix)) OR (calleridprefix='all' $CID_SUB_QUERY))
		ORDER BY LENGTH(dialprefix) DESC";

		$A2B->instance_table = new Table();
		$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);


		if (!is_array($result) || count($result)==0) return 0; // NO RATE FOR THIS NUMBER

		if ($this->debug_st) echo "::> Count Total result ".count($result)."\n\n";
		if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: Count Total result ".count($result)."]");

		// CHECK IF THERE IS OTHER RATE THAT 'DEFAULT', IF YES REMOVE THE DEFAULT RATES
		// NOT NOT REMOVE SHIFT THEM TO THE END :P
		$ind_stop_default = -1;
		for ($i=0 ; $i<count($result) ; $i++) {
			if ( $result[$i][7] != 'defaultprefix') {
				$ind_stop_default = $i;
				break;
			}
		}
		
		// IMPORTANT TO CUT THE PART OF THE defaultprefix CAUSE WE WILL APPLY THE SORT ACCORDING TO THE RATE
		// DEFAULPERFIX IS AN ESCAPE IN CASE OF NO RATE IS DEFINED, NOT BE COUNT WITH OTHER DURING THE SORT OF RATE
		if ($ind_stop_default>0) {
			$result_defaultprefix = array_slice ($result, 0, $ind_stop_default);
			$result = array_slice ($result, $ind_stop_default, count($result)-$ind_stop_default);
		}
		
		
        if ($A2B->agiconfig['lcr_mode'] == 0){
            //1) REMOVE THOSE THAT HAVE A SMALLER DIALPREFIX
            $max_len_prefix = strlen($result[0][7]);
            for ($i=1;$i<count($result);$i++) {
                if ( strlen($result[$i][7]) < $max_len_prefix) break;
            }
            $result = array_slice ($result, 0, $i);
        } else if ( $A2B->agiconfig['lcr_mode'] == 1 ) {
            //1) REMOVE THOSE THAT HAVE THE LOWEST COST
            $myresult = array();
            $myresult = $result;
            $mysearchvalue = array();
            if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYRESULT before sort \n".print_r($myresult, true));
            // 3 - tariff plan, 7 - dialprefix, 12 - rateinitial
            foreach ($myresult as $key => $row) {
                $sorttariffplan[$key]    = $row[3];
                $sortdialprefixstr[$key] = (ctype_digit($row[7]))?1:(($row[12] != 100)?strlen($row[7]):0);
                $sortdialprefixnum[$key] = $row[7];
            }
            array_multisort($sorttariffplan,SORT_NUMERIC,$sortdialprefixstr,SORT_NUMERIC,SORT_DESC,$sortdialprefixnum,SORT_NUMERIC,SORT_DESC,$myresult);
            if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYRESULT  after sort \n".print_r($myresult, true));
            $countdelete = 0;
            $resultcount = 0;
            $mysearchvalue[$resultcount] = $myresult[0];
            for ($ii=0;$ii<count($result)-1;$ii++){
                if (isset($myresult[$ii])) $mysearchvalue[$resultcount] = $myresult[$ii];
                if ($this->webui) {
                    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: Begin for ii value ".$ii."]");
                    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYSEARCHCVALUE \n".print_r($mysearchvalue,true)."]");
                };
                if (count($myresult)>0){
                    foreach($myresult as $j=>$i){
                        if ($this->webui) {
                            $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: foreach J=".print_r($j, true));
                            if (isset($mysearchvalue[$resultcount][4])) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine:mysearchvalue[4]=".$mysearchvalue[$resultcount][4]);
                            $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine:i[4]=".$i[4]);
                            if (isset($mysearchvalue[$resultcount][3])) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine:mysearchvalue[3]=".$mysearchvalue[$resultcount][3]);
                            $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine:i[3]=".$i[3]);
                            if (isset($mysearchvalue[$resultcount][7])) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine:mysearchvalue[7]=".$mysearchvalue[$resultcount][7]);
                            $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine:i[7]=".$i[7]);
                        };
                        if (isset($mysearchvalue[$resultcount][3]) && $mysearchvalue[$resultcount][3] == $i[3] ) {
                            if (strlen($mysearchvalue[$resultcount][7]) != strlen($i[7])) {
                                unset($myresult[$j]);
                                $countdelete++;
                                if ($this->webui) {
                                    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: foreach: COUNTDELETE: ".$countdelete."]");
                                    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: foreach: MYRESULT count after delete: ".count($myresult)."]");
                                };
                            };
                        }
                    } //end foreach
                    $myresult = array_values($myresult);
                    if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYRESULT  after foreach \n".print_r($myresult, true));
                    $resultcount++;
                    if ($this->webui) {
                        $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: Count MYRESULT after foreach=".count($myresult)."]");
                        $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: RESULTCOUNT=".$resultcount."]");
                    };
                }
                if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: End for II value ".$ii."]");
            }  //end for
            if ($this->webui) {
                $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: COUNTDELETE=".$countdelete."]");
                $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYRESULT  before unset \n".print_r($myresult, true));
            }
            if (count($result)>1 and $countdelete != 0) {
                if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: LAST UNSET");
                unset($mysearchvalue[$resultcount]);
                foreach ($mysearchvalue as $key => $value) {
                    if (is_null($value) or $value == "") {
                        unset($mysearchvalue[$key]);
                    }
                }
                $mysearchvalue = array_values($mysearchvalue);
                unset($myresult);
                $result = $mysearchvalue;
            }
            if ($this->webui) {
                $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: RESULTCOUNT".$resultcount."]");
                if (isset($myresult)) {
                    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYRESULT  after delete \n".print_r($myresult, true));
                    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: Count Total result after 4 ".count($myresult)."]");
                }
                $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: MYSEARCHVALUE after delete \n".print_r($mysearchvalue, true));
            }
            unset($mysearchvalue);
            if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[rate-engine: RESULT  after delete \n".print_r($result, true));
        }
		
		//2) TAKE THE VALUE OF LCTYPE
		//LCR : According to the buyer price	-0 	buyrate [col 9]
		//LCD : According to the seller price	-1  rateinitial	[col 12]

		$LCtype = $result[0][1];

		foreach ($result as $key => $row) {
		    $strprefix[$key]  = (ctype_digit($row[7]))?0:(($row[12] != 100)?strlen($row[7]):0);
		    $lcrsort[$key] = ($LCtype==0)?$row[9]:$row[12];
		}
		array_multisort($strprefix, SORT_DESC, $lcrsort, $result);
		
		// WE ADD THE DEFAULTPREFIX WE REMOVE BEFORE
		if ($ind_stop_default > 0) {
			$result = array_merge ((array)$result, (array)$result_defaultprefix);
		}
		if (count($result) > 1 && $result[count($result)-1][12] == 100) unset($result[count($result)-1]);
		if (count($result) > 0 && $result[count($result)-1][12] == 100) $result[count($result)-1][12] = 0;
		
		// 3) REMOVE THOSE THAT USE THE SAME TRUNK - MAKE A DISTINCT
		//    AND THOSE THAT ARE DISABLED.
		$mylistoftrunk = array();
		for ($i=0;$i<count($result);$i++) {

			if ($result[$i][34] == -1) {
				$status = $result[$i][46];
				$mylistoftrunk_next[]= $mycurrenttrunk = $result[$i][29];
			} else {
				$status = $result[$i][47];
				$mylistoftrunk_next[]= $mycurrenttrunk = $result[$i][34];
			}

			// Check if we already have the same trunk in the ratecard
			if (($i==0 || !in_array ($mycurrenttrunk , $mylistoftrunk)) && $status == 1) {
				$distinct_result[] = $result[$i];
			}

			if ($status == 1)
				$mylistoftrunk[]= $mycurrenttrunk;
		}

		$this -> ratecard_obj = $distinct_result;
		$this -> number_trunk = count($distinct_result);
		
		// if an extracharge DID number was called increase rates with the extracharge fee
		if (strlen($A2B->dnid)>1 && is_array($A2B->agiconfig['extracharge_did']) && in_array($A2B->dnid, $A2B->agiconfig['extracharge_did'])) {
			$fee=$A2B->agiconfig['extracharge_fee'][array_search($A2B->dnid, $A2B->agiconfig['extracharge_did'])];
			$buyfee=$A2B->agiconfig['extracharge_buyfee'][array_search($A2B->dnid, $A2B->agiconfig['extracharge_did'])];
			$A2B -> debug( INFO, $agi, __FILE__, __LINE__, "[CC_asterisk_rate-engine: Extracharge DID found: ".$A2B->dnid.", extra fee: ".$fee.", extra buy fee: ".$buyfee."]");
			for ($i=0; $i<count($this->ratecard_obj); $i++)
			{
				$this->ratecard_obj[$i][9] += $buyfee;
				$this->ratecard_obj[$i][12] += $fee;
                $this->ratecard_obj[$i][18] += $fee; //chargea
                $this->ratecard_obj[$i][22] += $fee; //chargeb
                $this->ratecard_obj[$i][26] += $fee; //chargec
			}
		}

		if ($this->debug_st) echo "::> Count Total distinct_result ".count($distinct_result)."\n\n";
		if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_asterisk_rate-engine: Count Total result ".count($distinct_result)."]");
		if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_asterisk_rate-engine: number_trunk ".$this -> number_trunk."]");

		return 1;
	}


	/*
		RATE ENGINE - CALCUL TIMEOUT
		* CALCUL THE DURATION ALLOWED FOR THE CALLER TO THIS NUMBER
	*/
	function rate_engine_all_calcultimeout (&$A2B, $credit)
	{
		global $agi;
		$addtimeout = 0;
		if ($this->webui) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_RATE_ENGINE_ALL_CALCULTIMEOUT ($credit)]");
		if (!is_array($this -> ratecard_obj) || count($this -> ratecard_obj)==0) return false;

		for ($k=0;$k<count($this -> ratecard_obj);$k++) {
			$res_calcultimeout = $this -> rate_engine_calcultimeout ($A2B,$credit,$k,$addtimeout);
			if ($this->webui)
				$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_RATE_ENGINE_ALL_CALCULTIMEOUT: k=$k - res_calcultimeout:$res_calcultimeout]");

			if (substr($res_calcultimeout,0,5)=='ERROR')	return false;
		}

		return (!$addtimeout)?true:$addtimeout;
	}

	/*
		RATE ENGINE - CALCUL TIMEOUT
		* CALCUL THE DURATION ALLOWED FOR THE CALLER TO THIS NUMBER
	*/
	function rate_engine_calcultimeout (&$A2B, $credit, $K=0, &$addtimeout=0)
	{
		global $agi;

		$rateinitial 					= a2b_round (abs($this -> ratecard_obj[$K][12]));
		$initblock 						= $this -> ratecard_obj[$K][13];
		$billingblock 					= $this -> ratecard_obj[$K][14];
		$connectcharge 					= a2b_round (abs($this -> ratecard_obj[$K][15]));
		$disconnectcharge 				= a2b_round (abs($this -> ratecard_obj[$K][16]));
		$disconnectcharge_after         = $this -> ratecard_obj[$K][60];
		$announce_time_correction		= $this -> ratecard_obj[$K][61];
		$stepchargea 					= $this -> ratecard_obj[$K][17];
		$chargea 						= a2b_round (abs($this -> ratecard_obj[$K][18]));
		$timechargea 					= $this -> ratecard_obj[$K][19];
		$billingblocka 					= $this -> ratecard_obj[$K][20];
		$stepchargeb 					= $this -> ratecard_obj[$K][21];
		$chargeb 						= a2b_round (abs($this -> ratecard_obj[$K][22]));
		$timechargeb 					= $this -> ratecard_obj[$K][23];
		$billingblockb 					= $this -> ratecard_obj[$K][24];
		$stepchargec 					= $this -> ratecard_obj[$K][25];
		$chargec 						= a2b_round (abs($this -> ratecard_obj[$K][26]));
		$timechargec 					= $this -> ratecard_obj[$K][27];
		$billingblockc 					= $this -> ratecard_obj[$K][28];
		// ****************  PACKAGE PARAMETERS ****************
		$id_cc_package_offer 			= $this -> ratecard_obj[$K][45];
		$id_rate 						= $this -> ratecard_obj[$K][6];
		$initial_credit					= $credit;
		// CHANGE THIS - ONLY ALLOW FREE TIME FOR CUSTOMER THAT HAVE MINIMUM CREDIT TO CALL A DESTINATION
		
		
		
		$this -> freetimetocall_left[$K] = 0;
		$this -> freecall[$K] = false;
		$this -> package_to_apply [$K] = null;
		
		//CHECK THE PACKAGES TO APPLY TO THIS RATES
		if ($id_cc_package_offer!=-1) {

			$query_pakages = "SELECT cc_package_offer.id, packagetype, billingtype, startday, freetimetocall ".
			                    "FROM cc_package_offer,cc_package_rate WHERE cc_package_offer.id= ".$id_cc_package_offer.
			                    " AND cc_package_offer.id = cc_package_rate.package_id AND cc_package_rate.rate_id = ".$id_rate.
			                    " ORDER BY packagetype ASC";
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[PACKAGE IN:$query_pakages ]");
			$table_packages = new Table();
			$result_packages = $table_packages -> SQLExec ($A2B -> DBHandle, $query_pakages);
			$idx_pack = 0;
			
			if (!empty($result_packages)) {
				$package_selected = false;

				while (!$package_selected && $idx_pack < count($result_packages)) {

					$freetimetocall 	= $result_packages[$idx_pack]["freetimetocall"];
					$packagetype 		= $result_packages[$idx_pack]["packagetype"];
					$billingtype 		= $result_packages[$idx_pack]["billingtype"];
					$startday 			= $result_packages[$idx_pack]["startday"];
					$id_cc_package_offer= $result_packages[$idx_pack][0];

					$A2B -> debug("[ID PACKAGE  TO APPLY=$id_cc_package_offer - packagetype=$packagetype]");
					switch($packagetype) {
						// 0 : UNLIMITED PACKAGE
						//IF PACKAGE IS "UNLIMITED" SO WE DON'T NEED TO CALCULATE THE USED TIMES
						case 0 : $this -> freecall[$K] = true;
								$package_selected = true;
								$this -> package_to_apply [$K] =  array("id"=>$id_cc_package_offer,"label"=>"Unlimited calls","type"=>$packagetype);
								$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[Unlimited calls]");
								break;
						// 1 : FREE CALLS
						//IF PACKAGE IS "NUMBER OF FREE CALLS"  AND WE CAN USE IT ELSE WE CHECK THE OTHERS PACKAGE LIKE FREE TIMES
						case 1 :
							if  ( $freetimetocall > 0) {
								$number_calls_used =$A2B -> number_free_calls_used($A2B->DBHandle, $A2B->id_card, $id_cc_package_offer, $billingtype, $startday);
								if ($number_calls_used < $freetimetocall) {
									$this -> freecall[$K] = true;
									$package_selected = true;
									$this -> package_to_apply [$K] =  array("id"=>$id_cc_package_offer,"label"=> "Number of Free calls","type"=>$packagetype);
									$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[Number of Free calls]");
								}
							}
							break;
						//2 : FREE TIMES
						case 2 :
							// CHECK IF WE HAVE A FREETIME THAT CAN APPLY FOR THIS DESTINATION
							if  ( $freetimetocall > 0) {
								// WE NEED TO RETRIEVE THE AMOUNT OF USED MINUTE FOR THIS CUSTOMER ACCORDING TO BILLINGTYPE (Monthly ; Weekly) & STARTDAY
								$this -> freetimetocall_used = $A2B -> FT2C_used_seconds($A2B->DBHandle, $A2B->id_card, $id_cc_package_offer, $billingtype, $startday);
								$this -> freetimetocall_left[$K] = $freetimetocall - $this->freetimetocall_used;
								if ($this -> freetimetocall_left[$K] < 0) $this -> freetimetocall_left[$K] = 0;
								if ($this -> freetimetocall_left[$K] > 0) {
									$package_selected = true;
									$this -> package_to_apply [$K] =  array("id"=>$id_cc_package_offer,"label"=> "Free minutes","type"=>$packagetype);
									$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[Free minutes - freetimetocall_used=$this->freetimetocall_used]");
								}
							}
							$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[Free minutes - Break (freetimetocall_left=".$this -> freetimetocall_left[$K].")]");
							break;
					}
					$idx_pack++;
				}
			}
		}
		
		$credit -= $connectcharge;
		if ($disconnectcharge_after==0) {
			$credit -= $disconnectcharge; 
	        //no disconnenct charge on timeout if disconnectcharge_after is set
		}
		
		$callbackrate = array();
		if (($A2B->mode == 'cid-callback') || ($A2B->mode == 'all-callback')) {
			$callbackrate['ri'] = $rateinitial;
			$callbackrate['ib'] = $initblock;
			$callbackrate['bb'] = $billingblock;
			$callbackrate['cc'] = $connectcharge;
			$callbackrate['dc'] = $disconnectcharge;
			$callbackrate['sc_a'] = $stepchargea;
			$callbackrate['tc_a'] = $timechargea;
			$callbackrate['c_a'] = $chargea;
			$callbackrate['bb_a'] = $billingblocka;
			$callbackrate['sc_b'] = $stepchargeb;
			$callbackrate['tc_b'] = $timechargeb;
			$callbackrate['c_b'] = $chargeb;
			$callbackrate['bb_b'] = $billingblockb;
			$callbackrate['sc_c'] = $stepchargec;
			$callbackrate['tc_c'] = $timechargec;
			$callbackrate['c_c'] = $chargec;
			$callbackrate['bb_c'] = $billingblockc;
		}
		
		$this -> ratecard_obj[$K]['callbackrate']=$callbackrate;
		$this -> ratecard_obj[$K]['timeout']=0;
		$this -> ratecard_obj[$K]['timeout_without_rules']=0;
		// used for the simulator
		$this -> ratecard_obj[$K]['freetime_include_in_timeout'] = $this -> freetimetocall_left[$K];
		
		// CHECK IF THE USER IS ALLOW TO CALL WITH ITS CREDIT AMOUNT
		/*
		Comment from Abdoulaye Siby
		This following "if" statement used to verify the minimum credit to call can be improved.
		This mininum credit should be calculated based on the destination, and the minimum billing block.
		*/
		if (($credit <= 0 || $credit < $A2B->agiconfig['min_credit_2call']) && !$this -> freecall[$K] && $this -> freetimetocall_left[$K]<=0) {
		    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[NO ENOUGH CREDIT TO CALL THIS NUMBER - ERROR CT1]");
			return "ERROR CT1";  //NO ENOUGH CREDIT TO CALL THIS NUMBER
		}

		// if ($rateinitial==0) return "ERROR RATEINITIAL($rateinitial)";
		$TIMEOUT = 0;
		$answeredtime_1st_leg = 0;

		if ($rateinitial <= 0) {
			$this -> ratecard_obj[$K]['timeout']= $A2B->agiconfig['maxtime_tocall_negatif_free_route'];
			$this -> ratecard_obj[$K]['timeout_without_rules'] = $A2B->agiconfig['maxtime_tocall_negatif_free_route'];
			$TIMEOUT = $A2B->agiconfig['maxtime_tocall_negatif_free_route'];
			// 90 min
			if ($this -> debug_st) print_r($this -> ratecard_obj[$K]);
			return $TIMEOUT;
		}
		
		if ($this -> freecall[$K]) {
			if ($this -> package_to_apply [$K] ["type"] == 0) {
				$this -> ratecard_obj[$K]['timeout']= $A2B->agiconfig['maxtime_tounlimited_calls']; // default : 90 min
				$TIMEOUT = $A2B->agiconfig['maxtime_tounlimited_calls'];
				$this -> ratecard_obj[$K]['timeout_without_rules'] =$A2B->agiconfig['maxtime_tounlimited_calls'];
				$this -> ratecard_obj[$K]['freetime_include_in_timeout']=$A2B->agiconfig['maxtime_tounlimited_calls'];
			} else {
				$this -> ratecard_obj[$K]['timeout']= $A2B->agiconfig['maxtime_tofree_calls'];
				$TIMEOUT =  $A2B->agiconfig['maxtime_tofree_calls'];
				$this -> ratecard_obj[$K]['timeout_without_rules'] = $A2B->agiconfig['maxtime_tofree_calls'];
				$this -> ratecard_obj[$K]['freetime_include_in_timeout']=$A2B->agiconfig['maxtime_tofree_calls'];
			}
			
			if ($this -> debug_st) print_r($this -> ratecard_obj[$K]);
			return $TIMEOUT;
		}
		
		if ($credit < $A2B->agiconfig['min_credit_2call'] && $this -> freetimetocall_left[$K]>0) {
			$this -> ratecard_obj[$K]['timeout'] = $this -> freetimetocall_left[$K];
			$TIMEOUT =  $this -> freetimetocall_left[$K];
			$this -> ratecard_obj[$K]['timeout_without_rules'] = $this -> freetimetocall_left[$K];
			return $TIMEOUT;
        }
		

		// IMPROVE THE get_variable AND TRY TO RETRIEVE THEM ALL SOMEHOW
		if ($A2B->mode == 'callback') {
			$calling_party_rateinitial = $agi->get_variable('RI', true);
			$calling_party_initblock = $agi->get_variable('IB', true);
			$calling_party_billingblock = $agi->get_variable('BB', true);
			$calling_party_connectcharge = $agi->get_variable('CC', true);
			$calling_party_disconnectcharge = $agi->get_variable('DC', true);
			$calling_party_stepchargea = $agi->get_variable('SC_A', true);
			$calling_party_timechargea = $agi->get_variable('TC_A', true);
			$calling_party_stepchargeb = $agi->get_variable('SC_B', true);
			$calling_party_timechargeb = $agi->get_variable('TC_B', true);
			$calling_party_stepchargec = $agi->get_variable('SC_C', true);
			$calling_party_timechargec = $agi->get_variable('TC_C', true);
		}

		// 2 KIND OF CALCULATION : PROGRESSIVE RATE & FLAT RATE
		// IF FLAT RATE
		if (empty($chargea) || $chargea==0 || empty($timechargea) || $timechargea==0 ) {
		    
			if ($A2B->mode == 'callback') {
				/*
				Comment from Abdoulaye Siby
				In all-callback or cid-callback mode, the number of minutes for the call must be calculated
				according to the rates of both legs of the call.
				*/

				$credit -= $calling_party_connectcharge;
				$credit -= $calling_party_disconnectcharge;
				$num_min = $credit/($rateinitial + $calling_party_rateinitial);
				//I think that the answered time is in seconds
				$answeredtime_1st_leg = intval($agi->get_variable('ANSWEREDTIME', true));
			} else {
				$num_min = $credit/$rateinitial;
			}

			if ($this -> debug_st) echo "num_min:$num_min ($credit/$rateinitial)\n";
			$num_sec = intval($num_min * 60) - $answeredtime_1st_leg;
			if ($this -> debug_st) echo "num_sec:$num_sec \n";

			if ($billingblock > 0) {
				$mod_sec = $num_sec % $billingblock;
				$num_sec=$num_sec-$mod_sec;
			}

			$TIMEOUT = $num_sec;

		// IF PROGRESSIVE RATE
		} else {
			if ($this -> debug_st) echo "CYCLE A	TIMEOUT:$TIMEOUT\n";
			// CYCLE A
			$credit -= $stepchargea;

			//if ($credit<=0) return "ERROR CT2";		//NO ENOUGH CREDIT TO CALL THIS NUMBER
			if ($credit<=0) {
				if ($this -> freetimetocall_left[$K] > 0) {
					$this -> ratecard_obj[$K]['timeout'] = $this -> freetimetocall_left[$K];
					if ($this -> debug_st) print_r($this -> ratecard_obj[$K]);
					return $this -> freetimetocall_left[$K];
				} else {
					return "ERROR CT2";		//NO ENOUGH CREDIT TO CALL THIS NUMBER
				}
			}
			if (!($chargea>0)) return "ERROR CHARGEA($chargea)";
			$num_min = $credit/$chargea;
			if ($this -> debug_st) echo "			CYCLEA num_min:$num_min ($credit/$chargea)\n";
			$num_sec = intval($num_min * 60);
			if ($this -> debug_st) echo "			CYCLEA num_sec:$num_sec \n";
			if ($billingblocka > 0) {
				$mod_sec = $num_sec % $billingblocka;
				$num_sec=$num_sec-$mod_sec;
			}


			if (($num_sec>$timechargea) && !(empty($chargeb) || $chargeb==0 || empty($timechargeb) || $timechargeb==0) ) {
				$TIMEOUT += $timechargea;
				$credit -= ($chargea/60) * $timechargea;

				if ($this -> debug_st) echo "		CYCLE B		TIMEOUT:$TIMEOUT\n";
				// CYCLE B
				$credit -= $stepchargeb;
				if ($credit<=0) {
					$this -> ratecard_obj[$K]['timeout'] = $TIMEOUT + $this -> freetimetocall_left[$K];
					return $TIMEOUT + $this -> freetimetocall_left[$K];		//NO ENOUGH CREDIT TO GO TO THE CYCLE B
				}

				if (!($chargeb>0)) return "ERROR CHARGEB($chargeb)";
				$num_min = $credit/$chargeb;
				if ($this -> debug_st) echo "			CYCLEB num_min:$num_min ($credit/$chargeb)\n";
				$num_sec = intval($num_min * 60);
				if ($this -> debug_st) echo "			CYCLEB num_sec:$num_sec \n";
				if ($billingblockb > 0) {
					$mod_sec = $num_sec % $billingblockb;
					$num_sec=$num_sec-$mod_sec;
				}

				if (($num_sec>$timechargeb) && !(empty($chargec) || $chargec==0 || empty($timechargec) || $timechargec==0) )
				{
					$TIMEOUT += $timechargeb;
					$credit -= ($chargeb/60) * $timechargeb;

					if ($this -> debug_st) echo "				CYCLE C		TIMEOUT:$TIMEOUT\n";
					// CYCLE C
					$credit -= $stepchargec;
					if ($credit<=0) {
						$this -> ratecard_obj[$K]['timeout'] = $TIMEOUT + $this -> freetimetocall_left[$K];
						return $TIMEOUT + $this -> freetimetocall_left[$K];		//NO ENOUGH CREDIT TO GO TO THE CYCLE C
					}

					if (!($chargec>0)) return "ERROR CHARGEC($chargec)";
					$num_min = $credit/$chargec;
					if ($this -> debug_st) echo "			CYCLEC num_min:$num_min ($credit/$chargec)\n";
					$num_sec = intval($num_min * 60);
					if ($this -> debug_st) echo "			CYCLEC num_sec:$num_sec \n";
					if ($billingblockc > 0) {
						$mod_sec = $num_sec % $billingblockc;
						$num_sec=$num_sec-$mod_sec;
					}


					if (($num_sec>$timechargec)) {
						if ($this -> debug_st) echo "		OUT CYCLE C		TIMEOUT:$TIMEOUT\n";
						$TIMEOUT += $timechargec;
						$credit -= ($chargec/60) * $timechargec;

						// IF CYCLE C IS FINISH USE THE RATEINITIAL
						$num_min = $credit/$rateinitial;
						if ($this -> debug_st) echo "			OUT CYCLEC num_min:$num_min ($credit/$rateinitial)\n";
						$num_sec = intval($num_min * 60);
						if ($this -> debug_st) echo "			OUT CYCLEC num_sec:$num_sec \n";
						if ($billingblock > 0) {
							$mod_sec = $num_sec % $billingblock;
							$num_sec=$num_sec-$mod_sec;
						}
						$TIMEOUT += $num_sec;
						// THIS IS THE END

					} else {
						$TIMEOUT += $num_sec;
					}

				} else {

					if (($num_sec>$timechargeb)) {
						$TIMEOUT += $timechargeb;
						if ($this -> debug_st) echo "		OUT CYCLE B		TIMEOUT:$TIMEOUT\n";
						$credit -= ($chargeb/60) * $timechargeb;

						// IF CYCLE B IS FINISH USE THE RATEINITIAL
						$num_min = $credit/$rateinitial;
						if ($this -> debug_st) echo "			OUT CYCLEB num_min:$num_min ($credit/$rateinitial)\n";
						$num_sec = intval($num_min * 60);
						if ($this -> debug_st) echo "			OUT CYCLEB num_sec:$num_sec \n";
						if ($billingblock > 0) {
							$mod_sec = $num_sec % $billingblock;
							$num_sec=$num_sec-$mod_sec;
						}

						$TIMEOUT += $num_sec;
						// THIS IS THE END

					} else {
						$TIMEOUT += $num_sec;
					}
				}

			} else {

				if (($num_sec>$timechargea)) {
					$TIMEOUT += $timechargea;
					if ($this -> debug_st) echo "		OUT CYCLE A		TIMEOUT:$TIMEOUT\n";
					$credit -= ($chargea/60) * $timechargea;

					// IF CYCLE A IS FINISH USE THE RATEINITIAL
					$num_min = $credit/$rateinitial;
					if ($this -> debug_st) echo "			OUT CYCLEA num_min:$num_min ($credit/$rateinitial)\n";
					$num_sec = intval($num_min * 60);
					if ($this -> debug_st) echo "			OUT CYCLEA num_sec:$num_sec \n";;
					if ($billingblock > 0) {
						$mod_sec = $num_sec % $billingblock;
						$num_sec=$num_sec-$mod_sec;
					}

					$TIMEOUT += $num_sec;
					// THIS IS THE END

				} else {
					$TIMEOUT += $num_sec;
				}
			}
		}
		//Call time to speak without rate rules... idiot rules
		$num_min_WR = $initial_credit/$rateinitial;
		$num_sec_WR = intval($num_min_WR * 60);
		$this -> ratecard_obj[$K]['timeout_without_rules'] = $num_sec_WR + $this -> freetimetocall_left[$K];
		
		$TIMEOUT = $TIMEOUT + $this -> freetimetocall_left[$K];
		if ($TIMEOUT > $A2B->agiconfig['maxtime_tounlimited_calls']) {
		    $addtimeout = $TIMEOUT - $A2B->agiconfig['maxtime_tounlimited_calls'];
		    $TIMEOUT = $A2B->agiconfig['maxtime_tounlimited_calls'];
		}
		$this -> ratecard_obj[$K]['timeout'] = $TIMEOUT;
		if ($this -> debug_st) print_r($this -> ratecard_obj[$K]);
		
		return $TIMEOUT;
	}

	/*
	 * RATE ENGINE - CALCUL COST OF THE CALL
	 * - calcul the credit consumed by the call
	 */
	function rate_engine_calculcost (&$A2B, $callduration, $K=0)
	{
		global $agi;
		$K = $this->usedratecard;

		$buyrate 						= a2b_round(abs($this -> ratecard_obj[$K][9]));
		$buyrateinitblock 				= $this -> ratecard_obj[$K][10];
		$buyrateincrement 				= $this -> ratecard_obj[$K][11];

		$rateinitial 					= a2b_round(abs($this -> ratecard_obj[$K][12]));
		$initblock 						= $this -> ratecard_obj[$K][13];
		$billingblock 					= $this -> ratecard_obj[$K][14];
		$connectcharge 					= a2b_round(abs($this -> ratecard_obj[$K][15]));
		$disconnectcharge 				= a2b_round(abs($this -> ratecard_obj[$K][16]));
		$disconnectcharge_after			= $this->ratecard_obj[$K][60];
		$stepchargea 					= $this -> ratecard_obj[$K][17];
		$chargea 						= a2b_round(abs($this -> ratecard_obj[$K][18]));
		$timechargea 					= $this -> ratecard_obj[$K][19];
		$billingblocka 					= $this -> ratecard_obj[$K][20];
		$stepchargeb 					= $this -> ratecard_obj[$K][21];
		$chargeb 						= a2b_round(abs($this -> ratecard_obj[$K][22]),4);
		$timechargeb 					= $this -> ratecard_obj[$K][23];
		$billingblockb 					= $this -> ratecard_obj[$K][24];
		$stepchargec 					= $this -> ratecard_obj[$K][25];
		$chargec 						= a2b_round(abs($this -> ratecard_obj[$K][26]),4);
		$timechargec 					= $this -> ratecard_obj[$K][27];
		$billingblockc 					= $this -> ratecard_obj[$K][28];
		// Initialization rounding calltime and rounding threshold variables
		$rounding_calltime 				= $this->ratecard_obj[$K][54];
		$rounding_threshold 			= $this->ratecard_obj[$K][55];
		// Initialization additional block charge and additional block charge time variables
		$additional_block_charge 		= $this->ratecard_obj[$K][56];
		$additional_block_charge_time 	= $this->ratecard_obj[$K][57];
		$additional_grace_time			= $this->ratecard_obj[$K][58];
		$minimal_call_cost 				= $this->ratecard_obj[$K][59];

		
		if (!is_numeric($rounding_calltime))			$rounding_calltime = 0;
		if (!is_numeric($rounding_threshold))			$rounding_threshold = 0;
		if (!is_numeric($additional_block_charge))		$additional_block_charge = 0;
		if (!is_numeric($additional_block_charge_time))	$additional_block_charge_time = 0;

		if (!is_numeric($this->freetimetocall_used))	$this->freetimetocall_used = 0;
		
		$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_RATE_ENGINE_CALCULCOST: K=$K - CALLDURATION:$callduration - freetimetocall_used=$this->freetimetocall_used - freetimetocall_left=".$this->freetimetocall_left[$K]."]");

		$cost = 0;
		$cost -= $connectcharge;
		if (($disconnectcharge_after<=$callduration) || ($disconnectcharge_after==0)) {
            $cost -= $disconnectcharge;
        }
		
		$this -> real_answeredtime = $callduration;
		$callduration = $callduration + $additional_grace_time;
		
		/*
		 * In following condition callduration will be updated
		 * according to the the rounding_calltime and rounding_threshold
		 * Reference to the TODO : ADDITIONAL CHARGES ON REALTIME BILLING - 1
		 */
		if ($rounding_calltime > 0 && $rounding_threshold > 0 && $callduration > $rounding_threshold && $rounding_calltime > $callduration) {
			$callduration = $rounding_calltime;
			// RESET THE SESSIONTIME FOR CDR
			$this -> answeredtime = $rounding_calltime;
		}

		/*
		 * Following condition will append cost of call
		 * according to the the additional_block_charge and additional_block_charge_time
		 */
		// If call duration is greater then block charge time
		if ($callduration >= $additional_block_charge_time && $additional_block_charge_time > 0) {
			$block_charge = intval($callduration / $additional_block_charge_time);
			$cost -= $block_charge * $additional_block_charge;
		}

		
		// #### 	CALCUL BUYRATE COST   #####
		$buyratecallduration = $this -> real_answeredtime + $additional_grace_time;

		$buyratecost =0;
		if ($buyratecallduration < $buyrateinitblock) $buyratecallduration = $buyrateinitblock;
		if (($buyrateincrement > 0) && ($buyratecallduration > $buyrateinitblock)) {
			$mod_sec = $buyratecallduration % $buyrateincrement; // 12 = 30 % 18
			if ($mod_sec>0) $buyratecallduration += ($buyrateincrement - $mod_sec); // 30 += 18 - 12
		}
		$buyratecost -= ($buyratecallduration/60) * $buyrate;
		if ($this -> debug_st)  echo "1. cost: $cost\n buyratecost:$buyratecost\n";

        // IF IT S A FREE CALL, WE CAN STOP HERE COST = 0
        if ($this -> freecall[$K]) {
        	$this -> lastcost = 0;
			$this -> lastbuycost = $buyratecost;
			if ($this -> debug_st)  echo "FINAL COST: $cost\n\n";
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_RATE_ENGINE_CALCULCOST: K=$K - BUYCOST: $buyratecost - SELLING COST: $cost]");
        	return; 
        }

		// #### 	CALCUL SELLRATE COST   #####
		if ($callduration < $initblock) {
			$callduration = $initblock;
		}


		// 2 KIND OF CALCULATION : PROGRESSIVE RATE & FLAT RATE
		// IF FLAT RATE
		if (empty($chargea) || $chargea==0 || empty($timechargea) || $timechargea==0) {

			if (($billingblock > 0) && ($callduration > $initblock)) {
				$mod_sec = $callduration % $billingblock;
				if ($mod_sec>0) {
					$callduration += ($billingblock - $mod_sec);
				}
			}
			
			if ($this -> freetimetocall_left[$K] >= $callduration) {
				$this -> freetimetocall_used = $callduration;
				$callduration = 0;
			}
			
			$cost -= ($callduration/60) * $rateinitial;
			if ($this -> debug_st)  echo "1.a cost: $cost\n";
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[TEMP - CC_RATE_ENGINE_CALCULCOST: 1. COST: $cost]:[ ($callduration/60) * $rateinitial ]");
			
		// IF PROGRESSIVE RATE
		} else {

			if ($this -> freetimetocall_left[$K] >= $callduration) {
				$this -> freetimetocall_used = $callduration;
				$callduration = 0;
			}

			if ($this -> debug_st) echo "CYCLE A	COST:$cost\n";
			// CYCLE A
			$cost -= $stepchargea;
			if ($this -> debug_st)  echo "1.A cost: $cost\n\n";

			if ($callduration > $timechargea) {
				$duration_report = $callduration - $timechargea;
				$callduration = $timechargea;
			}

			if ($billingblocka > 0) {
				$mod_sec = $callduration % $billingblocka;
				if ($mod_sec>0) {
					$callduration += ($billingblocka - $mod_sec);
				}
			}
			$cost -= ($callduration/60) * $chargea;

			if (($duration_report>0) && !(empty($chargeb) || $chargeb==0 || empty($timechargeb) || $timechargeb==0)) {
				$callduration = $duration_report;
				$duration_report = 0;

				// CYCLE B
				$cost -= $stepchargeb;
				if ($this -> debug_st)  echo "1.B cost: $cost\n\n";

				if ($callduration > $timechargeb) {
					$duration_report = $callduration - $timechargeb;
					$callduration = $timechargeb;
				}

				if ($billingblockb > 0) {
					$mod_sec = $callduration % $billingblockb;
					if ($mod_sec>0) {
						$callduration += ($billingblockb - $mod_sec);
					}
				}
				$cost -= ($callduration/60) * $chargeb; // change chargea -> chargeb thanks to Abbas :D

				if (($duration_report>0) && 
					!(empty($chargec) || $chargec==0 || empty($timechargec) || $timechargec==0)) {
					$callduration = $duration_report;
					$duration_report = 0;

					// CYCLE C
					$cost -= $stepchargec;
					if ($this -> debug_st)  echo "1.C cost: $cost\n\n";

					if ($callduration > $timechargec) {
						$duration_report = $callduration - $timechargec;
						$callduration=$timechargec;
					}

					if ($billingblockc > 0) {
						$mod_sec = $callduration % $billingblockc;
						if ($mod_sec>0) {
							$callduration += ($billingblockc - $mod_sec);
						}
					}
					$cost -= ($callduration/60) * $chargec;
				}
			}

			if ($duration_report > 0) {

				if ($billingblock > 0) {
					$mod_sec = $duration_report % $billingblock;
					if ($mod_sec>0) $duration_report += ($billingblock - $mod_sec);
				}
				$cost -= ($duration_report/60) * $rateinitial;
				$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[TEMP - CC_RATE_ENGINE_CALCULCOST: 2. DURATION_REPORT:$duration_report - COST: $cost]");
			}
		}
		$cost = a2b_round($cost);
		if ($this -> debug_st)  echo "FINAL COST: $cost\n\n";
		$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_RATE_ENGINE_CALCULCOST: K=$K - BUYCOST:$buyratecost - SELLING COST:$cost]");
		
		if ($cost> (0-$minimal_call_cost)) {
			$this -> lastcost = 0 - $minimal_call_cost;
		} else {
			$this -> lastcost = $cost;
		}
		$this -> lastbuycost = $buyratecost;
	}


    /*
		SORT_ASC : Tri en ordre ascendant
      	SORT_DESC : Tri en ordre descendant
	*/
	function array_csort()
	{
		$args = func_get_args();
		$marray = array_shift($args);
		$i=0;
		$msortline = "return(array_multisort(";
		foreach ($args as $arg) {
			$i++;
			if (is_string($arg)) {
				foreach ($marray as $row) {
					$sortarr[$i][] = $row[$arg];
				}
			} else {
				$sortarr[$i] = $arg;
			}
			$msortline .= "\$sortarr[".$i."],";
		}
		$msortline .= "\$marray));";

		eval($msortline);
		return $marray;
	}


	/*
	 * RATE ENGINE - UPDATE SYSTEM (DURATIONCALL)
	 * Calcul the duration allowed for the caller to this number
	 */
	function rate_engine_updatesystem (&$A2B, &$agi, $calledstation, $doibill = 1, $didcall=0, $callback=0, $trunk_id=0, $td=NULL)
	{
		$K = $this->usedratecard;
		
		// ****************  PACKAGE PARAMETERS ****************
		if (count($this -> ratecard_obj)>0) {
			$id_cc_package_offer = $this -> ratecard_obj[$K][45];
			$additional_grace_time = $this -> ratecard_obj[$K][58];
		} else $id_cc_package_offer = 'NONE';
		
		$id_card_package_offer = null;
		
		if ($A2B -> CC_TESTING) {
			$sessiontime = 120;
			$dialstatus = 'ANSWERED';
		} else {
			$sessiontime = $this -> answeredtime;
			$dialstatus = $this -> dialstatus;
		}

		$A2B -> debug( INFO, $agi, __FILE__, __LINE__, ":[sessiontime:$sessiontime - id_cc_package_offer:$id_cc_package_offer - package2apply:".$this ->package_to_apply[$K]."]\n\n");
		
		if ($sessiontime > 0) {
			// HANDLE FREETIME BEFORE CALCULATE THE COST
			$this -> freetimetocall_used = 0;
			if ($this -> debug_st) print_r($this -> freetimetocall_left[$K]);

			if (($id_cc_package_offer!=-1) && ($this ->package_to_apply[$K] !=null )) {
				$id_package_offer = $this ->package_to_apply[$K]["id"];
	
                switch ($this -> package_to_apply[$K]["type"]) {
                	//Unlimited
                	case 0 : 
        				$this->freetimetocall_used = $sessiontime;
        				break;
                	//free calls
                	case 1 : 
        				$this->freetimetocall_used = $sessiontime;
        				break;
                	//free minutes
                	case 2 :
						if ($this -> freetimetocall_left[$K] >= $sessiontime) {
							$this->freetimetocall_used = $sessiontime;
						} else {
							$this->freetimetocall_used = $this -> freetimetocall_left[$K];
						}
						break;
                }
				$this -> rate_engine_calculcost ($A2B, $sessiontime, 0);
				// rate_engine_calculcost could have change the duration of the call
				$sessiontime = $this -> answeredtime;
				
				// add grace time
				if ($sessiontime>0 && $additional_grace_time>0) {
					$sessiontime = $sessiontime + $additional_grace_time;
				}

				$QUERY_FIELS = 'id_cc_card, id_cc_package_offer, used_secondes';
				$QUERY_VALUES = "'".$A2B -> id_card."', '$id_package_offer', '$this->freetimetocall_used'";
				$id_card_package_offer = $A2B -> instance_table -> Add_table ($A2B -> DBHandle, $QUERY_VALUES, $QUERY_FIELS, 'cc_card_package_offer', 'id');
				$A2B -> debug( INFO, $agi, __FILE__, __LINE__, ":[ID_CARD_PACKAGE_OFFER CREATED : $id_card_package_offer]:[$QUERY_VALUES]\n\n\n\n");
				
			} else {
				
				$this -> rate_engine_calculcost ($A2B, $sessiontime, 0);
				// rate_engine_calculcost could have change the duration of the call
				
				$sessiontime = $this -> answeredtime;
				if ($sessiontime > 0 && $additional_grace_time > 0) {
					$sessiontime = $sessiontime + $additional_grace_time;
				}
			}
			
		} else {
			$sessiontime = 0;
		}
		if (count($this -> ratecard_obj)>0) {
			$calldestination = $this -> ratecard_obj[$K][5];
			$id_tariffgroup  = $this -> ratecard_obj[$K][2];
			$id_tariffplan	 = $this -> ratecard_obj[$K][3];
			$id_ratecard	 = $this -> ratecard_obj[$K][6];
			$buyrateapply	 = $this -> ratecard_obj[$K][9];
			$rateapply	 = $this -> ratecard_obj[$K][12];
		}
		$buycost = 0;
		if ($doibill==0 || $sessiontime < $A2B->agiconfig['min_duration_2bill']) {
			$cost = 0;
			$buycost = abs($this -> lastbuycost);
		} else {
			$cost = $this -> lastcost;
			$buycost = abs($this -> lastbuycost);
		}

		if ($cost<0) {
			$signe = '-';
			$signe_cc_call = '+';
		} else {
			$signe = '+';
			$signe_cc_call = '-';
		}

		$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_RATE_ENGINE_UPDATESYSTEM: usedratecard K=$K - (sessiontime=$sessiontime :: dialstatus=$dialstatus :: buycost=$buycost :: cost=$cost : signe_cc_call=$signe_cc_call: signe=$signe)]");

		if (strlen($this -> dialstatus_rev_list[$dialstatus]) > 0) {
			$terminatecauseid = $this -> dialstatus_rev_list[$dialstatus];
        } else {
			$terminatecauseid = 0;
        }

        // CALLTYPE -  0 = NORMAL CALL ; 1 = VOIP CALL (SIP/IAX) ; 2= DIDCALL + TRUNK ; 3 = VOIP CALL DID ; 4 = CALLBACK call
        if ($didcall == 3) {
			$calltype = $didcall;
        } elseif ($didcall) {
			$calltype = 2;
        } elseif ($callback) {
			$calltype = 4;
		$terminatecauseid = 1;
        } elseif ($A2B->recalltime) {
			$calltype = 5;
        } else {
			$calltype = 0;
        }

		$card_id =  (!is_numeric($A2B->id_card)) ? '-1' : "'". $A2B->id_card ."'";
		$real_sessiontime = (!is_numeric($this->real_answeredtime)) ? 'NULL' : "'". $this->real_answeredtime ."'";
		$id_tariffgroup = (!isset($id_tariffgroup) || !is_numeric($id_tariffgroup)) ? 'NULL' : "'$id_tariffgroup'";
		$id_tariffplan = (!isset($id_tariffplan) || !is_numeric($id_tariffplan)) ? 'NULL' : "'$id_tariffplan'";
		$id_ratecard = (!isset($id_ratecard) || !is_numeric($id_ratecard)) ? 'NULL' : "'$id_ratecard'";
		$trunk_id =  ($trunk_id) ? "'". $trunk_id ."'" : "'". $this->usedtrunk ."'";
		$id_card_package_offer = (!is_numeric($id_card_package_offer)) ? 'NULL' : "'$id_card_package_offer'";
		$calldestination = (!isset($calldestination) || !is_numeric($calldestination) || ($didcall && $dialstatus != 'ANSWERED')) ? -1 : $calldestination;
		$card_caller = (isset($A2B->card_caller)) ? "'$A2B->card_caller'" : "'0'";
		$id_did = (!isset($A2B->id_did) || !is_numeric($A2B->id_did)) ? 'NULL' : "'$A2B->id_did'";

		$QUERY_COLUMN = "uniqueid, sessionid, card_id, card_caller, nasipaddress, starttime, sessiontime, real_sessiontime, calledstation, ".
			" terminatecauseid, stoptime, sessionbill, id_tariffgroup, id_tariffplan, id_ratecard, " .
			" id_trunk, src, sipiax, buycost, id_card_package_offer, dnid, destination, id_did";
		$QUERY = "INSERT INTO cc_call ($QUERY_COLUMN) VALUES ('".$A2B->uniqueid."', '".$A2B->channel."', ".
			"$card_id, $card_caller, '".$A2B->hostname."', ";

		if ($A2B->config["global"]['cache_enabled']) {
			$QUERY .= " datetime( strftime('%s','now') - $sessiontime, 'unixepoch','localtime')";	
		} else {
			$QUERY .= "SUBDATE(CURRENT_TIMESTAMP, INTERVAL $sessiontime SECOND) ";
		}

		$QUERY .= 	", '$sessiontime', $real_sessiontime, '$calledstation', $terminatecauseid, ";
		if ($A2B->config["global"]['cache_enabled']) {
			$QUERY .= "datetime('now','localtime')";
		} else {
			$QUERY .= "now()";
		}

		$QUERY .= " , '$signe_cc_call".a2b_round(abs($cost))."', ".
					" $id_tariffgroup, $id_tariffplan, $id_ratecard, $trunk_id, '".$A2B->CallerID."', '$calltype', ".
					" '$buycost', $id_card_package_offer, '".$A2B->dnid."', $calldestination, $id_did)";

		if ($A2B->config["global"]['cache_enabled']) {
			 //insert query in the cache system
			$create = false;
			if (! file_exists( $A2B -> config["global"]['cache_path']))
				$create = true;
			if ($db = sqlite_open($A2B -> config["global"]['cache_path'], 0666, $sqliteerror)) {
			    if ($create)
					sqlite_query($db,"CREATE TABLE cc_call ($QUERY_COLUMN)");
			    sqlite_query($db,$QUERY);
			    sqlite_close($db);
			} else {
				$A2B -> debug( ERROR, $agi, __FILE__, __LINE__, "[Error to connect to cache : $sqliteerror]\n");
			}
		} else {
			$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY, 0);
			$A2B -> debug( INFO, $agi, __FILE__, __LINE__, "[CC_asterisk_stop : SQL: DONE : result=".$result."]");
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_asterisk_stop : SQL: $QUERY]");
		}
		if ($sessiontime>0) {
			
			if (!isset($td)) $td = (isset($this->td))?$this->td:"";
			if ($didcall==0 && $callback==0) {
				$myclause_nodidcall = " , redial='".$calledstation."' ";
			} else {
				$myclause_nodidcall='';
			}
			
			if (!defined($myclause_nodidcall)) {
				$myclause_nodidcall = null;
			}
			//Update the global credit
			$A2B -> credit = $A2B -> credit + $cost;
			
			if ($A2B->nbused>0) {
				$QUERY = "UPDATE cc_card SET credit= credit$signe".a2b_round(abs($cost))." $myclause_nodidcall,  lastuse=now(), nbused=nbused+1 WHERE username='".$A2B->username."'";
			} else {
				$QUERY = "UPDATE cc_card SET credit= credit$signe".a2b_round(abs($cost))." $myclause_nodidcall,  lastuse=now(), firstusedate=now(), nbused=nbused+1 WHERE username='".
				$A2B->username."'";
			}
			
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[CC_asterisk_stop 1.2: SQL: $QUERY]");
			$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY, 0);

			$QUERY = "SELECT period$td, UNIX_TIMESTAMP(periodexpiry$td), periodcount$td FROM cc_trunk WHERE id_trunk=$trunk_id LIMIT 1";
			$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);
			if (is_array($result) && count($result)>0) {
				$QUERY = "UPDATE cc_trunk SET secondusedreal = secondusedreal + $sessiontime, periodcount$td = ";
				$tqr = " / billblocksec$td) * billblocksec$td, lastcallstoptime$td=now()";
				$tqrperiod = ", periodexpiry$td = ";
				$period		= $result[0][0];
				$periodexpiry	= $result[0][1];
				$periodcount	= $result[0][2];
				if ($period == 31) {
				    if ($periodexpiry <= time()) {
					while ($periodexpiry <= time()) {
					    $preperiodexpiry = $periodexpiry;
					    $periodexpiry = strtotime('1 month', $periodexpiry);
					    $periodcount = 0;
					}
				    } else $preperiodexpiry = strtotime('-1 month', $periodexpiry);
				    $tqr .= $tqrperiod . "FROM_UNIXTIME($periodexpiry) ";
				} else {
				    $period *= 86400;
				    if ($period>1) {
					if ($periodexpiry <= time()) {
					    while ($periodexpiry <= time()) $periodexpiry += 86400;
					    $periodexpiry += $period - 86400;
					    $periodcount = 0;
					}
					$tqr .= $tqrperiod . "FROM_UNIXTIME($periodexpiry) ";
				    }
				$preperiodexpiry = $periodexpiry - $period;
				}
				if (time()-$sessiontime <= $preperiodexpiry && $period > 1 ) {
					$limitsessiontime = time() - $preperiodexpiry;
				} else {
					$limitsessiontime = $sessiontime;
				}
				$QUERY .= "$periodcount + ceil($limitsessiontime" . $tqr . "WHERE id_trunk=$trunk_id";
			} else $QUERY = "UPDATE cc_trunk SET secondusedreal = secondusedreal + $sessiontime WHERE id_trunk=$trunk_id";
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, $QUERY);
			$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY, 0);

			$QUERY = "UPDATE cc_tariffplan SET secondusedreal = secondusedreal + $sessiontime WHERE id=$id_tariffplan";
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, $QUERY);
			$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY, 0);
		}
		monitor_recognize($A2B);
	}
	
	/*
	 *	function would set when the trunk is used or when it release
	 */
	function trunk_start_inuse($agi, $A2B, $inuse, $wrapuptime=0) {

		if ($inuse) {
			$QUERY = "UPDATE cc_trunk SET inuse=inuse+1 WHERE id_trunk='".$this -> usedtrunk."'";
		} else {
			$QUERY = "UPDATE cc_trunk SET inuse=inuse-1, wrapnexttime = DATE_ADD(NOW(), INTERVAL $wrapuptime SECOND) WHERE id_trunk='".$this -> usedtrunk."'";
		}

		if ($agi) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[TRUNK STATUS UPDATE : $QUERY]");
		if (!$A2B -> CC_TESTING) $result = $A2B -> instance_table -> SQLExec ($A2B->DBHandle, $QUERY, 0);

		return 0;
	}

	/*
		RATE ENGINE - PERFORM CALLS
		$typecall = 1 -> predictive dialer
	*/
	function rate_engine_performcall ($agi, $destination, &$A2B, $typecall=0, $amicmd=false, &$ast=false) {

		$max_long = 36000000; //Maximum 10 hours
		$old_destination = $destination;

		if ($agi) $A2B -> debug( INFO, $agi, __FILE__, __LINE__, "Count of ratecard_obj = ".count($this -> ratecard_obj));
		for ($k=0;$k<count($this -> ratecard_obj);$k++) {
			$loop_failover = 0;
			$destination = $old_destination;
			$firstrand = false;
			$intellect_count = -1;
			$status = 1;
			$outcid = 0;
			//$this->answeredtime='60';
			//$this->dialstatus='ANSWERED';
			// LOOOOP FOR THE FAILOVER LIMITED TO failover_recursive_limit
			while ($loop_failover == 0 || ($loop_failover <= $A2B->agiconfig['failover_recursive_limit'] && is_numeric($failover_trunk) && $failover_trunk>=0
			    && $this->dialstatus != "ANSWER" && $this->dialstatus != "CANCEL" && time() - $timecur < 10 && ($this->dialstatus == "CHANUNAVAIL"
			    || $this->dialstatus == "CONGESTION" || $inuse>=$maxuse && $maxuse!=-1 || $timecur < $startdate || $stopdate <= $timecur || ($maxsecperperiod!=-1
			    && $periodcount >= $maxsecperperiod - $timeleft && $periodexpiry > time()) || $periodexpiry <= time()))) {

				$this -> td = $this -> prefixclause = '';
				$this -> real_answeredtime = $this -> answeredtime = $wrapuptime = 0;
				$destination=$old_destination;

				if ($loop_failover == 0 && $intellect_count == -1) {
				    if ($this -> ratecard_obj[$k][34]!='-1') {
					$usetrunk=35;
					$this -> usedtrunk = $this -> ratecard_obj[$k][34];
					$usetrunk_failover=1;
				    } else {
					$usetrunk=30;
					$this -> usedtrunk = $this -> ratecard_obj[$k][29];
					$usetrunk_failover=0;
				    }
				    $failover_trunk	= $this->usedtrunk;
				    $prefix		= $this -> ratecard_obj[$k][$usetrunk];
				    $tech		= $this -> ratecard_obj[$k][$usetrunk+1];
				    $ipaddress		= $this -> ratecard_obj[$k][$usetrunk+2];
				    $removeprefix	= explode(",",$this -> ratecard_obj[$k][$usetrunk+3]);
				    if ($typecall==1) $timeout = $A2B -> config["callback"]['predictivedialer_maxtime_tocall'];
				    else $timeout	= $this -> ratecard_obj[$k]['timeout'];
				    $musiconhold	= $this -> ratecard_obj[$k][39];
				    $next_failover_trunk= $this -> ratecard_obj[$k][40+$usetrunk_failover];
				    $addparameter	= $this -> ratecard_obj[$k][42+$usetrunk_failover];
				    $cidgroupidrate	= $this -> ratecard_obj[$k][44];
				    $inuse		= $this -> ratecard_obj[$k][48+$usetrunk_failover];
				    $maxuse		= $this -> ratecard_obj[$k][50+$usetrunk_failover];
				    $ifmaxuse		= $this -> ratecard_obj[$k][52+$usetrunk_failover];
				    $cidgroupid		= $this -> ratecard_obj[$k][79+$usetrunk_failover];
				    $wrapuprange	= explode(",",$this -> ratecard_obj[$k][82+$usetrunk_failover]);
				} else {
				    $this -> usedtrunk = $failover_trunk;
				    $QUERY = "SELECT trunkprefix, providertech, providerip, removeprefix, failover_trunk, status, inuse, IF(wrapnexttime>NOW(),0,maxuse), if_max_use, outbound_cidgroup_id, addparameter, wrapuptime FROM cc_trunk WHERE id_trunk='$this->usedtrunk' LIMIT 1";
				    $A2B->instance_table = new Table();
				    $result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);

				    if (is_array($result) && count($result)>0) {
					//DO SELECT WITH THE FAILOVER_TRUNKID
					$prefix			= $result[0][0];
					$tech			= $result[0][1];
					$ipaddress		= $result[0][2];
					$removeprefix		= explode(",",$result[0][3]);
					$next_failover_trunk	= $result[0][4];
					$status			= $result[0][5];
					$inuse			= $result[0][6];
					$maxuse			= $result[0][7];
					$ifmaxuse		= $result[0][8];
					$cidgroupid		= $result[0][9];
					$addparameter		= $result[0][10];
					$wrapuprange		= explode(",",$result[0][11]);
				    } else break;
				}
				if ($cidgroupid == -1) $cidgroupid = $cidgroupidrate;
				
				if (is_array($removeprefix) && count($removeprefix)>0) {
					foreach ($removeprefix as $testprefix) {
						if (substr($destination,0,strlen($testprefix))==$testprefix) {
							$destination = substr($destination,strlen($testprefix));
							break;
						}
					}
				}

				$max_len_prefix = min(strlen($destination), 15);
				$prefixclausemain = '(';
				while ($max_len_prefix > 0 ) {
					$prefixclausemain .= "dialprefixa='".substr($destination,0,$max_len_prefix)."' OR ";
					$max_len_prefix--;
				}
				$prefixclausemain .= "dialprefixa='defaultprefix')";
				$prefixclausemain .= " OR (dialprefixa LIKE '&_%' ESCAPE '&' AND '$destination' ";
				$prefixclausemain .= "REGEXP REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CONCAT('^', dialprefixa, '$'), ";
				$prefixclausemain .= "'X', '[[:digit:]]'), 'Z', '[1-9]'), 'N', '[2-9]'), '.', '.+'), '_', ''))";
				$prefixclause = $prefixclausemain;
				$periodexpiry = 0;
				$maxsecperperiod = -1;
				$periodcur = 1;
				$timecur = time();
				$startdate = $timecur - 10;
				$stopdate = $timecur + 10;
				$usetrunk_failover = 0;
				$trunktimeout = -10;
				$timeleft = 0;
				$td = 'a';
				$QUERY = "SELECT maxsecperperioda, periodcounta, UNIX_TIMESTAMP(periodexpirya), failover_trunka, perioda, UNIX_TIMESTAMP(startdatea), UNIX_TIMESTAMP(stopdatea),timelefta
					FROM cc_trunk WHERE id_trunk='".$this -> usedtrunk."' AND ($prefixclause) LIMIT 1";
				$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);
				if (!is_array($result) || count($result)==0) {
					$usetrunk_failover = 8;
					$td = 'b';
					$prefixclause = preg_replace('/dialprefixa/','dialprefixb',$prefixclause);
					$QUERY = "SELECT maxsecperperiodb, periodcountb, UNIX_TIMESTAMP(periodexpiryb), failover_trunkb, periodb, UNIX_TIMESTAMP(startdateb), UNIX_TIMESTAMP(stopdateb), timeleftb
						FROM cc_trunk WHERE id_trunk='".$this -> usedtrunk."' AND ($prefixclause) LIMIT 1";
					$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);
				}
				if (is_array($result) && count($result)>0) {
				    $this -> td = $td;
				    $this -> prefixclause = $prefixclause;
				    if (!$firstrand) {
					if ($intellect_count == -1) {
					    $prefixclausea = preg_replace('/dialprefixa/','an.dialprefixa',$prefixclausemain);
					    $prefixclauseb = preg_replace('/dialprefixa/','bn.dialprefixb',$prefixclausemain);
					    $prefixclause  = preg_replace('/dialprefixa/','bn.dialprefixa',$prefixclausemain);
					    $prefixclausec = preg_replace('/dialprefixa/','cn.dialprefixa',$prefixclausemain);
					    $prefixclaused = preg_replace('/dialprefixa/','cn.dialprefixb',$prefixclausemain);
					    $QUERY = "SELECT IF(cn.status,-1,IFNULL(IF(an.maxsecperperioda<0,IF(an.periodexpirya>NOW(),-an.periodcounta-2,-2),an.maxsecperperioda-(an.periodcounta*(an.periodexpirya>NOW()))),
										    IF(bn.maxsecperperiodb<0,IF(bn.periodexpiryb>NOW(),-bn.periodcountb-2,-2),bn.maxsecperperiodb-(bn.periodcountb*(bn.periodexpiryb>NOW()))))) AS duration,
					                     trunkpercentage,
					                     trunk_depend$td FROM cc_trunk_rand
						LEFT JOIN cc_trunk AS an ON an.id_trunk=trunk_depend$td AND ($prefixclausea) AND an.startdatea<=NOW() AND an.stopdatea>NOW() AND (((an.maxsecperperioda-an.periodcounta>=an.timelefta OR an.maxsecperperioda<0) AND an.periodexpirya>NOW()) OR an.perioda>0) AND (an.maxuse>an.inuse OR an.maxuse<0) AND an.status=1
						LEFT JOIN cc_trunk AS bn ON bn.id_trunk=trunk_depend$td AND ($prefixclauseb) AND bn.startdateb<=NOW() AND bn.stopdateb>NOW() AND (((bn.maxsecperperiodb-bn.periodcountb>=bn.timeleftb OR bn.maxsecperperiodb<0) AND bn.periodexpiryb>NOW()) OR bn.periodb>0) AND (bn.maxuse>bn.inuse OR bn.maxuse<0) AND bn.status=1 AND NOT ($prefixclause)
						LEFT JOIN cc_trunk AS cn ON cn.id_trunk=trunk_depend$td AND cn.status=1 AND (cn.maxuse>cn.inuse OR cn.maxuse<0) AND NOT (($prefixclausec) OR ($prefixclaused))
						WHERE trunk_id=$this->usedtrunk AND trunk_depend$td<>0 ORDER BY IF(duration AND trunkpercentage>0,trunkpercentage,32768)";
					    $resultrand = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);
					} else $resultrand = $intellecttrunks;
					if (is_array($resultrand) && count($resultrand)>0) {
						$trunkrand = $this -> usedtrunk;
						$intellecttrunks = $resultrand;
						$firstrand = true;
						$sum_percent = 0;
						$count_minus = 0;
						foreach ($resultrand as $valu_key => $valu_val) {
							if (is_numeric($valu_val[0]) && $valu_val[1]>0) $resultrand[$valu_key][1] = $sum_percent += $valu_val[1];
							else $resultrand[$valu_key][1] = 0;
							if (!is_numeric($valu_val[0])) $count_minus++;
						}
						if ($intellect_count == -1) $intellect_count = $valu_key - $count_minus;
						else $intellect_count--;

						if ($sum_percent>0) {
							$randgo = rand(1,$sum_percent);
							foreach ($resultrand as $intellect_key => $valu_val)	{
								if ($valu_val[1]>=$randgo) {
									$intellecttrunks[$intellect_key][1] = 0;
									if ($valu_val[2]<>$this->usedtrunk) {
										$failover_trunk = $valu_val[2];
										continue 2;
									} else break;
								}
							}
						} else {
							rsort($resultrand);
							$intellecttrunks = $resultrand;
							$intellectmarker = true;
							foreach ($resultrand as $intellect_key => $valu_val)	{
								if ($valu_val[0]<-1 && is_numeric($valu_val[0])) {
									$intellecttrunks[$intellect_key][0] = 0;
									$intellectmarker = false;
									if ($valu_val[2]<>$this->usedtrunk) {
									    $failover_trunk = $valu_val[2];
									    continue 2;
									} else break;
								}
							}
							if ($intellectmarker) {
							    if ($resultrand[$valu_key][0]>=0 || !is_numeric($resultrand[$valu_key][0])) $valu_key=0;
							    $intellecttrunks[$valu_key][0] = 0;
							    if ($resultrand[$valu_key][2]<>$this->usedtrunk) {
								$failover_trunk = $resultrand[$valu_key][2];
								continue;
							    }
							}
						}
					}
				    }
				    if ($loop_failover == 0 && $intellect_count == -1) {
					$next_failover_trunk	= $this -> ratecard_obj[$k][67+$usetrunk_failover];
					$startdate		= $this -> ratecard_obj[$k][68+$usetrunk_failover];
					$stopdate		= $this -> ratecard_obj[$k][69+$usetrunk_failover];
					if ($startdate <= $timecur && $timecur <= $stopdate) {
					    $periodcur		= $this -> ratecard_obj[$k][63+$usetrunk_failover];
					    $maxsecperperiod	= $this -> ratecard_obj[$k][64+$usetrunk_failover];
					    $periodcount	= $this -> ratecard_obj[$k][65+$usetrunk_failover];
					    $periodexpiry	= $this -> ratecard_obj[$k][66+$usetrunk_failover];
					    $timeleft		= $this -> ratecard_obj[$k][70+$usetrunk_failover];
					    if ($maxsecperperiod != -1) {
						if ($periodexpiry > $timecur) {
						    $trunktimeout = $maxsecperperiod - $periodcount;
						    if ($timeleft > $trunktimeout || $trunktimeout < 3) $trunktimeout = 0; else $trunktimeout = $trunktimeout - 2;
						} elseif ($periodcur >0) $trunktimeout = $maxsecperperiod -2;
					    }
					}
				    } else {
					$next_failover_trunk	= $result[0][3];
					$startdate		= $result[0][5];
					$stopdate		= $result[0][6];
					if ($startdate <= $timecur && $timecur <= $stopdate) {
						$maxsecperperiod	= $result[0][0];
						$periodcount		= $result[0][1];
						$periodexpiry		= $result[0][2];
						$periodcur		= $result[0][4];
						$timeleft		= $result[0][7];
						if ($maxsecperperiod != -1) {
							if ($periodexpiry > $timecur) {
								$trunktimeout = $maxsecperperiod - $periodcount;
								if ($timeleft > $trunktimeout || $trunktimeout < 3) $trunktimeout = 0; else $trunktimeout = $trunktimeout - 2;
							} elseif ($periodcur >0) $trunktimeout = $maxsecperperiod -2;
						}
					}
				    }
				}
				$prefixclausemain = preg_replace('/dialprefixa/','dialprefixmain',$prefixclausemain);
				$QUERY = "SELECT failover_trunk FROM cc_trunk WHERE id_trunk='".$this -> usedtrunk."' AND ($prefixclausemain) LIMIT 1";
				$result = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);
				if (is_array($result) && count($result)>0) $next_failover_trunk = $result[0][0];

				// Check if we will be able to use this route:
				//  if the trunk is activated and
				//  if there are less connection than it can support or there is an unlimited number of connections
				// If not, use the next failover trunk or next trunk in list
				if ($status == 0) {
					if ($agi) $A2B -> debug( WARN, $agi, __FILE__, __LINE__, "Failover trunk cannot be used because it is disabled. Now use next trunk\n");
					continue 2;
				}
				if (($maxuse!=-1 && $inuse >= $maxuse) || ($startdate > $timecur || $timecur >= $stopdate
				|| ($maxsecperperiod!=-1 && $periodcount >= $maxsecperperiod - $timeleft && $periodexpiry > $timecur) || ($periodexpiry <= $timecur && $periodcur==0))) {
					if ($agi) $A2B -> debug( WARN, $agi, __FILE__, __LINE__, "Failover trunk cannot be used because maximum number of connections on this trunk is already reached or limited.\n");
					// use failover trunk
					if ($ifmaxuse == 0 || $periodexpiry != 0) {
						if ($agi) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "Now using its failover trunk\n");
						if ($intellect_count > 0) {
						    $failover_trunk = $this -> usedtrunk;
						    $firstrand = false;
						} elseif ($next_failover_trunk == $failover_trunk) break;
						    else {
							if ($next_failover_trunk == -1 && $ifmaxuse == 1) continue 2;
							$intellect_count = -1;
							$loop_failover++;
							$failover_trunk = $next_failover_trunk;
						    }
						continue;
					} else {
						if ($agi) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "Now using next trunk\n");
						continue 2;
					}
				}
				$pos_dialingnumber = max(strpos($ipaddress, '%dialingnumber%'),strpos($ipaddress, '%none%'));
				$ipaddress = str_replace("%cardnumber%", $A2B->cardnumber, $ipaddress);
				$ipaddress = str_replace("%none%", '', $ipaddress);
				if (strncmp($destination, $prefix, strlen($prefix)) == 0 && strlen($prefix) > 1) $prefix="";
				$ipaddress = str_replace("%dialingnumber%", $prefix.$destination, $ipaddress);

				if ($pos_dialingnumber !== false) $channel = "$tech/$ipaddress";
				elseif ($A2B->agiconfig['switchdialcommand'] == 1) $channel = "$tech/$prefix$destination@$ipaddress";
					else $channel = "$tech/$ipaddress/$prefix$destination";

				$QUERY = "SELECT cid FROM cc_outbound_cid_list WHERE activated = 1 AND outbound_cid_group = $cidgroupid ORDER BY RAND() LIMIT 1";
				$A2B->instance_table = new Table();
				$cidresult = $A2B->instance_table -> SQLExec ($A2B -> DBHandle, $QUERY);
				if (is_array($cidresult) && count($cidresult)>0) {
					$outcid = $cidresult[0][0];
					//Uncomment this line if you want to save the outbound_cid in the CDR
					//$A2B -> CallerID = $outcid;
					if ($agi) {
					    $calleridname = $agi -> get_variable('CALLERID(name)', true);
					    $agi -> set_callerid($outcid);
					    $agi -> set_variable('CALLERID(ani)', $outcid);
					    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[EXEC SetCallerID : $outcid]");
					}
				}

				if ($typecall != 8 && (!$agi || $typecall == 9)) return array( $channel, $outcid, $this -> usedtrunk, $this -> td, $k );

				if ($agi) $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "app_callingcard: CIDGROUPID='$cidgroupid' OUTBOUND CID SELECTED IS '$outcid'.");

				if ($trunktimeout < 0)  $trunktimeout = $timeout;
				if ($A2B->recalltime) $dialparams = str_replace("%timeout%", min($timeout * 1000, $max_long, $trunktimeout * 1000, $A2B->recalltime * 1000), $A2B->agiconfig['dialcommand_param']);
					else $dialparams = str_replace("%timeout%", min($timeout * 1000, $max_long, $trunktimeout * 1000), $A2B->agiconfig['dialcommand_param']);

				if ($agi && strlen($musiconhold)>0 && $musiconhold!="selected") {
					$dialparams.= "m";
//					$myres = $agi->exec("SETMUSICONHOLD $musiconhold");
					$agi -> set_variable('CHANNEL(musicclass)', $musiconhold);
					$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "EXEC SETMUSICONHOLD $musiconhold");
//					$A2B -> streamfirst = false;
				}

				$dialstr = $channel.$dialparams;
				//ADDITIONAL PARAMETER 			%dialingnumber%,	%cardnumber%
				if (strlen($addparameter)>0) {
					$addparameter = str_replace("%cardnumber%", $A2B->cardnumber, $addparameter);
					$addparameter = str_replace("%dialingnumber%", $prefix.$destination, $addparameter);
					$dialstr .= $addparameter;
				}

				$this -> trunk_start_inuse($agi, $A2B, 1);
				if ($agi) {
				    $A2B -> debug( INFO, $agi, __FILE__, __LINE__, "FAILOVER app_callingcard: Dialing '$dialstr' with timeout of '$timeout'.\n");
				    if ($A2B->monitor == 1 || $A2B -> agiconfig['record_call'] == 1) {
					$A2B->dl_short = MONITOR_PATH . "/" . $A2B->username . "/" . date('Y') . "/" . date('n') . "/" . date('j') . "/";
					$dl_short = $A2B->dl_short . $A2B->uniqueid . ".";
					while (file_exists($dl_short . "WAV") || file_exists($dl_short . "wav") || file_exists($dl_short . "gsm") || file_exists($dl_short . "mp3")
					|| file_exists($dl_short . "sln") || file_exists($dl_short . "g723") || file_exists($dl_short . "g729")) {
						$A2B->uniqueid++;
						$dl_short = $A2B->dl_short . $A2B->uniqueid . ".";
					}
					$command_mixmonitor = $A2B -> format_parameters ("MixMonitor {$dl_short}{$A2B->agiconfig['monitor_formatfile']}|b");
					$myres = $agi->exec($command_mixmonitor);
					$A2B -> debug( INFO, $agi, __FILE__, __LINE__, "EXEC ". $command_mixmonitor);
				    }
				    // Count this call on the trunk
				    $agi -> set_variable('MASTER_CHANNEL(CHANNELCIDNUM)', $destination);
				    $agi -> set_variable('MASTER_CHANNEL(CALACCOUNT)', $A2B->cardnumber);
				    $myres = $A2B -> run_dial($agi, $dialstr);
				    $A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "DIAL FAILOVER $dialstr");

				    // check connection after dial(long pause) 
				    $A2B -> DbReConnect($agi);

				    if ($outcid <> 0) {
					$outcid = 0;
					$agi -> set_callerid($A2B -> CallerID);
					$agi -> set_variable('CALLERID(ani)', $A2B -> CallerID);
					$agi -> set_variable('CALLERID(name)', $calleridname);
				    }
				} elseif (is_array($amicmd)) {
				    write_log(LOGFILE_API_CALLBACK, basename(__FILE__) . ' line:' . __LINE__ . " ActionID = {$amicmd[5]} [#### Starting AMI ORIGINATE ####] $channel");
				    $ast->log("ActionID = {$amicmd[5]} [#### Starting AMI ORIGINATE ####] $channel");
				    $ast -> add_event_handler('OriginateResponse', 'originateresponse');
				    $ast -> actionid = $amicmd[5];
				    $amicmd[3] = substr_replace($amicmd[3],"RATECARD={$this->ratecard_obj[$k][6]},TRUNK=$this->usedtrunk,TD=$this->td",strpos($amicmd[3],"RATECARD"));
				    $res = $ast -> Originate($channel,$amicmd[0],$A2B -> config['callback']['context_callback'],$amicmd[1],NULL,NULL,
					$A2B -> config['callback']['timeout']*1000,$amicmd[2],$amicmd[3],$amicmd[4],true,$amicmd[5]);
				    write_log(LOGFILE_API_CALLBACK, basename(__FILE__) . ' line:' . __LINE__ . " ActionID = {$amicmd[5]} [####  RESULT AMI ORIGINATE  ####] {$res['Response']}");
				    $ast->log("ActionID = {$amicmd[5]} [#### RESULT AMI ORIGINATE  ####] {$res['Response']}");
				    if ($res['Response'] == "Success") {
				    write_log(LOGFILE_API_CALLBACK, basename(__FILE__) . ' line:' . __LINE__ . " ActionID = {$amicmd[5]} [#### Starting AMI WAIT_RESPONSE ####]");
				    $ast->log("ActionID = {$amicmd[5]} [#### Starting AMI WAIT_RESPONSE ####]");
					$res = $ast -> wait_response(true);
				    }
				    switch($res)
				    {
				     case  0: $this->dialstatus = "CHANUNAVAIL"; break;
				     case  1: $this->dialstatus = "BUSY"; break;
				     case  3: $this->dialstatus = "NOANSWER"; break;
				     case  4: $this->dialstatus = "ANSWER"; break;
//				     case  5: $this->dialstatus = "CONGESTION"; break;
				     case  8: $this->dialstatus = "CHANUNAVAIL"; break;
				     default: $this->dialstatus = "CONGESTION"; break;
				    }
				    write_log(LOGFILE_API_CALLBACK, basename(__FILE__) . ' line:' . __LINE__ . " ActionID = {$amicmd[5]} [####  RESULT AMI WAIT RESPONSE  ####] $channel  ".var_export($res, true)." = $this->dialstatus");
				    $ast->log("ActionID = {$amicmd[5]} [####  RESULT AMI WAIT RESPONSE  ####] $channel  ".var_export($res, true)." = $this->dialstatus");
				}
				if ($agi) {
				    if ($A2B->monitor == 1 || $A2B -> agiconfig['record_call'] == 1) {
					$myres = $agi->exec($A2B -> format_parameters ("StopMixMonitor"));
					$A2B -> debug( INFO, $agi, __FILE__, __LINE__, "EXEC StopMixMonitor (".$A2B->uniqueid.")");
				    }

				    $answeredtime = $agi->get_variable("ANSWEREDTIME",true);
				    if ($answeredtime == "")		$answeredtime = $agi->get_variable("CDR(billsec)",true);
				    $this -> real_answeredtime = $this -> answeredtime = $answeredtime;

				    $this -> dialstatus = $agi -> get_variable("DIALSTATUS",true);

				    $A2B -> debug( INFO, $agi, __FILE__, __LINE__, "[FAILOVER K=$k]:[ANSWEREDTIME=".$this->answeredtime."]:[DIALSTATUS=".$this->dialstatus."]");

				    if (is_array($wrapuprange)) {
					if (count($wrapuprange)==1) {
						$wrapuptime = $wrapuprange[0];
					} else {
						$wrapuptime = mt_rand($wrapuprange[0],$wrapuprange[1]);
					}
				    }
				}
				// Count this call on the trunk
				$this -> trunk_start_inuse($agi, $A2B, 0, $wrapuptime);

//				if (($this->dialstatus  == "CHANUNAVAIL" || $this->dialstatus  == "CONGESTION") && $intellect_count > 0) 
				if ($this->dialstatus  == "CHANUNAVAIL" && $intellect_count > 0) {
					$firstrand = false;
					$failover_trunk = $trunkrand;
					continue;
				}
				// IF THE FAILOVER TRUNK IS SAME AS THE ACTUAL TRUNK WE BREAK
				if ($next_failover_trunk == $failover_trunk) break;
				else $failover_trunk = $next_failover_trunk;
				$intellect_count = -1;
				$loop_failover++;

			} // END while LOOP FAILOVER
			if ($typecall == 8) {
			    if ($this->dialstatus  == "CHANUNAVAIL" || $this->dialstatus  == "CONGESTION") {
				$this -> real_answeredtime = $this -> answeredtime = 0;
				if ($A2B->agiconfig['failover_lc_prefix'] || $ifmaxuse == 1) continue;
			    }
			    return $res;
			}
			if (!$agi || $typecall == 9) continue;
			
			//# Ooh, something actually happened!

			if ($this->dialstatus  == "BUSY") {
				$this -> real_answeredtime = $this -> answeredtime = 0;
				if ($A2B->agiconfig['busy_timeout'] > 0)
					$res_busy = $agi->exec("Busy ".$A2B->agiconfig['busy_timeout']);
				if (array_search($agi -> channel_status('',true), array(AST_STATE_UP, AST_STATE_DOWN)) === false) $A2B -> let_stream_listening($agi);
				$agi-> stream_file('prepaid-isbusy', '#');
			} elseif ($this->dialstatus == "NOANSWER") {
				$this -> real_answeredtime = $this -> answeredtime = 0;
				if (array_search($agi -> channel_status('',true), array(AST_STATE_UP, AST_STATE_DOWN)) === false) $A2B -> let_stream_listening($agi);
				$agi-> stream_file('prepaid-noanswer', '#');
			} elseif ($this->dialstatus == "CANCEL") {
				$this -> real_answeredtime = $this -> answeredtime = 0;
			} elseif (($this->dialstatus  == "CHANUNAVAIL") || 
					  ($this->dialstatus  == "CONGESTION")) {
				$this -> real_answeredtime = $this -> answeredtime = 0;
				// Check if we will failover for LCR/LCD prefix - better false for an exact billing on resell
				if ($A2B->agiconfig['failover_lc_prefix'] || $ifmaxuse == 1) {
					continue;
				}
				return false;
			} elseif ($this->dialstatus == "ANSWER") {
				$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "-> dialstatus : ".$this->dialstatus.", answered time is ".$this->answeredtime." \n");
			}

			$this->usedratecard = $k;
			$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[USEDRATECARD=".$this -> usedratecard."]");
			return true;
		} // End for

		$this -> usedratecard = $k-$loop_failover;
		$A2B -> debug( DEBUG, $agi, __FILE__, __LINE__, "[USEDRATECARD - FAIL =".$this -> usedratecard."]");
		return false;

	}


};
