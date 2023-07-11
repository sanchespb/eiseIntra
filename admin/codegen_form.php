<?php
include "common/auth.php";

$tblName = $_GET["tblName"];

define('CODE_INDENT', '    ');

function fieldsByArray($toGen, $arrTable, $strArrName = '$_POST', $indent=''){

    GLOBAL $intra;

    $strFields = '';
    $strPKs = $arrTable["PKCond"];

    foreach($arrTable['columns'] as $i=>$col){
        
        if (preg_match('/insert/i', $toGen)){

            if ($col["DataType"]=="PK"){
                switch($arrTable['PKtype']){
                    case "auto_increment":
                        break;
                    case "GUID":
                    default:
                        $strFields .= $col["Field"].' = '.($arrTable['PKtype']=="GUID" ? "@".$col["Field"] : "'\$".$col["Field"]."'");
                        break;
                }
                continue;
            }
        }
        if (preg_match('/update/i', $toGen) && $col["DataType"]=="PK"){
            continue;
        }

        if ($col["DataType"]=="activity_stamp"){
            if (preg_match('/update/i', $toGen) && preg_match("/insert/i",$col["Field"])
                || preg_match('/no_activity_stamp/i', $toGen))
                continue;
        }
        
        
        
        $rn = $prevCol["DataType"]=="activity_stamp" && $col["DataType"]=="activity_stamp" ? "" : "\n".$indent.CODE_INDENT;
      
        $strFields .= ($strFields!="" ? $rn.", " : "");
        $strFields .= (
                preg_match('/select/i', $toGen)
                ? $intra->getSQLValue($col).' as '.$col["Field"]
                : (preg_match('/fields/i', $toGen) 
                    ?  $col["Field"] 
                    :  $col["Field"].' = '.$intra->getSQLValue($col)
                    ) 
            );
        $prevCol = $col;
    
    }

    if($strArrName!='$_POST'){
        $strFields = str_replace('$_POST', $strArrName, $strFields);
    }

    return $indent.CODE_INDENT.$strFields;

}

$defaultSQLCodegenConf = array('indent'=>"", 'flagEiseItem'=>false, 'dataArray'=>'$_POST');

function getInsertCode($toGen, $arrTable, $conf = array()){
        
        GLOBAL $intra, $defaultSQLCodegenConf;
        $conf = array_merge($defaultSQLCodegenConf, $conf);
        
        $tblName = $arrTable["table"];

        $strCode = "";
        
        if ($arrTable['PKtype']=="GUID")
            $strCode .= "{$conf['indent']}SET @".$arrTable['PK'][0]."=UUID();\r\n\r\n";

        $strCode .= "{$conf['indent']}INSERT INTO $tblName ";
        if ($toGen == "INSERT SELECT"){
            $strCode .= "(\n{$conf['indent']}    ".fieldsByArray('INSERT FIELDS', $arrTable, $conf['dataArray'], $conf['indent'])."\r\n{$conf['indent']}) SELECT\n{$conf['indent']}".
                fieldsByArray('INSERT SELECT', $arrTable, $conf['dataArray'], $conf['indent']);
        } else {
            $strCode .= "SET\n".fieldsByArray('INSERT', $arrTable, $conf['dataArray'], $conf['indent']);
        }

        if ($arrTable['PKtype']=="GUID")
            $strCode .= ";\r\n\r\n{$conf['indent']}SELECT @".$arrTable['PK'][0]." as ".$arrTable['PK'][0].";";

        return $strCode;

}

function getUpdateCode($toGen, $arrTable, $conf = array()){
        
        GLOBAL $intra;
        
        $tblName = $arrTable["table"];
       

        $strCode = "UPDATE $tblName SET\r\n".$indent."    ";
        $strPKs = $arrTable["PKCond"];
        
        $strFields = fieldsByArray($toGen, $arrTable, $conf['dataArray'], $conf['indent']);
        
        $strCode .= $strFields;
        $strCode .= "\r\n{$conf['indent']}WHERE ".$strPKs;
        
        return $strCode;
}


$arrActions[]= Array ("title" => ($_GET["toGen"]=="EntTables" ? "Entity" : "Table")
       , "action" => "".($_GET["toGen"]=="EntTables" ? "entity_form.php?dbName=$dbName&entID=".$_GET['entID'] : "table_form.php?dbName=$dbName&tblName=$tblName")
       , "class"=> "ss_arrow_left"
    );

try{

    if ($tblName!=""  && !in_array($_GET["toGen"], Array("EntTables", "newtable", "MissingFields")))    
       $arrTable = $intra->getTableInfo($dbName, $tblName);

}catch(Exception $e){
    SetCookie("UserMessage", "ERROR:".$e->getMessage());
    header("Location: ".(isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : "about.php"));
    die();
}
    
switch ($_GET["toGen"]){
    case "newtable":
       $strPrefix = preg_replace("/^tbl_/", "", $tblName);
       $arrCstName = explode("_", $strPrefix);
       $nCsts = count($arrCstName);
       for ($i=0;$i<$nCsts;$i++) 
          $arrPrefix[$i] = preg_replace("/([euoai])/", "", $arrCstName[$i]);
       //print_r($arrCstName);
       switch($nCsts){
         case 1:
             $strPrefix = substr($arrPrefix[0], 0, 3);
             break;
         case 2:
             $strPrefix = substr($arrPrefix[0], 0, 1).substr($arrPrefix[1], 0, 2);
             break;
         case 3:
             $strPrefix = substr($arrPrefix[0], 0, 1).substr($arrPrefix[1], 0, 1).substr($arrPrefix[2], 0, 1);
             break;
       }
       
       $strCode = "CREATE TABLE `$tblName` (
  `".$strPrefix."ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `".$strPrefix."TitleLocal` varchar(255) NOT NULL DEFAULT '',
  `".$strPrefix."Title` varchar(255) NOT NULL DEFAULT '',
  `".$strPrefix."FlagDeleted` tinyint(4) NOT NULL DEFAULT '0',
  `".$strPrefix."InsertBy` varchar(50) DEFAULT NULL,
  `".$strPrefix."InsertDate` datetime DEFAULT NULL,
  `".$strPrefix."EditBy` varchar(50) DEFAULT NULL,
  `".$strPrefix."EditDate` datetime DEFAULT NULL,
  PRIMARY KEY (`".$strPrefix."ID`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8";
       
       //$oSQL->do_query($strCode);
    
       break;
    
    case "INSERT":
    case "INSERT PHP":
    case "INSERT SELECT":
        $strCode  = getInsertCode($_GET["toGen"], $arrTable);
        
        break;
    case "UPDATE":
    case "UPDATE PHP":
        
        $strCode = getUpdateCode($_GET["toGen"], $arrTable);
        
        break;
    case "easyGrid":
        
        include_once('../grid/inc_eiseGrid_codegen.php');
        $strCode = eiseGrid_codegen::code(array('tableName'=>$tblName, 'arrTable'=>$arrTable));
        break;
      
    case "eiseList":
        
        /*
        echo"<pre>";
        print_r($arrTable);
        echo"</pre>";
        die();
        //*/
        
        if ($arrTable["hasActivityStamp"]){
            //put changed date here
           $strEditByField = $arrTable["prefix"]."EditDate";
           
           $strCodeEditBy .= "\$lst->Columns[] = array('title' => \"Updated\"\r\n";
           $strCodeEditBy .= "        , 'type'=>\"date\"\r\n";
           
           $strCodeEditBy .= "        , 'field' => \"".$strEditByField."\"\r\n";
           $strCodeEditBy .= "        , 'filter' => \"".$strEditByField."\"\r\n";
           $strCodeEditBy .= "        , 'order_field' => \"".$strEditByField."\"\r\n";
           $strCodeEditBy .= "        );\r\n";
        }
        
        $strCode = "";
        
        $strCode .= "<?php\r\n".
                "include(\"common/auth.php\");\r\n".
                "//\$_DEBUG=true;\r\n".
                "\$arrJS[] = commonStuffRelativePath.'eiseList/eiseList.jQuery.js';\r\n".
                "\$arrCSS[] = commonStuffRelativePath.'eiseList/themes/default/screen.css';\r\n".
                "include_once(commonStuffAbsolutePath.'eiseList/inc_eiseList.php');\r\n\r\n";
                
        $strCode .= "\$arrJS[] = jQueryUIPath.'/jquery-ui.min.js';\r\n".
                "\$arrCSS[] = jQueryUIPath.'/jquery-ui.min.css';\r\n\r\n";
                
        $strCode .= "\$listName = \$listName ? \$listName : \"".$arrTable['prefix']."\";\r\n".
                "\$lst = new eiseList(\$oSQL, \$listName\r\n".
                "    , Array('title'=>\$arrUsrData[\"pagTitle\$strLocal\"]\r\n".
                "    , 'sqlFrom' => '{$arrTable["table"]}'\r\n".
                "    , 'defaultOrderBy'=>'".($strEditByField ? $strEditByField : $arrTable["PK"][0])."'\r\n".
                "    , 'defaultSortOrder'=>'".($strEditByField ? "DESC" : "ASC")."'\r\n".
                "    , 'intra' => \$intra));\r\n\r\n";
        
        $strCode .= "\$lst->Columns[] = array('title' => \"\"\r\n".
                "        , 'field' => '".implode("_", $arrTable['PK'])."'\r\n".
                (count($arrTable['PK']) > 1 ? 
                "        , 'sql' => \"CONCAT(".implode(", '_', ", $arrTable["PK"]).")\"\r\n" : "").        
                "        , 'PK' => true\r\n".
                "        );\r\n\r\n";
        
        $strCode .= "\$lst->Columns[] = array('title' => \"##\"\r\n".
                "        , 'field' => \"phpLNums\"\r\n".
                "        , 'type' => \"num\"\r\n".
                "        );\r\n\r\n";
                
        foreach($arrTable['columns'] as $col){
           if ($col["DataType"]=="binary")
               continue;
           if ($col["DataType"]=="activity_stamp")
               continue;
           
           $field = $col["Field"];
           $sql = $col["Field"];
           
           if ($col["DataType"]=="PK"){
              $field = $field."_";
           }
           
           $strCode .= "\$lst->Columns[] = array('title' => \$intra->translate(\"".($col["Comment"]!="" ? $col["Comment"] : $col["Field"])."\")\r\n";
           
           if ($col["DataType"]=="FK"){
               if ( $col["ref_table"]!=""){
                    $arrRefTable = $intra->getTableInfo($dbName, $col["ref_table"]);
                    $strCode .= "        , 'type' => \"combobox\"\r\n";
                    $strCode .= "        , 'source_prefix' => \"{$arrRefTable["prefix"]}\"\r\n";
                    $strCode .= "        , 'source' => \"{$col["ref_table"]}\"\r\n";
                    $strCode .= "        , 'defaultText' => getTranslation(\"Any\")\r\n";
                    $strCode .= "        , 'field' => \"{$col["Field"]}\"\r\n";
                    $strCode .= "        , 'filter' => \"{$col["Field"]}\"\r\n";
                    $strCode .= "        , 'order_field' => \"{$col["Field"]}_Text\"\r\n";
                    $strCode .= "        );\r\n";
                    continue;
               } else {
                    $strType="text";
               }
           }
           
           switch ($col["DataType"]){
               case "datetime":
                  $strType = "date";
                  break;
               case "real":
                  $strType = "money";
                  break;
               case "integer":
                  $strType = "numeric";
                  break;
               case "boolean":
                    $strType = "boolean";
                    break;
               case "FK":
                  $strType = ($col["ref_table"]!="" ? "combobox" : "text");
                  break;
               default:
                  $strType = "text";
                  break;
           }
           
           $strCode .= "        , 'type'=>\"$strType\"\r\n";
           
           $strCode .= "        , 'field' => \"".$field."\"\r\n";
           $strCode .= ($field != $sql
                     ?  "        , 'sql' => \"".$sql."\"\r\n"
                     : ""
                    );
           
           $strCode .= "        , 'filter' => \"".$sql."\"\r\n";
           $strCode .= "        , 'order_field' => \"".$field."\"\r\n";
           
           
           
           if(preg_match("/Title$/i", $field))
              $strCode .= "        , 'width' => \"100%\"\r\n";
           $strCode .= "        );\r\n";
           
        }
        
        $strCode .= $strCodeEditBy;
        
        $strCode .= "\r\n";
        
        $strCode .= "\$lst->handleDataRequest();\r\n\r\n";
        
        $strCode .= "if (\$intra->arrUsrData['FlagWrite']){\r\n";
        $strCode .= "    \$arrActions[]= Array ('title' => \$intra->translate(\"New\")\r\n";
        $strCode .= "       , 'action' => \"".(str_replace("tbl_", "", $tblName))."_form.php\"\r\n";
        $strCode .= "       , 'class' => \"ss_add\"\r\n";
        $strCode .= "    );\r\n";
        $strCode .= "}\r\n\r\n";
        
        $strCode .= "include eiseIntraAbsolutePath.'inc-frame_top.php';\r\n\r\n";
        $strCode .= "\$lst->show();\r\n\r\n";
        $strCode .= "include eiseIntraAbsolutePath.'inc-frame_bottom.php';\r\n";
        $strCode .= "?>";
        
        
        break;
    case "Form":
        
        $strCode .= "<?php\r\n";
        $strCode .= "include 'common/auth.php';\n\n";
        
        $itemName = ucfirst($arrTable['name']);
        $idField = "\${$arrTable['prefix']}ID";
        $objName = "\${$arrTable['prefix']}";

        $strFields = trim(fieldsByArray('UPDATE no_activity_stamp PHP', $arrTable, '$data', "                "));
        $insertCode = ltrim(getInsertCode('INSERT PHP', $arrTable, array('indent'=>"                ", 'dataArray'=>'$data')));
        $updateCode = ltrim(getUpdateCode('UPDATE PHP', $arrTable, array('indent'=>"                ", 'dataArray'=>'$data')));
        $updateCode = str_replace($idField, '$this->id', $updateCode);
        switch ($arrTable['PKtype']){
            case "auto_increment":
                $idCode = "\$oSQL->i()";
                break;
            case "GUID":
            default:
                $idCode = "'' /* your id retrieval code here */;";
                break;
        }

        $fields = '';
        $fieldSources = '';
        foreach($arrTable['columns'] as $ix=>$col){
            if ($col["DataType"]=="PK")
                continue;
            if ($col["DataType"]=="binary")
                continue;
            if ($col["DataType"]=="activity_stamp")
                continue;
            
            $title = ($col['Comment'] ? $col['Comment'] : $col['Field']);
            $fieldValue = "{$objName}->item[\"".$col["Field"]."\"]";

            switch($col['DataType']){
                case 'FK':
                    if ( $col["ref_table"]!=""){
                        $arrRefTable = $intra->getTableInfo($dbName, $col["ref_table"]);

                        $fields .= "echo \$intra->field('{$title}', '{$col['Field']}', {$fieldValue}, array('type'=>'select', 'source'=>'{$col['ref_table']}'"
                            .($arrRefTable['prefix'] ? ", 'source_prefix'=>'{$arrRefTable['prefix']}'" : '')
                            ."));\r\n\r\n";
                    } else {
                        $fieldSources .= "\$src_{$col['Field']} = array ('option1'=>'text1', 'option2'=>'text2');\n";
                        $fields .= ($fields ? CODE_INDENT.'.': '')."\$intra->field(\$intra->translate('{$title}'), '{$col['Field']}', {$fieldValue}, array('type'=>'select', 'source'=>\$src_{$col['Field']}))\n";
                    }
                    break;
                default:
                    $type = ($col['DataType']=='text' ? '' : ", array('type'=>'{$col['DataType']}')");
                    $fields .= ($fields ? CODE_INDENT.'.': '')."\$intra->field(\$intra->translate('{$title}'), '{$col['Field']}', {$fieldValue}{$type})\n";
                    break;
            }
           
        }
        $fieldSources .= ($fieldSources ? "\n" : '');
        $fields = rtrim($fields);

        $strCode .= "class c{$itemName} extends eiseItem {

function __construct({$idField} = null, \$conf=array('name'=>'{$arrTable['name']}')){   

    parent::__construct({$idField}, \$conf);   

}

function getData(\$pk = null){

    \$intra = \$this->intra;\$oSQL = \$this->oSQL;

    parent::getData(\$pk);

    if(!\$pk) return;

    // put your extra code here

    return \$this->item;

}

public function update(\$nd){

    \$nd_sql = \$this->intra->arrPHP2SQL(\$nd, \$this->table['columns_types']);

    \$sqlFields = \$this->intra->getSQLFields(\$this->table, \$nd_sql);

    \$this->oSQL->q('START TRANSACTION');

    if(!\$this->id){
        ".(in_array($arrTable['PKtype'], array('user_defined', 'GUID')) 
          ? "\$this->id = ".($arrTable['PKtype']=='GUID' 
            ? "\$this->oSQL->q('SELECT UUID()');" 
            : "\$nd[\$this->table['PK'][0].'_'];") 
          : '')."
        \$sql = \"INSERT INTO stbl_user SET ".(in_array($arrTable['PKtype'], array('user_defined', 'GUID')) 
          ? "{\$this->table['PK'][0]}=\".\$this->oSQL->e(\$this->id).\", " 
          : '')
        ."{\$this->conf['prefix']}InsertBy='{\$this->intra->usrID}', {\$this->conf['prefix']}InsertDate=NOW() 
            , {\$this->conf['prefix']}EditBy='{\$this->intra->usrID}', {\$this->conf['prefix']}EditDate=NOW()
            {\$sqlFields}\";
        \$this->oSQL->q(\$sql);
        ".(!in_array($arrTable['PKtype'], array('user_defined', 'GUID')) 
            ? "\$this->id = \$this->oSQL->i();"
            : '')."
    } else {
        \$sql = \"UPDATE stbl_user SET {\$this->conf['prefix']}EditBy='{\$this->intra->usrID}', {\$this->conf['prefix']}EditDate=NOW() {\$sqlFields} WHERE \".\$this->getSQLWhere();
        \$this->oSQL->q(\$sql);
    }

    \$this->oSQL->q('COMMIT');

    parent::update(\$nd);

}

}

{$objName} = new c{$itemName}(\$_POST['{$arrTable['PK'][0]}'] ? \$_POST['{$arrTable['PK'][0]}'] : \$_GET['{$arrTable['PK'][0]}']);

\$intra->dataRead(array(), {$objName});

\$intra->dataAction(array('update', 'delete'), {$objName}, \$_POST);

\$arrActions[]= Array ('title' => $intra->translate('Back to list')
       , 'action' => {$objName}->conf['list']
       , 'class'=> 'ss_arrow_left'
    );

include eiseIntraAbsolutePath.'inc-frame_top.php';

{$fieldSources}\$fields = {$fields};

\$fields = \$intra->fieldset(\$intra->arrUsrData['pagTitle'.\$intra->local].' '.\$ra->item['radTitle'], \$fields.
            \$intra->field(' ', null, {$objName}->getButtons() )
            );

echo {$objName}->form(\$fields);

include eiseIntraAbsolutePath.'inc-frame_bottom.php';
";


        echo "<pre>";
        echo htmlspecialchars($strCode);
        #print_r($arrTable); 
        die();


            
        


        $strCode .= "\r\nif(\$intra->arrUsrData['FlagWrite']){\r\n";
        $strCode .= "\r\nswitch(\$DataAction){
    case 'update':
        
        \$oSQL->q('START TRANSACTION'); 
        
        \$strFields = \"{$strFields}\";

        if (\$".$arrTable["PK"][0]."==\"\") {
            \$sqlIns = \"\n".str_replace($strFields, '{$strFields}', $strInsert)."\";
            \$rs = \$oSQL->q(\$sqlIns);
            {$strObtainID}
        } else {
            \$sqlUpd = \"".str_replace($strFields, '{$strFields}', $strUpdate)."\";
            \$oSQL->q(\$sqlUpd);
        }
        
        \$oSQL->q('COMMIT');
        
        \$intra->redirect(\$intra->translate(\"Data is updated\"), \$_SERVER[\"PHP_SELF\"].\"?{$pkURI}\");
        
    case 'delete':
    
        \$oSQL->q('START TRANSACTION');
        \$sqlDel = \"DELETE FROM `{$tblName}` WHERE ".$arrTable["PKCond"]."\";
        \$oSQL->q(\$sqlDel);
        \$oSQL->q('COMMIT');

        \$intra->redirect(\$intra->translate(\"Data is updated\"), preg_replace('/form\.php$/', 'list.php', \$_SERVER[\"PHP_SELF\"]));
        die();
        
    default:
        break;
}
}

\$sql".strtoupper($arrTable['prefix'])." = \"SELECT * FROM `{$tblName}` WHERE ".$arrTable["PKCond"]."\";
\$rs".strtoupper($arrTable['prefix'])." = \$oSQL->do_query(\$sql".strtoupper($arrTable['prefix']).");
\$rw".strtoupper($arrTable['prefix'])." = \$oSQL->fetch_array(\$rs".strtoupper($arrTable['prefix']).");

\$arrActions[]= Array ('title' => \$intra->translate('Back to list')
       , 'action' => \"".(str_replace("tbl_", "", $tblName))."_list.php\"
       , 'class'=> 'ss_arrow_left'
    );
\$arrJS[] = jQueryUIPath.'/jquery-ui.min.js';
\$arrCSS[] = jQueryUIPath.'/jquery-ui.min.css';
include eiseIntraAbsolutePath.'inc-frame_top.php';
?>

<form action=\"<?php  echo \$_SERVER[\"PHP_SELF\"] ; ?>\" method=\"POST\" class=\"eiseIntraForm\">\r\n";
foreach($arrTable["PK"] as $i=>$pk){
    $strCode .= "<?php\r\n";
    $strCode .= "echo \$intra->field(null, '{$pk}', \${$pk}, array('type'=>'hidden'));\r\n";
    $strCode .= "echo \$intra->field(null, \$this->conf['dataActionKey'], 'update', array('type'=>'hidden'));\r\n";
    $strCode .= "?>\r\n";
}
$strCode .= "
<fieldset class=\"eiseIntraMainForm\"><legend><?php echo \$intra->arrUsrData[\"pagTitle{\$intra->local}\"]; ?></legend>\r\n\r\n";
$strCode .= "<?php\r\n\r\n";
        $i=0;
        foreach($arrTable['columns'] as $ix=>$col){
            if ($col["DataType"]=="PK")
                continue;
            if ($col["DataType"]=="binary")
                continue;
            if ($col["DataType"]=="activity_stamp")
                continue;
            
            $title = ($col['Comment'] ? $col['Comment'] : $col['Field']);
            $fieldValue = "\$rw".strtoupper($arrTable['prefix'])."[\"".$col["Field"]."\"]";

            switch($col['DataType']){
                case 'FK':
                    if ( $col["ref_table"]!=""){
                        $arrRefTable = $intra->getTableInfo($dbName, $col["ref_table"]);

                        $strCode .= "echo \$intra->field('{$title}', '{$col['Field']}', {$fieldValue}, array('type'=>'select', 'source'=>'{$col['ref_table']}'"
                            .($arrRefTable['prefix'] ? ", 'source_prefix'=>'{$arrRefTable['prefix']}'" : '')
                            ."));\r\n\r\n";
                    } else {
                        $strCode .= "\$source = array ('option1'=>'text1', 'option2'=>'text2');\r\n";
                        $strCode .= "echo \$intra->field('{$title}', '{$col['Field']}', {$fieldValue}, array('type'=>'select', 'source'=>\$source));\r\n\r\n";
                    }
                    break;
                default:
                    $type = ($col['DataType']=='text' ? '' : ", array('type'=>'{$col['DataType']}')");
                    $strCode .= "echo \$intra->field('{$title}', '{$col['Field']}', {$fieldValue}{$type});\r\n\r\n";
                    break;
            }
           
        }
$strCode .= "?>\r\n\r\n";
$strCode .= "<div class=\"eiseIntraField\">\r\n
<?php 
if (\$intra->arrUsrData[\"FlagWrite\"]) {
 ?>
<label>&nbsp;</label><div class=\"eiseIntraValue\"><input class=\"eiseIntraSubmit\" type=\"Submit\" value=\"Update\">
<?php 
if (\$".$arrTable['PK'][0]."!=\"\"){
?>
<input type=\"button\" value=\"Delete\" class=\"eiseIntraDelete\">
<?php  
  }
}
?></div>

</div>\r\n";

$strCode .= "
</fieldset>
</form>
<script>
$(document).ready(function(){
    $('.eiseIntraForm').eiseIntraForm();
});
</script>
<?php
include eiseIntraAbsolutePath.'inc-frame_bottom.php';
?>";
        
        break;
    case "MissingFields":
        $entID = $_GET["entID"];
        $rwEnt = $oSQL->fetch_array($oSQL->do_query("SELECT * FROM stbl_entity WHERE entID='$entID'"));
        
        $arrCols = array();
        $arrTable = array();
        try{
            $arrTable = $intra->getTableInfo($dbName, $rwEnt["entTable"]);
        }catch(Exception $e){
            $strCode = "DROP TABLE IF EXISTS `{$rwEnt["entTable"]}`;
CREATE TABLE `{$rwEnt["entTable"]}` (
    `{$entID}ID` VARCHAR(50) NOT NULL,
    `{$entID}StatusID` INT UNSIGNED NULL DEFAULT NULL,
    `{$entID}ActionID` VARCHAR(50) NULL DEFAULT NULL,
    `{$entID}ActionLogID` VARCHAR(50) NULL DEFAULT NULL,
    `{$entID}StatusActionLogID` VARCHAR(36) NULL DEFAULT NULL,
    `{$entID}InsertBy` VARCHAR(255) NULL DEFAULT NULL,
    `{$entID}InsertDate` DATETIME NULL DEFAULT NULL,
    `{$entID}EditBy` VARCHAR(255) NULL DEFAULT NULL,
    `{$entID}EditDate` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`{$entID}ID`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;";
            $strCode .= "\r\n\r\nDROP TABLE IF EXISTS `{$rwEnt["entTable"]}_log`;
CREATE TABLE `{$rwEnt["entTable"]}_log` (
    `l{$entID}GUID` VARCHAR(36) NOT NULL,
    `l{$entID}InsertBy` VARCHAR(50) NULL DEFAULT NULL,
    `l{$entID}InsertDate` DATETIME NULL DEFAULT NULL,
    `l{$entID}EditBy` VARCHAR(50) NULL DEFAULT NULL,
    `l{$entID}EditDate` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`l{$entID}GUID`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;

DROP TABLE IF EXISTS `{$rwEnt["entTable"]}_number`;
CREATE TABLE `{$rwEnt["entTable"]}_number` (
  `n{$entID}ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `n{$entID}InsertDate` datetime DEFAULT NULL,
  PRIMARY KEY (`n{$entID}ID`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;

";


        }
        
        //for($i=0; $i<count($arrTable["columns"]); $i++)
        if (is_array($arrTable['columns']))
          foreach($arrTable["columns"] as $i=>$col)
              $arrCols[] = $col["Field"];
            //echo "<pre>";
            //print_r($arrTable);
            //print_r($arrCols);
            //echo "</pre>";
            
        $sqlMsf = "SELECT * FROM stbl_attribute WHERE atrEntityID='$entID' 
        ORDER BY atrOrder";
        //echo $sqlMsf;
        $strCodeMaster = "";
        $strCodeLog = "";
        $rsMsf = $oSQL->do_query($sqlMsf);
        while($rwMsf = $oSQL->fetch_array($rsMsf)){
            if (!in_array($rwMsf["atrID"], $arrCols)){
                $colName = $rwMsf["atrID"];
                $strCodeMaster .= ($strCodeMaster != "" ? "\r\n, " : "")."\tADD COLUMN $colName ";
                $strCodeLog .= ($strCodeLog != "" ? "\r\n, " : "")."\tADD COLUMN l{$colName} ";
                switch($rwMsf["atrType"]){
                    case "date":
                        $strType = "DATE NULL DEFAULT NULL";
                        break;
                    case "datetime":
                        $strType = "DATETIME NULL DEFAULT NULL";
                        break;
                    case "numeric":
                        $strType = "DOUBLE NULL DEFAULT NULL";
                        break;
                    case "integer":
                        $strType = "INT NULL DEFAULT NULL";
                        break;
                    case "money":
                        $strType = "DECIMAL(10,2)";
                        break;
                    case "combobox":
                    case "ajax_dropdown":
                        $strType = "VARCHAR(50)";
                        break;
                    default:
                       $strType = "VARCHAR(1024)";
                       break;
                }
                $strCodeMaster .= "{$strType} NULL DEFAULT NULL";
                $strCodeLog .= "{$strType} NULL DEFAULT NULL";
            }
            
            $lastColName = $colName;
            
            $strUOMCol = $rwMsf["atrID"]."_uomID";
            if (!empty($rwMsf["atrUOMTypeID"]) && !in_array($strUOMCol, $arrCols)){
                 $strCodeMaster .= "\r\n, ADD COLUMN {$strUOMCol} VARCHAR(10) NULL DEFAULT NULL AFTER {$rwMsf["atrID"]}";
                 $strCodeLog .= "\r\n, ADD COLUMN l{$strUOMCol} VARCHAR(10) NULL DEFAULT NULL AFTER l{$rwMsf["atrID"]}";
                 $lastColName = $strUOMCol;
            }
        }
        
        if ($strCodeMaster!=""){
            
            $strCodeMaster .= "\r\n, CHANGE ".$entID."InsertBy ".$entID."InsertBy VARCHAR(255) NULL DEFAULT NULL  AFTER {$lastColName}";
            $strCodeMaster .= "\r\n, CHANGE ".$entID."InsertDate ".$entID."InsertDate DATETIME NULL DEFAULT NULL AFTER ".$entID."InsertBy";
            $strCodeMaster .= "\r\n, CHANGE ".$entID."EditBy ".$entID."EditBy VARCHAR(255) NULL DEFAULT NULL AFTER ".$entID."InsertDate";
            $strCodeMaster .= "\r\n, CHANGE ".$entID."EditDate ".$entID."EditDate DATETIME NULL DEFAULT NULL AFTER ".$entID."EditBy";
            
            $strCodeLog .= "\r\n, CHANGE l".$entID."InsertBy l".$entID."InsertBy VARCHAR(255) NULL DEFAULT NULL  AFTER l{$lastColName}";
            $strCodeLog .= "\r\n, CHANGE l".$entID."InsertDate l".$entID."InsertDate DATETIME NULL DEFAULT NULL AFTER l".$entID."InsertBy";
            $strCodeLog .= "\r\n, CHANGE l".$entID."EditBy l".$entID."EditBy VARCHAR(255) NULL DEFAULT NULL AFTER l".$entID."InsertDate ";
            $strCodeLog .= "\r\n, CHANGE l".$entID."EditDate l".$entID."EditDate DATETIME NULL DEFAULT NULL AFTER l".$entID."EditBy ";
            
            $strCode .= "\r\n\r\nALTER TABLE ".$rwEnt["entTable"]."\r\n".$strCodeMaster.";";
            $strCode .= "\r\n\r\nALTER TABLE ".$rwEnt["entTable"]."_log\r\n".$strCodeLog.";";
        } else {
            $strCode .= "--no fields added";
        }
        
        break;
    case "EntTables":

        $entID = $_GET["entID"];
        $rwEnt = $oSQL->fetch_array($oSQL->do_query("SELECT * FROM stbl_entity WHERE entID='$entID'"));
        $entPrefix = $rwEnt['entPrefix'] ? $rwEnt['entPrefix'] : $entID;
        $strTBL = $rwEnt["entTable"];
        $strLTBL = $rwEnt["entTable"]."_log";

        $arrReservedColumnNames = array("{$entPrefix}ID"
            , "{$entPrefix}StatusID"
            , "{$entPrefix}ActionLogID"
            , "{$entPrefix}StatusActionLogID"
            , "{$entPrefix}StatusLogID"
            , "{$entPrefix}FlagDeleted"
            , "{$entPrefix}InsertBy"
            , "{$entPrefix}InsertDate"
            , "{$entPrefix}EditBy"
            , "{$entPrefix}EditDate");

        $arrMasterTable = array();
        try { $arrMasterTable = $intra->getTableInfo($dbName, $strTBL); } catch (Exception $e) {$arrMasterTable['columns'] = array();}

        //determine last column name from master
        $ak = array_keys($arrMasterTable['columns']);
        $ii = 0;
        foreach($arrMasterTable['columns'] as $col=>$x){
            $lastMasterColName = $col;
            if( in_array($ak[$ii+1], array("{$entPrefix}FlagDeleted", "{$entPrefix}InsertDate")) ){
                break;
            } 
            $ii++;
        }

        //collect attributes
        $strFieldsMaster = "";
        $strLogFields = "";
        $atrs = [];
        $sqlATR = "SELECT * FROM stbl_attribute WHERE atrEntityID='{$entID}' ORDER BY atrOrder";
        $rsATR = $oSQL->do_query($sqlATR);
        while ($rwATR = $oSQL->fetch_array($rsATR)) {

            $strKeyMaster = $strKeyLog = '';
            switch ($rwATR["atrType"]){
                case "boolean":
                    $strType = "TINYINT(4) NOT NULL DEFAULT 0";
                    break;
                case "integer":
                    $strType = "INT NULL DEFAULT NULL";
                    break;
                case "numeric":
                case "real":
                    $strType = "DECIMAL(12,2) NULL DEFAULT NULL";    
                    break;
                case "date":
                case "datetime":
                    $strType = $rwATR["atrType"].' NULL DEFAULT NULL';
                    break;
                case "textarea":
                    $strType = "LONGTEXT NULL";
                    break;
                case "combobox":
                case "ajax_dropdown":
                    $strKeyMaster = "`IX_".$rwATR["atrID"]."` (`".$rwATR["atrID"]."`)";
                    $strKeyLog = "`IX_l".$rwATR["atrID"]."` (`l".$rwATR["atrID"]."`)";
                    $strType = "VARCHAR(36) NULL DEFAULT NULL";
                    break;
                case "varchar":
                case "text":
                default:                    
                    $strType = "VARCHAR(1024) NOT NULL DEFAULT ''";
                    break;
            }

            if(!in_array($rwATR['atrID'], $arrReservedColumnNames))
                if(!@array_key_exists($rwATR['atrID'], $arrMasterTable['columns'])){
                    if(count($arrMasterTable['columns'])>0){
                        $strFieldsMaster .= "\r\n\t, ADD COLUMN `{$rwATR["atrID"]}` {$strType} COMMENT ".$oSQL->escape_string($rwATR["atrTitle"])." AFTER `{$lastMasterColName}`" ;
                        $strKeysMaster .= ($strKeyMaster!='' ? "\r\n\t, ADD INDEX {$strKeyMaster}" : '');
                        $lastMasterColName = $rwATR['atrID'];
                    } else {
                        $strFieldsMaster .= "\r\n\t, `{$rwATR["atrID"]}` {$strType} COMMENT ".$oSQL->escape_string($rwATR["atrTitle"]);
                        $strKeysMaster .= ($strKeyMaster!='' ? "\r\n\t, KEY {$strKeyMaster}" : '');
                    }
                } 
            $atrs[] = $rwATR["atrID"];

        }

        $strDropMaster = '';
        foreach ($arrMasterTable['columns'] as $ix => $col) {
            if(!in_array($col['Field'], $atrs) && !in_array($col['Field'], $arrReservedColumnNames)){
                $strDropMaster .= "\n\t, DROP COLUMN {$col['Field']}";
            }
        }

        if(count($arrMasterTable['columns'])==0){
            $strCode = "DROP TABLE IF EXISTS `{$strTBL}`;\r\n";
            //create master table
           $strCode .= "\r\nCREATE TABLE `{$strTBL}` (".
                "\r\n\t`{$entPrefix}ID` VARCHAR(36) NOT NULL".
                "\r\n\t,`{$entPrefix}StatusID` INT(11) NOT NULL DEFAULT 0".
                "\r\n\t,`{$entPrefix}ActionID` INT(11) NOT NULL DEFAULT 1".
                "\r\n\t,`{$entPrefix}ActionLogID` VARCHAR(36) NULL DEFAULT NULL".
                "\r\n\t,`{$entPrefix}StatusActionLogID` VARCHAR(36) NULL DEFAULT NULL".
                "\r\n\t,`{$entPrefix}StatusLogID` VARCHAR(36) NULL DEFAULT NULL".
                "{$strFieldsMaster}".
                "\r\n\t, `{$entPrefix}FlagDeleted` tinyint(4) DEFAULT 0".
                "\r\n\t, `{$entPrefix}InsertBy` varchar(50) DEFAULT NULL".
                "\r\n\t, `{$entPrefix}InsertDate` datetime DEFAULT NULL".
                "\r\n\t, `{$entPrefix}EditBy` varchar(50) DEFAULT NULL".
                "\r\n\t, `{$entPrefix}EditDate` datetime DEFAULT NULL".
                "\r\n\t, PRIMARY KEY (`{$entPrefix}ID`)".
                "\r\n\t, INDEX `IX_{$entPrefix}StatusID` (`{$entPrefix}StatusID`)".
                "\r\n\t, INDEX `IX_{$entPrefix}ActionLogID` (`{$entPrefix}ActionLogID`)".
                "\r\n\t, INDEX `IX_{$entPrefix}StatusLogID` (`{$entPrefix}StatusLogID`)".
                "\r\n\t, INDEX `IX_{$entPrefix}EditDate` (`{$entPrefix}EditDate`)".
                "\r\n\t".($strKeysMaster!="" ? $strKeysMaster : "").
                "\r\n) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;\r\n";
        } else {
            $strCode = "ALTER TABLE {$strTBL}";
            $strAlterBody = '';
            $strIndexes = '';
            $strLastColumn = $arrMasterTable['PK'][count($arrMasterTable['PK'])-1];
            if(!array_key_exists("{$entPrefix}StatusID", $arrMasterTable['columns'])){
                $strAlterBody .= "\r\n\t".($strAlterBody!='' ? ', ' : '')."ADD COLUMN `{$entPrefix}StatusID` INT(11) NOT NULL DEFAULT '0' AFTER {$strLastColumn}";
                $strIndexes .= "\r\n\t, ADD INDEX `IX_{$entPrefix}StatusID` (`{$entPrefix}StatusID`)";
                $strLastColumn = "{$entPrefix}StatusID";
            }
            if(!array_key_exists("{$entPrefix}ActionLogID", $arrMasterTable['columns'])){
                $strAlterBody .= "\r\n\t".($strAlterBody!='' ? ', ' : '')."ADD COLUMN `{$entPrefix}ActionLogID` VARCHAR(36) NULL DEFAULT NULL AFTER {$strLastColumn}";
                $strIndexes .= "\r\n\t, ADD INDEX `IX_{$entPrefix}ActionLogID` (`{$entPrefix}ActionLogID`)";
                $strLastColumn = "{$entPrefix}ActionLogID";
            }
            if(!array_key_exists("{$entPrefix}StatusActionLogID", $arrMasterTable['columns'])){
                $strAlterBody .= "\r\n\t".($strAlterBody!='' ? ', ' : '')."ADD COLUMN `{$entPrefix}StatusActionLogID` VARCHAR(36) NULL DEFAULT NULL AFTER {$strLastColumn}";
                $strIndexes .= "\r\n\t, ADD INDEX `IX_{$entPrefix}StatusActionLogID` (`{$entPrefix}StatusActionLogID`)";
                $strLastColumn = "{$entPrefix}StatusActionLogID";
            }
            if(!array_key_exists("{$entPrefix}StatusLogID", $arrMasterTable['columns'])){
                $strAlterBody .= "\r\n\t".($strAlterBody!='' ? ', ' : '')."ADD COLUMN `{$entPrefix}StatusLogID` VARCHAR(36) NULL DEFAULT NULL AFTER {$strLastColumn}";
                $strIndexes .= "\r\n\t, ADD INDEX `IX_{$entPrefix}StatusLogID` (`{$entPrefix}StatusLogID`)";
                $strLastColumn = "{$entPrefix}StatusLogID";
            }
            $strCode .= $strAlterBody;
            $strCode .= ($strAlterBody=='' ? "\r\n\t".preg_replace('/^(\s*,\s*)/', '' , $strFieldsMaster) : $strFieldsMaster);
            $strCode .= ($strAlterBody=='' ? "\r\n\t".preg_replace('/^(\s*,\s*)/', '' , $strDropMaster) : $strDropMaster);
            $strCode .= $strIndexes.$strKeysMaster;
            $strCode .= ";\r\n\r\n";
        }
        
        
        $strCode = str_replace("\t", CODE_INDENT, $strCode);

            
        break;
    case "ATV2MT":
        
        $entID = $_GET["entID"];
        $rwEnt = $oSQL->fetch_array($oSQL->do_query("SELECT * FROM stbl_entity WHERE entID='$entID'"));
        
        
        
        //collect attributes
        $strFields = "";
        $arrATR = Array();
        $arrFields = Array();
        $arrCheckMask = Array();
        $sqlATR = "SELECT * FROM stbl_attribute INNER JOIN
            (
            SELECT atvEntityID, atvAttributeID 
            FROM stbl_attribute_value 
            GROUP BY atvAttributeID, atvEntityID
            ) as t1 ON atrID=atvAttributeID AND atrEntityID=atvEntityID
            WHERE atrEntityID='{$entID}'";
            
        $strCode = "UPDATE IGNORE `{$rwEnt["entTable"]}` SET";
        $strFieldValue = "";
        $rsATR = $oSQL->do_query($sqlATR);
            while ($rwATR = $oSQL->fetch_array($rsATR)) {
                
                $strFieldValue .= ($strFieldValue!="" ? "\r\n, " : "");
                $strFieldValue .= " `{$rwATR["atrID"]}`=IFNULL((SELECT ";
                $strFieldValue .= ($rwATR["atrType"]=="easyGrid"
                    ? "GROUP_CONCAT((CASE WHEN atvValue='' THEN NULL ELSE atvValue END) SEPARATOR '\\r\\n')"
                    : "MAX(CASE WHEN atvValue='' THEN NULL ELSE atvValue END)");
                $strFieldValue .= " as atvValue FROM stbl_attribute_value 
                    WHERE atvEntityID='{$entID}' AND atvEntityItemID={$entID}ID AND atvAttributeID='{$rwATR["atrID"]}'
                    GROUP BY atvEntityItemID, atvEntityID, atvAttributeID), {$rwATR["atrID"]})";
            }
            
            
            $strCode .= $strFieldValue;
            
        break;
    case "EntityReport":
        $entID = $_GET["entID"];
        $rwEnt = $oSQL->fetch_array($oSQL->do_query("SELECT * FROM stbl_entity WHERE entID='$entID'"));
        
        $strLocal = "Local";
        
        $strHTML = "";
        
        $strHTML .= "<H1>Сущность &quot;{$rwEnt["entTitle$strLocal"]}&quot;</H1>\r\n\r\n";
        
        /* statuses */ 
        $strHTML .= "<h2>Состояния</h2>\r\n<p>Доступны следующие состояния:";
        $sqlSta = "SELECT * FROM stbl_status WHERE staEntityID='{$entID}' ORDER BY staID";
        $rsSta = $oSQL->do_query($sqlSta);
        $strHTML .= "<ul>\r\n";
        while ($rwSta = $oSQL->fetch_array($rsSta)){
            $strHTML .= "<li><b>{$rwSta["staID"]}: {$rwSta["staTitle$strLocal"]}</b><br><br></li>\r\n";
            $strHTML .= "<blockquote><b>Доступные для редактирования атрибуты:</b><br><pre>\r\n";
            $sqlSat = "SELECT * FROM stbl_status_attribute 
               INNER JOIN stbl_attribute ON satAttributeID=atrID AND satEntityID=atrEntityID
               WHERE satStatusID='{$rwSta["staID"]}' AND satEntityID='{$entID}' AND satFlagEditable=1
               ORDER BY atrOrder";
            $rsSat = $oSQL->do_query($sqlSat);
            while ($rwSat = $oSQL->fetch_array($rsSat)){
               $strHTML .= "{$rwSat["atrTitle$strLocal"]}\t{$rwSat['atrID']}\r\n";
            }
            $strHTML .= "</pre></blockquote>\r\n";    
            
            $strHTML .= "<blockquote><b>Доступные действия:</b><ol>\r\n";
            $sqlAct = "SELECT * FROM stbl_action_status
                INNER JOIN stbl_action ON atsActionID=actID
               WHERE atsOldStatusID='{$rwSta["staID"]}' AND actEntityID='{$entID}'
               ORDER BY actPriority";
            $rsAct = $oSQL->do_query($sqlAct);
            while ($rwAct = $oSQL->fetch_array($rsAct)){
               $strHTML .= "<li><b>{$rwAct["actTitle$strLocal"]}</b></li><br><br>Обязательные атрибуты:<br><i>\r\n";
               $sqlAAT = "SELECT *
                FROM stbl_action_attribute INNER JOIN stbl_attribute ON atrID=aatAttributeID 
                WHERE atrEntityID='{$entID}' AND aatActionID='{$rwAct["actID"]}' AND aatFlagMandatory=1 
                ORDER BY atrOrder";
                $rsAAT = $oSQL->do_query($sqlAAT);
                while ($rwAAT = $oSQL->fetch_array($rsAAT)){
                    $strHTML .= "- {$rwAAT["atrTitle$strLocal"]}".($rwAAT["aatFlagToPush"] ? ", запись" : "").
                        ($rwAAT["aatFlagTimestamp"] ? ", <tt>{$rwAAT["aatFlagTimestamp"]}</tt>" : "")."<br>\r\n";
                }
                $strHTML .= "</i><br><br>";
            }
            $strHTML .= "</ol></blockquote>\r\n";   
        }
        $strHTML .= "</ul>\r\n";
        $strHTML .= "</p>\r\n";
        
        
        break;
        
    case "table_Description":
        
        $strHTML ="<h3>Table &quot;{$arrTable["table"]}&quot;</h3>
        <style>td, th {border: 1px solid black; font-size: 10pt;vertical-align: top;} table {border-collapse: collapse;}</style>
        <table>
        <thead>
        <tr>
        <th>##</th>
        <th>Field</th>
        <th>Type/length</th>
        <th>Designation</th>
        <th>Description</th>
        </tr>
        </thead>
        <tbody>";
        
        $iCounter = 0;
        /*
        echo "<pre>";
        print_r($arrTable);
        echo "</pre>";
        */
        foreach($arrTable["columns"] as $col){
            $strHTML .= "<tr>
            <td>".($iCounter+1).".</td>
            <td>".$col["Field"]."</td>
            <td>".$col["Type"]."</td>
            <td>".$col["DataType"]."</td>
            <td>".$col["Comment"]."&nbsp;</td>
            </tr>";
            $iCounter++;
        }
        
        
        $strHTML .= "</tbody>
        </table>";
        
        break;
        
        
    case "StatusLogCheck":
        
        for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
        ob_implicit_flush(1);
        echo str_repeat(" ", 256)."<pre>"; ob_flush();
        
        $sqlENT = "SELECT * FROM {$_GET["tblName"]}";
        $rsENT = $oSQL->do_query($sqlENT);
        
        $ii = 0;
        $entID = $_GET["entID"];
        $oSQL->do_query("DELETE FROM stbl_status_log WHERE stlEntityID='{$entID}'");
        
        while ($rwENT=$oSQL->fetch_array($rsENT)){
            
            $ii++;
            
            //if ($ii<5000 || $ii>6000)
            //    continue;
            
            ob_flush();
            
            $entItemID = $rwENT[$_GET["entID"]."ID"];
            
            echo "Shipment '{$entItemID}': ";
            updateStatusLog($entItemID, "0", $rwENT[$_GET["entID"]."InsertDate"]);
            echo "\r\n";
            
        }
        
        
        echo "</pre>";
        ob_flush();
        die();
        break;    
}

if ($strHTML){
   echo $strHTML;
   die();
}

header("Content-Type: text/plain; charset=UTF-8");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");

echo $strCode;

