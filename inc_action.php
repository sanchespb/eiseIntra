<?php
class eiseAction {

public static $ts = array('ETD', 'ATD', 'ETA', 'ATA');
	
public function __construct($item, $arrAct){

	$this->item = $item;
	$this->oSQL = $item->oSQL;
	$this->intra = $item->intra;
	$this->entID = $item->conf['entID'];

    $nd = $arrAct;
	
	if(!$arrAct['actID'] && !$arrAct['aclGUID'])
		$arrAct['actID'] = 2;

	if($arrAct['aclGUID']){
		$this->item->refresh(array('Master', 'ACL'));
        $this->arrAction = $this->item->item['ACL'][$arrAct['aclGUID']];
        foreach($nd as $field=>$value){
            if(strpos($field, '_'.$arrAct['aclGUID'])===False)
                continue;
            $nd[str_replace('_'.$arrAct['aclGUID'], '', $field)] = $value;
        }
	} else {
		$this->conf = $item->conf['ACT'][(string)$arrAct['actID']];
	}

    $nd_SQL = $this->intra->arrPHP2SQL($nd, $this->item->table['columns_types']);

	$this->arrAction = array_merge($this->conf, $nd_SQL);

    switch($this->arrAction['actID']){
        case 0:
        case 3:
            $this->arrAction['aclOldStatusID'] = $this->arrAction['actOldStatusID'][0];
            $this->arrAction['aclNewStatusID'] = $this->arrAction['actNewStatusID'][0];
            break;
        case 2:
            $this->arrAction['aclOldStatusID'] = $item->staID;
            $this->arrAction['aclNewStatusID'] = $item->staID;
            break;
        default:
            break;
    }

	if(!$this->conf && !$arrAct['aclGUID'])
		throw new Exception("Action is not defined properly. Debug info: ".var_dump($arrAct, true));

}

public function execute(){

    // proceed with the action
    if ($this->arrAction["actFlagAutocomplete"] && !$this->arrAction["aclGUID"]){

        $this->add();
        $this->validate();
        $this->finish();
        
    } else {
        if ($this->arrAction["aclGUID"]==""){
            
            $this->add();
            
        } else {
            switch ($this->arrAction["aclToDo"]) {
                case "finish":
                	$this->validate();
                    $this->finish();
                    break;
                case "start":
                    $this->start();
                    break;
                case "cancel":
                	$this->validate();
                    $this->cancel();
                    break;
            }
        }
        
    }
    
    $aclGUID = $this->arrAction["aclGUID"];
    
    return $aclGUID;

}

public function add(){

    // 1. obtaining aclGUID
    $this->arrAction["aclGUID"] = $this->oSQL->d("SELECT UUID()");

    $item_before = $this->item->item_before;
    unset($item_before['ACL']);
    unset($item_before['STL']);

    $timestamps = $this->getTimeStamps();

    // 2. insert new ACL
    $sqlInsACL = "INSERT INTO stbl_action_log SET
        aclGUID = '{$this->arrAction["aclGUID"]}'
        , aclActionID = ".(int)$this->arrAction["actID"]."
        , aclEntityItemID = '".$this->item->id."'
        , aclOldStatusID = ".($this->arrAction['aclOldStatusID']===null ? "NULL"  : "'".(string)$this->arrAction['aclOldStatusID']."'")."
        , aclNewStatusID = ".($this->arrAction['aclNewStatusID']===null ? "NULL"  : "'".(string)$this->arrAction['aclNewStatusID']."'")."
            , aclActionPhase = 0
            {$timestamps}
            , aclItemBefore = ".$this->oSQL->e(json_encode($item_before))."
            , aclItemDiff = NULL
            , aclItemAfter = NULL
            , aclItemTraced = ".$this->oSQL->e(json_encode($this->getTraceData()))."
        , aclComments = NULL
        , aclInsertBy = '{$this->intra->usrID}', aclInsertDate = NOW(), aclEditBy='{$this->intra->usrID}', aclEditDate=NOW()";
     
    $this->oSQL->q($sqlInsACL);  
    
    // 3. Trigger onActionPlan hook
    $this->item->onActionPlan($this->arrAction['actID'], $this->arrAction['aclOldStatusID'], $this->arrAction['aclNewStatusID']);

    $this->arrAction['aclActionPhase'] = 0;

    return $this->arrAction["aclGUID"];

}

public function validate(){

	$aclOldStatusID = (isset($this->arrAction["aclOldStatusID"]) 
	    ? $this->arrAction["aclOldStatusID"] 
	    : $this->item->item["{$this->item->conf['prefix']}StatusID"]
	    );

	if($this->arrAction['actID']<=3){
	    switch( $this->arrAction['actID'] ){
	        case 1:
	            if($aclOldStatusID>0)
	                throw new Exception('Item is already created');
	            break;
	        case 2:
	            if(!$this->item->conf['STA'][$aclOldStatusID]['staFlagCanUpdate'] && !$this->flagFullEdit)
	                throw new Exception('Update is not allowed');
	            break;
	        case 3:
	            if(!$this->item->conf['STA'][$aclOldStatusID]['staFlagCanDelete'])
	                throw new Exception('Delete is not allowed');
	            break;
	        default:
	            break;
	    }
	} else {
	    if(!in_array($aclOldStatusID, $this->conf['actOldStatusID'])){
            throw new Exception($this->intra->translate("Action \"%s\" cannot be run for origin status \"%s\""
                , ( $this->conf['actTitle'.$this->intra->local] ? $this->conf['actTitle'.$this->intra->local] : $this->conf['actTitle'])
                , $this->item->conf['STA'][(string)$this->arrAction["aclOldStatusID"]]['staTitle'.$this->intra->local]));
	    }
	    if(!in_array($this->arrAction["aclNewStatusID"], $this->conf['actNewStatusID'])){
	        throw new Exception($this->intra->translate("Action \"%s\" cannot be run for destination status \"%s\""
                , ( $this->conf['actTitle'.$this->intra->local] ? $this->conf['actTitle'.$this->intra->local] : $this->conf['actTitle'])
                , $this->item->conf['STA'][(string)$this->arrAction["aclNewStatusID"]]['staTitle'.$this->intra->local]));
	    }
	}
}

public function finish(){

	if (!$this->arrAction["aclActionPhase"]){
        $this->item->onActionStart($this->arrAction['actID'], $this->arrAction['aclOldStatusID'], $this->arrAction['aclNewStatusID']);
    }

    $this->checkTimeLine();
    $this->checkMandatoryFields();

    $item_before = self::itemCleanUp($this->item->item_before);

    $this->item->refresh();

    $item_after = self::itemCleanUp($this->item->item);

    $timestamps = $this->getTimeStamps();
    $aTraced = $this->getTraceData();
        
    // update started action as completed
    $sqlUpdACL = "UPDATE stbl_action_log SET
        aclActionPhase = 2
        , aclItemAfter = ".$this->oSQL->e(json_encode($item_after))."
        {$timestamps}
        ".($this->arrAction["actID"]!="2" && count($aTraced)>0
            ? ", aclItemTraced=".$this->oSQL->e(json_encode($aTraced))
            : ', aclItemTraced=NULL')."
        , aclStartBy=IFNULL(aclStartBy, '{$this->intra->usrID}'), aclStartDate=IFNULL(aclStartDate,NOW())
        , aclFinishBy=IFNULL(aclFinishBy, '{$this->intra->usrID}'), aclFinishDate=IFNULL(aclFinishDate, NOW())
        , aclEditDate=NOW(), aclEditBy='{$this->intra->usrID}'
        WHERE aclGUID='".$this->arrAction["aclGUID"]."'";

    $this->oSQL->q($sqlUpdACL);
    
    if ($this->arrAction["actID"]!="2") {

        $tracedFields = $this->intra->getSQLFields($this->item->table, $aTraced);
        $userstamps = $this->getUserStamps();

        $sqlMaster = "UPDATE {$this->item->conf['table']} LEFT OUTER JOIN stbl_action_log ON aclGUID='{$this->arrAction['aclGUID']}' SET
            {$this->item->conf['prefix']}ActionLogID=aclGUID
            {$tracedFields}
            {$userstamps}
            , {$this->item->conf['prefix']}EditBy='{$this->intra->usrID}', {$this->item->conf['prefix']}EditDate=NOW()
        WHERE ".$this->item->getSQLWhere();

        $this->oSQL->q($sqlMaster);

    }
    
    $this->item->onActionFinish($this->arrAction['actID'], $this->arrAction['aclOldStatusID'], $this->arrAction['aclNewStatusID']);

    // if status is changed or action requires status stay interruption, we insert status log entry and update master table
    if (((string)$this->arrAction["aclOldStatusID"]!=(string)$this->arrAction["aclNewStatusID"]
          && (string)$this->arrAction["aclNewStatusID"]!=""
        )
        || $this->arrAction["actFlagInterruptStatusStay"]){

        $sql = Array();
        $stlGUID = $this->oSQL->get_data($this->oSQL->do_query("SELECT UUID()"));
        
        $sql[] = "UPDATE stbl_status_log SET
        stlATD=(SELECT IFNULL(aclATD, aclATA) FROM stbl_action_log WHERE aclGUID='{$this->arrAction["aclGUID"]}')
        , stlDepartureActionID='{$this->arrAction["aclGUID"]}'
        , stlEditBy='{$this->intra->usrID}', stlEditDate=NOW()
        WHERE stlEntityItemID='{$this->item->id}' AND stlATD IS NULL";

        $sql[] = "INSERT INTO stbl_status_log (
            stlGUID
            , stlEntityID
            , stlEntityItemID
            , stlStatusID
            , stlArrivalActionID
            , stlDepartureActionID
            , stlTitle
            , stlTitleLocal
            , stlATA
            , stlATD
            , stlInsertBy, stlInsertDate, stlEditBy, stlEditDate
            ) SELECT
            '$stlGUID' as stlGUID
            , '{$this->entID}' as stlEntityID
            , aclEntityItemID
            , aclNewStatusID as stlStatusID
            , aclGUID as stlArrivalActionID
            , NULL as stlDepartureActionID
            , staTitle as stlTitle
            , staTitleLocal as stlTitleLocal
            , aclATA as stlATA
            , NULL as stlATD
            , '{$this->intra->usrID}' as stlInsertBy, NOW() as stlInsertDate, '{$this->intra->usrID}' as stlEditBy, NOW() as stlEditDate
            FROM stbl_action_log 
            INNER JOIN stbl_action ON actID=aclActionID
            INNER JOIN stbl_status ON aclNewStatusID=staID AND staEntityID='{$this->item->conf['entID']}'
            WHERE aclGUID='{$this->arrAction['aclGUID']}'";
        
        $arrSAT = $this->conf['STA'][$this->arrAction['aclNewStatusID']]['satFlagTrackOnArrival'];
        if (count($arrSAT)>0){
            $sqlSAT = "INSERT INTO {$this->conf["entTable"]}_log (
                l{$this->conf['entPrefix']}GUID
                , l{$this->conf['entPrefix']}EditBy , l{$this->conf['entPrefix']}EditDate, l{$this->conf['entPrefix']}InsertBy, l{$this->conf['entPrefix']}InsertDate
                ";
            foreach($arrSAT as $atrID=>$x)
                $sqlSAT .= ", l{$atrID}";
            $sqlSAT .= ") SELECT 
                '$stlGUID' as l{$this->conf['entPrefix']}GUID
                , '{$this->intra->usrID}' as l{$this->conf['entPrefix']}EditBy , NOW() as l{$this->conf['entPrefix']}EditDate, '{$this->intra->usrID}' as {$this->conf['entPrefix']}InsertBy, NOW() as {$this->conf['entPrefix']}InsertDate
                ";
            foreach($arrSAT as $atrID=>$ix)
                $sqlSAT .= ", {$atrID}";
            $sqlSAT .= " FROM {$this->item->conf["entTable"]} WHERE {$this->item->table['PK'][0]}='{$this->item->id}'";
            $sql[] = $sqlSAT;
        }
        
        // after action is done, we update entity table with last status action log id
        $sql[] = "UPDATE {$this->item->conf["entTable"]} SET
            {$this->item->conf['prefix']}ActionLogID='{$this->arrAction["aclGUID"]}'
            , {$this->item->conf['prefix']}StatusActionLogID='{$this->arrAction["aclGUID"]}'
            , {$this->item->conf['prefix']}StatusID=".(int)$this->arrAction["aclNewStatusID"]."
            , {$this->item->conf['prefix']}EditBy='{$this->intra->usrID}', {$this->item->conf['prefix']}EditDate=NOW()
            WHERE {$this->item->table['PK'][0]}='{$this->item->id}'";
        
        for($i=0;$i<count($sql);$i++){
            $this->oSQL->do_query($sql[$i]);
        }

        $this->item->onStatusArrival($this->arrAction['aclNewStatusID']);

    }

    $this->item->staID = $this->arrAction['aclNewStatusID'];

}

function checkTimeLine(){
	
    $oSQL = $this->oSQL;
    
	$entID = $this->item->conf["entID"];
    $entItemID = $this->item->id;
	$aclGUID = $this->arrAction["aclGUID"];
	
	if ($this->arrAction["actID"]=="2")
		return true;
		
	
	$sqlMaxATA = "SELECT 
		CASE WHEN DATEDIFF(
			(SELECT aclATA FROM stbl_action_log WHERE aclGUID='{$aclGUID}')
			, MAX(aclATA)
			) < 0 THEN 0 ELSE 1 END as ATAnotLessThanPrevious
	FROM stbl_action_log 
	WHERE aclEntityItemID='{$entItemID}' AND aclActionPhase=2 AND aclActionID>2";
	if (!$oSQL->get_data($oSQL->do_query($sqlMaxATA))) {
		throw new Exception("ATA for execueted action cannot be in the past for {$entItemID}");
		die();
	}
	
	$sqlATAATD = "SELECT 
		CASE WHEN DATEDIFF (aclATA, IFNULL(aclATD, aclATA)) < 0 THEN 0 ELSE 1 END as ATAnotLessThanATD
		FROM stbl_action_log 
		WHERE aclGUID='{$aclGUID}'
		";
	if (!$oSQL->get_data($oSQL->do_query($sqlMaxATA))) {
		throw new Exception("ATA for execueted action cannot be less than ATD");
	}

	return true;
}

function checkMandatoryFields(){
    
    $oSQL = $this->oSQL;
    
    $entID = $this->item->conf['entID'];
    $entItemID = $this->item->id;
    $entTable = $this->item->conf["entTable"];
    $rwEnt = $this->item->item;
    $flagAutocomplete = $this->arrAction["actFlagAutocomplete"];
    $aclGUID = $this->arrAction["aclGUID"];

    $aMandatoryFails = array();
    $aChangedFails = array();

    foreach((array)$this->arrAction["aatFlagMandatory"] as $atrID => $rwATR){
            
        $oldValue = $this->item->item[$atrID];
        
        if ($this->arrAction["aclGUID"]==""){
            /*
            $flagIsMissingOnForm = (int)($this->arrAction['actFlagAutocomplete'] && !isset($this->arrNewData[$atrID]));
            $sqlCheckMandatory = "SELECT 
                CASE WHEN IFNULL({$atrID}, '')='' 
                AND NOT ".(int)$flagIsMissingOnForm." 
                THEN 0 ELSE 1 END as mandatoryOK 
                FROM {$entTable} WHERE {$this->entItemIDField}='{$entItemID}'";
            $sqlCheckChanges = "SELECT 
                CASE WHEN IFNULL({$atrID}, '')='{$rwEnt[$atrID]}' THEN 0 ELSE 1 END as changedOK 
                FROM {$entTable} WHERE {$this->entItemIDField}='{$entItemID}'";
                */
        } else {

            if($rwATR['aatFlagMandatory'] && !$this->arrAction[$atrID])
                $aMandatoryFails[] = $atrID;
            
            if($rwATR['aatFlagToChange'] && $this->arrAction[$atrID]!=$this->item->item[$atrID])
                $aChangedFails[] = $atrID;
            
        }
        
    }

    if (count($aMandatoryFails)){
        $strFields = '';
        foreach($aMandatoryFails as $field)
            $strFields .= ($strFields ? ', ' : '').$this->item->conf['ATR'][$field]["atrTitle{$this->intra->local}"];
        throw new Exception($this->intra->translate("These fields are required: %s for %s", $strFields, $this->item->id));
    } 
    
    if (count($aChangedFails)){
        $strFields = '';
        foreach($aChangedFails as $field)
            $strFields .= ($strFields ? ', ' : '').$this->item->conf['ATR'][$field]["atrTitle{$this->intra->local}"];
        throw new Exception($this->intra->translate("These fields should be changed: %s for %s", $strFields, $this->item->id));
    }
    
}

public function getTimeStamps(){

    $sql = '';

    $tsValues = array();

    foreach(self::$ts as $ts){
        $tsValues[$ts] = ($this->arrAction['actTrackPrecision']==='datetime'
                ? $this->intra->datetimePHP2SQL($this->arrAction[$this->arrAction['aatFlagTimestamp'][$ts]], 'NOW()')
                : $this->intra->datePHP2SQL($this->arrAction[$this->arrAction['aatFlagTimestamp'][$ts]], 'NOW()')
                );
    }

    if(!$this->arrAction['actFlagHasEstimates']){
        $tsValues['ETD'] = $tsValues['ATD'];
        $tsValues['ETA'] = $tsValues['ATA'];
    }

    if($this->arrAction['actFlagDepartureEqArrival']){
        $tsValues['ETD'] = $tsValues['ETA'];
        $tsValues['ATD'] = $tsValues['ATA'];   
    }

    foreach($tsValues as $ts=>$value){
        $sql .= "\n, acl{$ts} = {$value}";
    }

    return $sql;

}

public function getUserStamps(){
    $sql = '';
    foreach ( (array)$this->arrAction["aatFlagUserStamp"] as $atrID => $xx ) {
        if(array_key_exists($atrID, (array)$this->arrAction["aatFlagToTrack"]))
            continue;
        $sql .= "\n, {$atrID} = ".$oSQL->e($this->intra->usrID);
    }
    return $sql;
}

public function getTraceData(){

    $aRet = array();

    foreach((array)$this->arrAction['aatFlagToTrack'] as $field=>$props){
        $aRet[$field] = $this->arrAction[$field];
    }

    $aRet_SQL = $this->intra->arrPHP2SQL($aRet, $this->item->table['columns_types']);

    return $aRet_SQL;

}




static function itemCleanUp($item){
	unset($item['ACL']);
	unset($item['STL']);
	return $item;
}

}