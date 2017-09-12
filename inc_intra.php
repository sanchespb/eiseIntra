<?php
/**
 *
 * eiseIntra core class
 *
 * Authentication, form elements display, data handling routines, archive/restore functions
 *
 *
 * @package eiseIntra
 * @version 2.0beta
 *
 */

include "inc_config.php";
include "inc_mysqli.php";
include "inc_intra_data.php";
include "inc_item.php";

class eiseIntra extends eiseIntraData {

public $conf = array('versionIntra'=>'2.0beta.040');

private $arrHTML5AllowedInputTypes = 
    Array("color"
        #, "date", "datetime", "datetime-local", "time"
        , "email", "month", "number", "range", "search", "tel", "url", "week", "password", "text", "file");

private $arrClassInputTypes = 
    Array("ajax_dropdown", "date", "datetime", "datetime-local", "time");

const cachePreventorVar = 'nc';
const dataActionKey = 'DataAction';
const dataReadKey = 'DataAction';

static $arrKeyboard = array(
        'EN' =>   'qwertyuiop[]asdfghjkl;\'\\zxcvbnm,./QWERTYUIOP{}ASDFGHJKL:"|ZXCVBNM<>?'
        , 'RU' => 'йцукенгшщзхъфывапролджэёячсмитьбю/ЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЁЯЧСМИТЬБЮ?'
    );

function __construct($oSQL = null, $conf = Array()){ //$oSQL is not mandatory anymore

    GLOBAL $eiseIntraCookiePath
        , $eiseIntraCookieExpire
        , $eiseIntraUserMessageCookieName
        , $localLanguage;

    $this->conf = array_merge($this->conf, 
        Array(                    //defaults for intra
            'dateFormat' => "d.m.Y" // 
            , 'timeFormat' => "H:i" // 
            , 'decimalPlaces' => "2"
            , 'decimalSeparator' => "."
            , 'thousandsSeparator' => ","
            , 'language' => 'rus'
            , 'logofftimeout' => 360 //6 hours
            , 'addEiseIntraValueClass' => true
            , 'keyboards' => 'EN,RU'
            , 'system' => ltrim(dirname($_SERVER['PHP_SELF']), '/')
            , 'dataActionKey' => self::dataActionKey
            , 'dataReadKey' => self::dataReadKey
     //       , 'flagSetGlobalCookieOnRedirect' = false
            , 'cookiePath' => (isset($eiseIntraCookiePath) ? $eiseIntraCookiePath : '/')
            , 'cookieExpire' => (isset($eiseIntraCookieExpire) ? $eiseIntraCookieExpire : null)
            , 'UserMessageCookieName' => ($eiseIntraUserMessageCookieName ? $eiseIntraUserMessageCookieName: 'eiMsg')
            , 'selItemMenu' => null
            , 'selItemTopLevelMenu' => null
        )
        , $conf
    );
        
    
    $arrFind = Array();
    $arrReplace = Array();
    $arrFind[] = '.'; $arrReplace[]='\\.';          
    $arrFind[] = '/'; $arrReplace[]='\\/';          
    $arrFind[] = 'd'; $arrReplace[]='([0-9]{1,2})'; 
    $arrFind[] = 'm'; $arrReplace[]='([0-9]{1,2})';
    $arrFind[] = 'Y'; $arrReplace[]='([0-9]{4})';
    $arrFind[] = 'y'; $arrReplace[]='([0-9]{1,2})';
    $this->conf['prgDate'] = str_replace($arrFind, $arrReplace, $this->conf['dateFormat']);
    $dfm  = preg_replace('/[^a-z]/i','', $this->conf['dateFormat']);
    $this->conf['prgDateReplaceTo'] = '\\'.(strpos($dfm, 'y')===false ? strpos($dfm, 'Y')+1 : strpos($dfm, 'y')+1).'-\\'.(strpos($dfm, 'm')+1).'-\\'.(strpos($dfm, 'd')+1);
    
    $arrFind = Array();
    $arrReplace = Array();            
    $arrFind[] = "."; $arrReplace[]="\\.";
    $arrFind[] = ":"; $arrReplace[]="\\:";
    $arrFind[] = "/"; $arrReplace[]="\\/";
    $arrFind[] = "H"; $arrReplace[]="([0-9]{1,2})";
    $arrFind[] = "h"; $arrReplace[]="([0-9]{1,2})";
    $arrFind[] = "i"; $arrReplace[]="([0-9]{1,2})";
    $arrFind[] = "s"; $arrReplace[]="([0-9]{1,2})";
    $this->conf["prgTime"] = str_replace($arrFind, $arrReplace, $this->conf["timeFormat"]);
    
    $this->oSQL = $oSQL;

    self::buildLess();

    $this->requireComponent('base');

}

/**********************************
   Authentication Routines
/**********************************/
/**
 * Function decodes authstring login:password
 * using current encoding algorithm 
 * (now base64).
 * 
 * @param string $authstring Encoded string
 *
 * @return array {string $login, string $password}
 */
function decodeAuthString($authstring){

    $auth_str = base64_decode($authstring);
        
    preg_match("/^([^\:]+)\:([\S ]+)$/i", $auth_str, $arrMatches);

    return Array($arrMatches[1], $arrMatches[2]);
}

/**
 * Function encodes authstring login:password
 * using current encoding algorithm 
 * (now base64).
 * 
 * @param string $login Login
 * @param string $password Password
 *
 * @return string Encoded authentication string.
 */
function encodeAuthString($login, $password){

    return base64_encode($login.':'.$password);

}

/**
 * Function that checks authentication with credentials database using selected $method.
 * Now it supports the following methods:
 * 1) LDAP - it checks credentials with specified GLOBAL $ldap_server with GLOBAL $ldap_domain
 * 2) database (or DB) - it checks credentials with database table stbl_user
 * 3) mysql - it checks credentials of MySQL database user supplied with $login and $password parameters. Together with authentication this 
 * Function returns true when authentication successfull, otherwise it returns false and $strError parameter 
 * variable becomes updated with authentication error message.
 *
 * LDAP method was successfully tested with Active Directory on Windows 2000, 2003, 2008, 2008R2 servers.
 * 
 * @param string $login - login name
 * @param string $password - password
 * @param string $method - authentication method. Can be 'LDAP', 'database'(equal to 'DB'), 'mysql'
 * @param array $options - Array
 *  ['flagNoSession'] boolean (optional) Use true when you need one-time authentication without $_SESSION modification.
 *  ['dbhost'] string (optional) Database host, used only with 'mysql' authentication method.
 *  ['dbname'] string (optional) Database name. Default database to be selected with 'mysql' authentication method.
 * 
 * @return boolean authentication result: true on success, otherwise false.
 */
function Authenticate($login, $password, $method="LDAP", $options=array()){
    
    $oSQL = $this->oSQL;
    
    switch($method) {
    case "LDAP":    
        GLOBAL $ldap_server;
        GLOBAL $ldap_domain;
        GLOBAL $ldap_dn;
        GLOBAL $ldap_conn, $ldap_anonymous_login, $ldap_anonymous_pass;
        if (preg_match("/^([a-z0-9]+)[\/\\\]([a-z0-9]+)$/i", $login, $arrMatch)){
            $login = $arrMatch[2];
            $ldap_domain = strtolower($arrMatch[1].".e-ise.com");
        } else
            if (preg_match("/^([a-z0-9\_]+)[\@]([a-z0-9\.\-]+)$/i", $login, $arrMatch)){
                $login = $arrMatch[1];
                $ldap_domain = $arrMatch[2];
            }
            
        $ldap_conn = ldap_connect($ldap_server);
        $binding = @ldap_bind($ldap_conn, $ldap_anonymous_login, $ldap_anonymous_pass);
        
        if (!$binding){
            $method = "database";
        } else {
            $ldap_login = $login."@".$ldap_domain;
            $ldap_pass = $password;
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

            if ( !($binding = ldap_bind($ldap_conn, $ldap_login, $ldap_pass)) ){
                throw new eiseException("Bad Windows user name or password {$ldap_login}");
            } else 
                break;
        }

    case "database":
    case "DB":

        if(!$oSQL->connect()){
            throw new eiseException("Unable to connect to database");
        }
        $sqlAuth = "SELECT usrID FROM stbl_user WHERE usrID='{$login}' AND usrPass='".md5($password)."'";
        $rsAuth = $oSQL->do_query($sqlAuth);
        if ($oSQL->num_rows($rsAuth)!=1)
            throw new eiseException("Bad database user name or password");

        break;
    case "mysql":
        try {
            $this->oSQL = new eiseSQL (
                (!$options['dbhost'] ? 'localhost' : $options['dbhost'])
                , $login
                , $password
                , (!$options["dbname"] ? 'mysql' : $options["dbname"])
                );
        } catch(Exception $e){
            throw new eiseException($e->getMessage());
        }
        break;
    } 

    if($method=="mysql"){
        $_SESSION["usrID"] = $login;
        $_SESSION["DBHOST"] = $this->oSQL->dbhost;
        $_SESSION["DBPASS"] = $this->oSQL->dbpass;
    }

    if($options['flagNoSession'])
        return true;

    $this->session_initialize();
    session_regenerate_id();

    $_SESSION["last_login_time"] = Date("Y-m-d H:i:s");
    $_SESSION["usrID"] = $login;
    $_SESSION["authstring"] = $this->encodeAuthString($login, $password);

    $this->usrID = $_SESSION["usrID"];

    SetCookie("last_succesfull_usrID", $login, $this->conf['cookieExpire'], $this->conf['cookiePath']);

    return true;

}

/**
 * This function intialize session with session cookes placed at path set by $this->conf['cookiePath'] configuration variable.
 */
function session_initialize(){
   session_set_cookie_params(0, $this->conf['cookiePath']);
   session_start();
   $this->usrID = $_SESSION["usrID"];
} 
 
/**
 * This function quits user session.
 */
function logout(){

    session_set_cookie_params(0, $this->conf['cookiePath']);

    session_start();
    session_unset();

    $_SESSION = array();

    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );

    session_destroy();

    SetCookie("last_succesfull_usrID", $this->usrID, $this->conf['cookieExpire'], $this->conf['cookiePath']);

}

/**
 * This function checks current user's permissions on currently open script.
 * Also it checks session expiration time, and condition when user is blocked or not in the database.
 * Script name is obtained from $_SERVER['SCRIPT_NAME'] global variable.
 * Permission information is collected from stbl_page_role table and calculated according to user role membership defined at stbl_role_user table.
 * Permissions are calulated in the following way:
 * - if at least one user's role is permitted to do something, it means that this user is permitted to to it.
 * 
 * If user has no permissions to 'Read' the script, function throws header Location: login.php and stops the script.
 * When 'Read' permissions are confirmed for the user, function updates $intra->arrUsrData property with the following data:
 * - all user data from stbl_user table
 * - string pagID - database page ID
 * - string pagTitle - page title in English
 * - string pagTitleLocal - page title in local language
 * - string* FlagRead - always '1'
 * - string* FlagCreate - '0' or '1', as set in database
 * - string* FlagUpdate - '0' or '1', as set in database
 * - string* FlagDelete - '0' or '1', as set in database
 * - string* FlagWrite - '0' or '1', as set in database
 * - array roles - array of role titles in currently selected language
 * - array roleIDs - array of role IDs
 * ---------------
 * (*) - type is 'string' because of PHP function mysql_fetch_assoc()'s nature. It fetches anything like strings despite actual data type in the database.
 *
 * NOTE: Role membership information is collected from stbl_role_user table basing on rluInsertDate timestamp, 
 * it should not be in the future. It is useful when some actions should be temporarily delegated to the other user in case of vacations, illness etc.
 *
 * Page permissions can be set with eiseAdmin's GUI at <database>/Pages menu.
 * Role membership can be set by system's GUI at system's Setting/Access Control menu or <database>/Roles menu of eiseAdmin.
 *
 * @return array $intra->arrUsrData
 */
function checkPermissions(){
   
   $oSQL = $this->oSQL;
   
   GLOBAL $strSubTitle;
   
   // checking user timeout
   if ($_SESSION["last_login_time"]!="" && $strSubTitle != "DEVELOPMENT" ){
      if (time() - strtotime($_SESSION["last_login_time"])>60*$this->conf['logofftimeout']) {
          $tt = Date("Y-m-d H:i:s", mktime())." - ".$_SESSION["last_login_time"];
          header("HTTP/1.0 403 Access denied");
          header ("Location: login.php?error=".urlencode($this->translate("Session timeout ($tt). Please re-login.")));
          die();
      }
   }
   
   //checking is user blocked or not?
   $rsUser = $oSQL->do_query("SELECT * FROM stbl_user WHERE usrID='".$_SESSION["usrID"]."'");
   $rwUser = $oSQL->fetch_array($rsUser);
   
   if (!$rwUser["usrID"]){
        header ("Location: login.php?error=".urlencode($this->translate("Your User ID doesnt exist in master database. Contact system administrator.")));
        die();
   }
   
   if ($rwUser["usrFlagDeleted"]){
        header ("Location: login.php?error=".urlencode($this->translate("Your User ID is blocked.")));
        die();
   }
   
   // checking script permissions
   $script_name = preg_replace("/^(\/[^\/]+)/", "", $_SERVER["SCRIPT_NAME"]);
   $sqlCheckUser = "SELECT
             pagID
            , pagTitle
            , pagTitleLocal
            , MAX(pgrFlagRead) as FlagRead
            , MAX(pgrFlagCreate) as FlagCreate
            , MAX(pgrFlagUpdate) as FlagUpdate
            , MAX(pgrFlagDelete) as FlagDelete
            , MAX(pgrFlagWrite) as FlagWrite
           FROM 
        (SELECT 
             pagID
            , pagTitle
            , pagTitleLocal
            , pgrFlagRead, pgrFlagCreate, pgrFlagUpdate, pgrFlagDelete, pgrFlagWrite
            , rolID
        FROM stbl_page PAG
           INNER JOIN stbl_page_role PGR ON PAG.pagID=PGR.pgrPageID
           INNER JOIN stbl_role ROL ON PGR.pgrRoleID=ROL.rolID
           INNER JOIN stbl_role_user RLU ON ROL.rolID=RLU.rluRoleID
           WHERE PAG.pagFile='$script_name' AND (RLU.rluUserID=".$oSQL->e($_SESSION["usrID"])." AND DATEDIFF(NOW(), rluInsertDate)>=0)
        UNION 
        SELECT 
             pagID
            , pagTitle
            , pagTitleLocal
            , pgrFlagRead, pgrFlagCreate, pgrFlagUpdate, pgrFlagDelete, pgrFlagWrite
            , rolID
        FROM stbl_page PAG
           INNER JOIN stbl_page_role PGR ON PAG.pagID=PGR.pgrPageID
           INNER JOIN stbl_role ROL ON PGR.pgrRoleID=ROL.rolID
           WHERE pagFile='$script_name' AND rolFlagDefault=1
           )
        AS t1
        GROUP BY pagID, pagTitle";
       //echo $sqlCheckUser;
    $rsChkPerms = $oSQL->do_query($sqlCheckUser);
    $rwPerms = $oSQL->fetch_array($rsChkPerms);
        
    if (!$rwPerms["FlagRead"]){
        $errortext = "".$_SERVER["PHP_SELF"].": ".$this->translate("access denied");
        $this->redirect("ERROR: ".$errortext
            , (($_SERVER["HTTP_REFERER"]!="" && !strstr($_SERVER["HTTP_REFERER"], "login.php")) ? $_SERVER["HTTP_REFERER"] : "login.php?error=".urlencode($errortext)));
        die();
    } 
    
    
    $sqlRoles = "SELECT rolID, rolTitle$this->local
       FROM stbl_role ROL
       LEFT OUTER JOIN stbl_role_user RLU ON RLU.rluRoleID=ROL.rolID
       WHERE (RLU.rluUserID = '{$_SESSION["usrID"]}' AND DATEDIFF(NOW(), rluInsertDate)>=0)
          OR rolID='Everyone'";
    $rsRoles = $oSQL->do_query($sqlRoles);
    $arrRoles = Array();
    $arrRoleIDs = Array();
    while ($rwRol = $oSQL->fetch_array($rsRoles)){
        $arrRoles[] = $rwRol["rolTitle$this->local"];
        $arrRoleIDs[] = $rwRol["rolID"];
    }
    $oSQL->free_result($rsRoles); 

    $this->arrUsrData = array_merge($rwUser, $rwPerms);

    $clear_uri = preg_replace('/^'.preg_quote(dirname($_SERVER['PHP_SELF']), '/').'/', '', $_SERVER['REQUEST_URI']);
    if($rwPage = $oSQL->f($oSQL->q("SELECT * FROM stbl_page WHERE pagFile=".$oSQL->e($clear_uri))) ) {
        $this->arrUsrData = array_merge( $this->arrUsrData, $rwPage);  
    }

    $this->arrUsrData["roles"] = $arrRoles;
    $this->arrUsrData["roleIDs"] = $arrRoleIDs;
    $_SESSION["last_login_time"] = Date("Y-m-d H:i:s");
    
    $this->usrID = $this->arrUsrData["usrID"];
    return $this->arrUsrData;
     
}

/**
 * This method returns content of top-level "jumper" menu as drop-down list. "Jumper" menu content goes with an associative array passed as parameter to this function.
 */
public function topLevelMenu($arrItems = array(), $options = array()){

    if(!$arrItems)
        return '';

    $defaultOptions = array('format' => 'html'
        , 'element'=>'select'
        , 'class'=>array('ei-top-level-menu')
        , 'target' => null);

    $options = array_merge_recursive($defaultOptions, (array)$options);

    $retVal = '';

    if(count($arrItems)>0 && strtolower($options['format'])=='html')
        $retVal .= '<'.$options['element'].' class="'.implode(' ', $options['class']).'">';

    foreach( (array)$arrItems as $itemID=>$item ){

        $itemID_HTML = preg_replace('/[^a-z0-9]/i', '', $itemID);

        switch( strtolower($options['element']) ){
            case 'select':
                $retVal .= "\r\n".'<option value="'.(is_array($item) && $item['value'] ? $item['value'] : $itemID).'"'
                    .' class="menu-item' .'"'
                    .' id="'.$options['class'][0].'-'.$itemID_HTML .'">'.(is_array($item) 
                        ? $item['title']
                        : $item).'</option>';
                break;
            case 'ul':
            case 'ol':
                $retVal .= "\r\n".'<li id="'.$options['class'][0].'-'.$itemID_HTML.'"'
                    .' class="menu-item"'
                    .'>'
                    .(is_array($item) 
                        ? ($item['href'] ? '<a href="'.$item['href'].'">' : '').$item['title'].($item['href'] ? '</a>' : '')
                        : $item).'</li>';
                break;
            default:
                throw new eiseException('Bad element for top-level menu: '.$options['element']);
        }
    }

    if(count($arrItems)>0)
        $retVal .= '</'.$options['element'].'>';


    return $retVal;
}

/**
 * This method returns system menu <ul> HTML for menu structure
 *
 * @param string $target - base target for all <a href="..."> inside menu
 *
 * @return string HTML with menu structure
 */
public function menu($target = null){

    $target = ($target ? ' target="'.$target.'"' : '');

    /*-----------------------------Standard menu from stbl_page table---------------------------------*/    
    $sql = "SELECT PG1.*
                , COUNT(DISTINCT PG2.pagID) as iLevelInside
                , (SELECT COUNT(*) FROM stbl_page CH 
                    WHERE CH.pagParentID=PG1.pagID AND CH.pagFlagShowInMenu=1) as nChildren
                , MAX(PGR.pgrFlagRead) as FlagRead
                , MAX(PGR.pgrFlagWrite) as FlagWrite
        FROM stbl_page PG1
                INNER JOIN stbl_page PG2 ON PG2.pagIdxLeft<=PG1.pagIdxLeft AND PG2.pagIdxRight>=PG1.pagIdxRight
                INNER JOIN stbl_page PG3 ON PG3.pagIdxLeft BETWEEN PG1.pagIdxLeft AND PG1.pagIdxRight AND PG3.pagFlagShowInMenu=1
                INNER JOIN stbl_page_role PGR ON PG1.pagID = PGR.pgrPageID
                INNER JOIN stbl_role ROL ON PGR.pgrRoleID=ROL.rolID
                LEFT JOIN stbl_role_user RLU ON PGR.pgrRoleID=RLU.rluRoleID
        WHERE 
         (RLU.rluUserID='{$this->usrID}' OR ROL.rolFlagDefault=1)
         AND PG1.pagFlagShowInMenu=1
        GROUP BY 
                PG1.pagID
                , PG1.pagParentID
                , PG1.pagTitle{$this->local}
                , PG1.pagFile
                , PG1.pagIdxLeft
                , PG1.pagIdxRight
                , PG1.pagFlagShowInMenu
        HAVING (MAX(PGR.pgrFlagRead)=1 OR MAX(PGR.pgrFlagWrite)=1) 
        ORDER BY PG1.pagIdxLeft";
        
    $rs = $this->oSQL->do_query($sql);

    $strRet = '<ul class="simpleTree ei-menu">'."\r\n";

    $strRet .= '<li id="menu_root" class="root"><span><strong>Menu</strong></span>'."\r\n";

    $rw_old["iLevelInside"] = 1;

    while ($rw = $this->oSQL->fetch_array($rs)){
        
        $rw["pagFile"] = preg_replace("/^\//", "", $rw["pagFile"]);
        
        $hrefSuffix = "";
        
        for ($i=$rw_old["iLevelInside"]; $i>$rw["iLevelInside"]; $i--)
           $strRet .= "</ul></li>\r\n\r\n";
        
        for ($i=$rw_old["iLevelInside"]; $i<$rw["iLevelInside"]; $i++)
           $strRet .= "<ul>";
        
        if (preg_match("/list\.php$/", $rw["pagFile"]) && $rw["pagEntityID"]!=""){
           $hrefSuffix = "?".$rw["pagEntityID"]."_staID=".($rw['pagFlagShowMyItems'] ? '&'.$rw["pagEntityID"].'_'.$rw["pagEntityID"].'FlagMyItems=' : '') ;
           $rwEnt = $this->oSQL->f('SELECT * FROM stbl_entity WHERE entID='.$this->oSQL->e($rw["pagEntityID"]));
        }
        
        $flagIsEntity = ($rw["pagFile"]=="entity_form.php" && $rw["pagEntityID"]=="ent" ? true : false);
        
        $strRet .= "<li".($rw["pagParentID"]==1 && ($rw["FlagWrite"] || !$this->conf['menuCollapseAll'])
                 ? " class='open'"
                 : "")." id='".$rw["pagID"]."'>".
          ($rw["pagFile"] && !$flagIsEntity && !($rw["pagFile"]=="entity_form.php" && $rw["pagEntityID"]=="ent")
            ? "<a{$target} href='".$rw["pagFile"].$hrefSuffix."'>"
            : "")
          ."<span>".$rw["pagTitle{$this->local}"]."</span>".
          ($rw["pagFile"] && !$flagIsEntity
            ? "</a>"
            : ""
            )
            .($rw["nChildren"]==0 && !($rw["pagFile"]=="entity_form.php" && $rw["pagEntityID"]=="ent") ? "</li>" : "")."\r\n";
       
        if ($hrefSuffix){

            if($rw['pagFlagShowMyItems']){
                $strRet .= '<li id="'.$rw["pagID"].'-my-items"><a target="pane" href="'
                    .$rw["pagFile"].'?'.$rw["pagEntityID"].'_staID=&'.$rw["pagEntityID"].'_'.$rw["pagEntityID"].'FlagMyItems=1">'
                    .($this->translate('My ').$rwEnt["entTitle{$this->local}Mul"])
                    ."</a>\r\n";
            }

            $sqlSta = "SELECT * FROM stbl_status WHERE staEntityID='".$rw["pagEntityID"]."' AND staFlagDeleted=0";
            $rsSta = $this->oSQL->do_query($sqlSta);
            while ($rwSta = $this->oSQL->fetch_array($rsSta)){
                $strRet .= "<li id='".$rw["pagID"]."_".$rwSta["staID"]."'><a{$target} href='"
                    .$rw["pagFile"]."?".$rw["pagEntityID"]."_staID=".$rwSta["staID"]."'>"
                    .($rwSta["staTitle{$this->local}Mul"] ? $rwSta["staTitle{$this->local}Mul"] : $rwSta["staTitle{$this->local}"])
                    ."</a>\r\n";
            }
        }
       
       if ($rw["pagFile"]=="entity_form.php" && $rw["pagEntityID"]=="ent"){
          $strRet .= "<ul>\r\n";
          $sqlEnt = "SELECT * FROM stbl_entity";
          $rsEnt = $this->oSQL->do_query($sqlEnt);
          while ($rwEnt = $this->oSQL->fetch_array($rsEnt)){
             $strRet .= "<li id='".$rw["pagID"]."_".$rwEnt["entID"]."'><a{$target} href='".
                $rw["pagFile"]."?entID=".$rwEnt["entID"]."'>".$rwEnt["entTitle{$this->local}"]."</a>\r\n";
          }
          $strRet .= "</ul></li>\r\n";
       }
       
       $rw_old = $rw;
    }
    for ($i=$rw_old["iLevelInside"]; $i>1; $i--)
       $strRet .= "</ul>\r\n\r\n";

    $strRet .= '</ul>'."\r\n\r\n"; // /div.ei-menu

    return $strRet;

}

/**
 * This method returns HTML for "action menu" - the menu that displayed above the functional part of the screen. Menu content is set by $arrActions parameter, the set of associative arrays with menu items.
 * Menu item definition array consists of the following properties:
 * array[] - menu item set. No nested menu items, no dropdowns in this version.
 *  ['title']   string Menu item title
 *  ['action']  string Menu item HREF attribute content. If it starts with 'javascript:' JS call will be encapsulated under ONCLICK attribute.
 *  ['targer']  string (optional) TARGET attribute content
 *  ['class']   string (optional) CLASS attribute content
 * All these attributes are related to A element that correspond to given menu item.
 *
 * @param array $arrActions (See above)
 * @param boolean $flagShowLink The flag that defines is there a need to show 'Link' menu element on the right, in case when page opened within a frame. FALSE by default.
 *
 * @return string HTML for "action menu".  
 */
function actionMenu($arrActions = array(), $flagShowLink=false){

    $strRet .= '<div class="menubar ei-action-menu" id="menubar">'."\r\n";
    for ($i=0;$i<count($arrActions);$i++) {
            $strRet .=  "<div class=\"menubutton\">";
            $strClass = ($arrActions[$i]['class'] != "" ? " class='ss_sprite ".$arrActions[$i]['class']."'" : "");
            $strTarget = (isset($arrActions[$i]["target"]) ? " target=\"{$arrActions[$i]["target"]}\"" : "");
            $isJS = preg_match("/javascript\:(.+)$/", $arrActions[$i]['action'], $arrJSAction);
            if (!$isJS){
                 $strRet .=  "<a href=\"".$arrActions[$i]['action']."\"{$strClass}{$strTarget}>{$arrActions[$i]["title"]}</a>\r\n";
            } else {
                 $strRet .=  "<a href=\"".$arrActions[$i]['action']."\" onclick=\"".$arrJSAction[1]."; return false;\"{$strClass}>{$arrActions[$i]["title"]}</a>\r\n";
            }
            $strRet .=  "</div>\r\n";
    }

    if($flagShowLink){
        $strRet .= '<div class="menubutton float_right no-title"><a target=_top href="index.php?pane='.urlencode($_SERVER["REQUEST_URI"])
            .'" class="ss_sprite ss_link"></a></div>'."\r\n";
    }

    $strRet .= '</div>'."\r\n";

    return $strRet;

}

/**
 * This method includes specified $components into your PHP code by calling corresponding include() PHP functions and filling out $arrJS and $arrCSS arrays.
 * @param variant $components Array or string with eiseIntra's component name. Name set can be the following:
 * - base
 * - list
 * - grid
 * - actions
 * - 
 */
function requireComponent($components){

    GLOBAL $arrJS, $arrCSS, $eiseIntraCSSTheme;

    if(!is_array($components))
        $components = func_get_args();

    foreach($components as $componentName){
        switch ($componentName) {
            case 'base':
                $arrJS[] = jQueryPath."jquery-1.6.1.min.js";
                $arrJS[] = jQueryUIPath.'jquery-ui.min.js';
                $arrJS[] = eiseIntraJSPath."intra.js";
                $arrJS[] = eiseIntraJSPath."intra_execute.js";
                $arrCSS[] = eiseIntraCSSPath.'themes/'.$eiseIntraCSSTheme.'/style.css';
                break;
                
            case 'simpleTree':
                $arrJS[] = eiseIntraLibRelativePath."simpleTree/jquery.simple.tree.js";
                $arrCSS[] = eiseIntraLibRelativePath."simpleTree/simpletree.css";
                break;

            case 'jquery-ui':
                break;

            case 'batch':
                $arrJS[] = eiseIntraJSPath."eiseIntraBatch.jQuery.js";
                break;

            case 'list':
                $this->requireComponent('jquery-ui');
                include_once(dirname(__FILE__)."/list/inc_eiseList.php");
                $arrJS[] = eiseIntraRelativePath."list/eiseList.jQuery.js";
                
                break;
            case 'grid':
                $this->requireComponent('jquery-ui');
                include_once (dirname(__FILE__).'/grid/inc_eiseGrid.php');
                $arrJS[] = eiseIntraRelativePath.'grid/eiseGrid.jQuery.js';
                break;

            default:
                # code...
                break;
        }
    }

}

/**
 * This function returns cookie path for given location. In case when flagSetGlobalCookieOnRedirect it returns $this->conf['cookiePath'] constant. Otherwise it returns path part of location URL.
 * 
 * @param string $strLocation header('Location: {}') parameter
 * @param array $arrConfig Array with one usable boolean property: flagSetGlobalCookieOnRedirect. See above.
 *
 * @return string A cookie path.
 */
private function getCookiePath($strLocation, $arrConfig = array()){

    $conf = array_merge($this->conf, $arrConfig);
    
    return ($conf['flagSetGlobalCookieOnRedirect']
        ? $this->conf['cookiePath']
        : parse_url($strLocation, PHP_URL_PATH) 
    );
}

/**
 * This method adds HTTP header "Location" that redirects user to URL/URI specified in $strLocation, with text message to be shown on this page, specified in $strMessage parameter.
 * Message will be shown on eiseIntra enabled page, using $('body').eiseIntra('showMessage') function that will fire right after $('window').load() event. 
 * Message will be saved for display using cookies. By default cookie path is the path part of $strLocation URL. If $intra->conf['flagSetGlobalCookieOnRedirect'] is TRUE, cookie path will be set by global constant $this->conf['cookiePath'].
 * This property can be overriden for this function with the $arrConfig[] parameter member 'flagSetGlobalCookieOnRedirect' = TRUE|FALSE. It can be useful when you need to redirect user from project subdirectory to the script placed at the root one, for example:
 * @example $intra->redirect('Operation successfull', '/myproject/item_form.php?itemID=12345'); // normal redirect within the project
 * @example $intra->redirect('Bye-bye, see you later', '/byebye.php', array('flagSetGlobalCookieOnRedirect'=>true)); // when $this->conf['cookiePath']='/' and you redirect user to the root dir of your web server.
 *
 * @param string $strMessage Message content.
 * @param string $strLocation header('Location: {}') parameter
 * @param array $arrConfig Array with one usable boolean property: flagSetGlobalCookieOnRedirect. See above.
 *
 * @return nothing, script execution terminates.
 */
function redirect($strMessage, $strLocation, $arrConfig = array()){

    $conf = array_merge($this->conf, $arrConfig);

    setcookie ( $conf['UserMessageCookieName'], $strMessage, 0, $this->getCookiePath($strLocation,  $arrConfig) );
    header("Location: {$strLocation}");
    die();

}

/**
 * This method returns proper 'Back' reference for this button in Action Menu. If $_SERVER['HTTP_REFERER'] doesn't contain current URI, it set a cookie with referring page.
 * Otherwise, it use this cookie value, and if it's absent, it returns $urlIfNoReferer parameter.
 * It works like this: when user arrives to given form via hyperlink in list or other form, or whatever that leaves HTTP_REFERER header, it returns this value and saves a cookie with that URL, with this form path. When user saves data on this form it appears back without this HTTP header and 'Back' button needs proper value. It takes it from cookie (if it exists) or from specified parameter.
 *
 * @example $arrActions[] = array('title'=>'Back', 'action'=>$intra->backref('myitems_list.php')); // it will return user to the item list by default
 *
 * @param string $urlIfNoReferer URL(URI) for 'Back' reference in case when there's no $_SERVER['HTTP_REFERER'] or $_SERVER['HTTP_REFERER'] leads from itself.
 *
 * @return string URL
 */
function backref($urlIfNoReferer){
    
    if (strpos($_SERVER["HTTP_REFERER"], $_SERVER["REQUEST_URI"])===false //if referer is not from itself
        &&
        strpos($_SERVER["HTTP_REFERER"], 'index.php?pane=')===false ) //and not from fullEdit
    {
        SetCookie("referer", $_SERVER["HTTP_REFERER"], 0, $_SERVER["PHP_SELF"]);
        $backref = $_SERVER["HTTP_REFERER"];
    } else {
        $backref = ($_COOKIE["referer"] ? $_COOKIE["referer"] : $urlIfNoReferer);
    }
    return $backref;

}

/**
 * Function outputs JSON-encoded response basing on intra specification and terminates the script.
 * 
 * @param string $status - response status. 'ok' should be set in case of successfull execution
 * @param string $message - status message to be displayed to the user
 * @param varian $data - data to be transmitted
 *
 */
function json($status, $message, $data=null){
    
    header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Date in the past
    header("Content-type: application/json"); // JSON

    echo json_encode(array('status'=>$status, 'message'=>$message, 'data'=>$data));

    die();

}

/**
 * This function outputs necessary stuff to start batch data operation script.
 *
 *
 */
function batchStart(){
    
    header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Date in the past
    header("Content-type: text/html;charset=utf-8"); // HTML

    $this->flagBatch = true;

    ob_start();

    for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
    ob_implicit_flush(1);
    echo str_repeat(" ", 256)."<pre>"; ob_flush();

    set_time_limit(1200); // 20 minutes
}

/**
 * This function outputs data at batch data operation script, adds htmlspecialchars() and flushes output buffer.
 *
 *
 */

function batchEcho($string){
    $args = func_get_args();
    echo htmlspecialchars( 
        call_user_func_array( 
            ($this->conf['auto_translate'] 
                ? array($this, 'transate')
                : 'sprintf'
                )
            , $args) 
        );
    ob_flush();
    flush();
}

/**
 * This function retrievs user message from the cookie and deletes the cookie itself.
 */
function getUserMessage(){
    $strRet = $_COOKIE[$this->conf['UserMessageCookieName']];
    if($strRet){
        setcookie($this->conf['UserMessageCookieName'], '', 0, $this->getCookiePath($_SERVER['PHP_SELF']));
        setcookie($this->conf['UserMessageCookieName'], ''); // backward-compatibility
    }
    return $strRet;
}

function getRoleUsers($strRoleName) {
   $sqlRoleUsers = "SELECT rluUserID
       FROM stbl_role ROL
       INNER JOIN stbl_role_user RLU ON RLU.rluRoleID=ROL.rolID
       WHERE rolID='$strRoleName'   AND DATEDIFF(NOW(), rluInsertDate)>=0";
   $rsRole = $this->oSQL->do_query($sqlRoleUsers);
   while ($rwRole = $this->oSQL->fetch_array($rsRole))
      $arrRoleUsers[] = $rwRole["rluUserID"];

   return $arrRoleUsers;
}

function checkLanguage(){
    
    if(isset($_GET["local"])){
        $cookieSet = false;
        switch ($_GET["local"]){
            case "on":
                SetCookie("l", "Local");
                $cookieSet = true;
                break;
            case "off":
                SetCookie("l", "en");
                $cookieSet = true;
                break;
            default:
                break;
        }
        if($cookieSet){
            $qs = preg_replace('/([\?\&]{0,1}local\=(on|off))/', '', $_SERVER['QUERY_STRING']);
            header("Location: ".$_SERVER["PHP_SELF"].($qs ? '?'.$qs : '') );
            die();  
        }
        
    } else 
    if (!isset($_COOKIE["l"]) && preg_match("/(ru|uk|be)/i", $_SERVER["HTTP_ACCEPT_LANGUAGE"])){
        SetCookie("l", "Local");
        $this->local = "Local";
    }

    $this->local = (isset($_COOKIE["l"]) 
        ? ( $_COOKIE["l"]=="en" 
            ? ""
            : "Local")
        : (isset($this->local) 
            ? $this->local 
            : ""));

    $this->conf['local'] = $this->local;

}

function translate($key){
    
    $key = addslashes($key);

    $args = func_get_args();
    array_shift($args);
    
    if (!isset($this->lang[$key]) && $this->conf['collect_keys'] && $this->local){
        $this->addTranslationKey($key);
    }
    $retVal = @vsprintf( (isset($this->lang[$key]) ? $this->lang[$key] : $key), $args );
    return stripslashes(
        ( $retVal ? $retVal : (isset($this->lang[$key]) ? $this->lang[$key] : $key) )
        );
}

function addTranslationKey($key){
    $oSQL = $this->oSQL;
    $sqlSTR = "INSERT IGNORE INTO stbl_translation (
        strKey
        , strValue
        ) VALUES (
        ".$oSQL->escape_string($key)."
        , '');";
    $oSQL->q($sqlSTR);
}


function readSettings(){
    
    $oSQL = $this->oSQL;
    
    /* ?????????????? ?????????? ?? tbl_setup ? ?????? ============================ BEGIN */
    
    $arrSetup = Array();
    
    $sqlSetup = "SELECT * FROM stbl_setup";

    $rsSetup = $oSQL->do_query($sqlSetup);

    while ($rwSetup = $oSQL->fetch_array($rsSetup)){

        switch ($rwSetup["stpCharType"]){
            case "varchar":
            case "text":
                $arrSetup[$rwSetup["stpVarName"]] = $rwSetup["stpCharValue"];
            break;
            case "numeric":
                if (preg_match("/^[0-9,\.]+$/",$rwSetup["stpCharValue"])) {
                    eval("\$arrSetup['".$rwSetup["stpVarName"]."'] = ".str_replace(",", ".", $rwSetup["stpCharValue"]).";\n");
                }
                break;
        case "boolean":
            switch ($rwSetup["stpCharValue"]){
                case "on":
                case "true":
                case "yes":
                case 1:
                    eval("\$arrSetup['".$rwSetup["stpVarName"]."'] = true; \n");
                    break;
                default:
                    eval("\$arrSetup['".$rwSetup["stpVarName"]."'] = false; \n");
                    break;
            }
            default: 
                $arrSetup[$rwSetup["stpVarName"]] = $rwSetup["stpCharValue"];
                break;
        }
    }
    /* ?????????????? ?????????? ?? tbl_setup ============================ END */
    $this->conf = array_merge($this->conf, $arrSetup);
    
    return $arrSetup;
}

/**
 * Input display functions
 */

/**
 * This function returns HTML for single field
 * If parameter $title is specified, it returns full HTML with container, label and input/text
 * If parameter $name is specified it returns HTML for input/text according to $value parameter
 * else it returns HTML specified in $value parameter.
 */
public function field( $title, $name=null, $value=null, $conf=array() ){

    $oSQL = $this->oSQL;

    
    if(in_array($conf['type'], array('row_id', 'hidden')) )
        return '<input type="hidden" name="'.$name.'" id="'.$name.'" value="'.htmlspecialchars($value).'">'."\r\n";

    $html = '';

    if($title!==null) {

        $flagFieldDelimiter = ($name===null && $value===null);

        $html .= "<div class=\"eiseIntraField eif-field".
                ($name 
                    ? " field-{$name}" 
                    : ($flagFieldDelimiter ? " field-delimiter" : '' )
                    ).
                ($conf['fieldClass'] ? " {$conf['fieldClass']}" : '').
                "\""
            .($name 
                ? " id=\"field_{$name}\"" 
                : ($conf['id']
                    ? ' id="'.$conf['id'].'"'
                    : '')
                ).">";

        $title = ($this->conf['auto_translate'] ? $this->translate($title) : $title);

        if(!in_array($conf['type'], array('boolean', 'checkbox', 'radio')) ) {
            if ($title!==''){
                $labelClass = ($flagFieldDelimiter ? 'field-delimiter-label' : 'title-'.$name).($conf['labelClass'] ? ' '.$conf['labelClass'] : '');
                $html .= "<label".($name ? " id=\"title_{$name}\"" : '')." class=\"{$labelClass}\">".htmlspecialchars($title).(
                    trim($title)!='' ? ':' : ''
                  )."</label>";
            }
        } else {
            $html .= "<label></label>";
        }

    }

    if($name){

        $conf['id'] = ($conf['id'] 
            ? $conf['id']
            : ( (preg_match('/\[\]$/',$name) ||  $conf['type']==='radio')
                    ? eiseIntra::idByValue($name, $value)
                    : preg_replace('/([^a-z0-9\_\.]+)/i', '', $name)
                    )
            );

        switch($conf['type']){

            case "datetime":
            case "date":
                $html .= $this->showTextBox($name
                    , ($conf['type']=='datetime' ? $this->datetimeSQL2PHP($value) : $this->dateSQL2PHP($value)) 
                    , $conf); 
                break;

            case "select":
            case "combobox"://backward-compatibility

                $defaultConf = array();
                $conf  = array_merge($defaultConf, $conf);

                $conf['source'] = self::confVariations($conf, array('source', 'arrValues', 'strTable', 'options'));
                $conf['source_prefix'] = self::confVariations($conf, array('source_prefix', 'prefix', 'prfx'));

                if(is_array($conf['source'])){
                    $ds = $conf['source'];
                } else {
                    $ds = @json_decode($conf['source'], true);
                    if(!$ds){
                        @eval('$ds = '.$conf['source']);
                        if(!$ds){
                            $aDS = explode('|', $conf['source']);
                            $ds = $aDS[0];
                            $conf['source_prefix'] = ($conf['source_prefix'] 
                                ? $conf['source_prefix']
                                : $aDS[1]);        
                        }
                    }
                }

                $conf['source'] = ($ds ? $ds : array());

                if (is_array($conf['source'])){
                    $opts = $conf['source'];
                } else {
                    $rsCMB = $this->getDataFromCommonViews(null, null, $conf["source"]
                        , $conf["source_prefix"]
                        , (! ($this->arrUsrData['FlagWrite'] && (isset($conf['FlagWrite']) ? $conf['FlagWrite'] : 1) ) )
                        , (string)$conf['extra']
                        , true
                        );
                    $opts = Array();
                    while($rwCMB = $oSQL->f($rsCMB))
                        $opts[$rwCMB["optValue"]]=$rwCMB["optText"];
                }
                $html .= $this->showCombo($name, $value, $opts, $conf);
                break;

            case "ajax_dropdown":
            case "typeahead":
                $html .= $this->showAjaxDropdown($name, $value, $conf);
                break;

            case "boolean":
            case "checkbox":
            case "radio":
                $html .= '<div class="eiseIntraValue eiseIntraCheckbox">';
                $html .= ($conf['type']==='radio' 
                    ? $this->showRadio($name, $value, $conf) 
                    : $this->showCheckBox($name, $value, $conf) );
                $html .= '<label for="'.$conf['id'].'">'.htmlspecialchars($title).'</label>';
                $html .= '</div>';
                break;

            case 'submit':
            case 'delete':
            case 'button':
                $html .= $this->showButton($name, $value, $conf);
                break;

            case "textarea":
                $html .= $this->showTextArea($name, $value, $conf);
                break;

            case 'text':
            default:
                $html .= $this->showTextBox($name, $value, $conf);
                break;
                    
        }

    } else {

        $html .= ($value ? '<div class="eiseIntraValue">'.$value.'</div>' : '');

    }


    if($title!==null){

        $html .= '</div>'."\r\n\r\n";

    }

    return $html;

}

/**
 * This function returns HTML for single fieldset
 * 
 * @param $fields - HTML with fields
 * @param $legend - contents of <legend> tag
 * @param $conf - array with configuration data, with following possible members:
 *  - id - contents of 'id' attribute of <fieldset> tag
 *  - class - contents of 'class' attribute of <fieldset> tag
 *  - attr - string of extra attributes to be added to <fieldset> tag
 *  - attr_legend - string of extra attributes to be added to <fieldset><legend> tag
 */
public function fieldset($legend=null, $fields='', $conf = array()){
 
    return '<fieldset'
        .($conf['id']!='' ? ' id="'.htmlspecialchars($conf['id']).'"' : '')
        .($conf['class']!='' ? ' class="'.htmlspecialchars($conf['class']).'"' : '')
        .($conf['attr']!='' ? ' '.$conf['attr'] : '')
        .'>'
        .($legend 
            ? "\r\n".'<legend'.($conf['attr_legend']!='' ? ' '.$conf['attr_legend'] : '').'>'.$legend.'</legend>'
            : '')
        ."\r\n".$fields
        .'</fieldset>'
        ."\r\n\r\n";
 
}
 
/**
 * This function returns HTML for the form.
 *
 * @param $action - Stands for ACTION attribute of FORM tag
 * @param $dataAction - value of DataAction form input
 * @param $fields - form inner HTML
 * @param $method - METHOD attribute of form tag
 * @param $conf - form configuration data array, contains the following possible members:
 *  - class - contents of CLASS attribute of FORM tag, all listed classes will be added to default class list (eiseIntraForm eif-form) on the right
 *  - attr - extra attributes to be added to FORM element
 *  - id - contents of ID attribute of FORM element
 *  - flagDontClose - if set to TRUE, <FORM> tag is not closed in function output.
 */
public function form($action, $dataAction, $fields, $method='POST', $conf=array()){
 
    return '<form action="'.htmlspecialchars($action).'"'
        .' method="'.htmlspecialchars($method).'"'
        .($conf['id']!='' ? ' id="'.htmlspecialchars($conf['id']).'"' : '')
        .' class="eiseIntraForm eif-form'.($conf['class']!='' ? ' '.$conf['class'] : '').'"'
        .($conf['attr']!='' ? ' '.$conf['attr'] : '')
        .'>'."\r\n"
        .$this->field(null, $this->conf['dataActionKey'], $dataAction, array('type'=>'hidden'))."\r\n"
        .$fields."\r\n"
        .($conf['flagDontClose']
            ? ''
            : '</form>'."\r\n\r\n")
        ."\r\n";
 
}


private function handleClass(&$arrConfig){

    $arrClass = Array();
    if ($this->conf['addEiseIntraValueClass'])
        $arrClass['eiseIntraValue'] = 'eiseIntraValue';
    
    // get contents of 'class' attribute in strAttrib
    $prgClass = "/\s+class=[\"\']([^\"\']+)[\"\']/i";
    $attribs = $arrConfig["strAttrib"];
    if (preg_match($prgClass, $attribs, $arrMatch)){
        $strClass = $arrMatch[1];
        $arrConfig["strAttrib"] = preg_replace($prgClass, "", $arrConfig["strAttrib"]);
    }
    
    // if we specify something in arrConfig, we add it to the string
    if (!is_array($arrConfig["class"])){
        $strClass = ($strClass!="" ? $strClass." " : "").$arrConfig["class"];
    } else {
        $strClass = ($strClass!="" ? $strClass." " : "").implode(" ",$arrConfig["class"]);
    }
    
    // split class sting into array
    $arrClassList = preg_split("/\s+/", $strClass); 
    // remove duplicates using unique key
    foreach($arrClassList as $class) 
        if($class!="")
            $arrClass[$class] =  $class;
    
    $arrConfig["class"] = $arrClass;
    return implode(" ", $arrClass);
}
    
function showTextBox($strName, $strValue, $arrConfig=Array()) {
    
    if(!is_array($arrConfig)){
        $arrConfig = Array("strAttrib"=>$arrConfig);
    }
    
    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]);
    
    $strClass = $this->handleClass($arrConfig);
   
    $strAttrib = $arrConfig["strAttrib"];
    if ($flagWrite){
        
        $strType = (in_array($arrConfig['type'], $this->arrHTML5AllowedInputTypes) ? $arrConfig["type"] : 'text');

        $strClass .= (!in_array($arrConfig['type'], $this->arrHTML5AllowedInputTypes) ? ($strClass!='' ? ' ' : '').'eiseIntra_'.$arrConfig["type"] : '');

        $strRet = "<input type=\"{$strType}\" name=\"{$strName}\" id=\"{$strName}\"".
            ($strAttrib ? " ".$strAttrib : "").
            ($strClass ? ' class="'.$strClass.'"' : "").
            ($arrConfig["required"] ? " required=\"required\"" : "").
            ($arrConfig["placeholder"] 
                ? ' placeholder="'.htmlspecialchars($arrConfig["placeholder"]).'"'
                    .' title="'.htmlspecialchars($arrConfig["placeholder"]).'"'
                : "").
            ($arrConfig["maxlength"] ? " maxlength=\"{$arrConfig["maxlength"]}\"" : "").
       " value=\"".htmlspecialchars($strValue)."\" />";
    } else {
        $strRet = "<div id=\"span_{$strName}\"".
        ($strAttrib ? " ".$strAttrib : "").
        ($strClass ? ' class="'.$strClass.'"' : "").">"
            .($arrConfig['href'] ? "<a href=\"{$arrConfig['href']}\"".($arrConfig["target"] ? " target=\"{$arrConfig["target"]}\"" : '').">" : '')
            .htmlspecialchars($strValue)
            .($arrConfig['href'] ? "</a>" : '')
        ."</div>\r\n"
        ."<input type=\"hidden\" name=\"{$strName}\" id=\"{$strName}\""
        ." value=\"".htmlspecialchars($strValue)."\" />";
    }
    
   return $strRet;
}

function showTextArea($strName, $strValue, $arrConfig=Array()){
    
    if(!is_array($arrConfig)){
        $arrConfig = Array("strAttrib"=>$arrConfig);
    }

    $strAttrib = $arrConfig["strAttrib"];
    
    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]);
    
    $strClass = $this->handleClass($arrConfig);
    
    if ($flagWrite){
        $strRet .= "<textarea"
            ." id=\"".($arrConfig['id'] ? $arrConfig['id'] : $strName)."\""
            ." name=\"".$strName."\"";
        if($strAttrib) $strRet .= " ".$strAttrib;
        $strRet .= ($strClass ? ' class="'.$strClass.'"' : '').
            ($arrConfig["required"] ? " required=\"required\"" : "").
            ($arrConfig["placeholder"] ? ' placeholder="'.htmlspecialchars($arrConfig['placeholder']).'"' : '').
            ">";
        $strRet .= htmlspecialchars($strValue);
        $strRet .= "</textarea>";
    } else {
        $strRet = "<div id=\"span_{$strName}\"".
            ($strAttrib ? " ".$strAttrib : "").
            ($strClass ? ' class="'.$strClass.'"' : "").">"
                .($arrConfig['href'] ? "<a href=\"{$arrConfig['href']}\"".($arrConfig["target"] ? " target=\"{$arrConfig["target"]}\"" : '').">" : '')
                .htmlspecialchars($strValue)."</div>\r\n"
                .($arrConfig['href'] ? '</a>' : '')
            ."<input type=\"hidden\" name=\"{$strName}\" id=\"{$strName}\""
            ." value=\"".htmlspecialchars($strValue)."\" />\r\n";
    }
    return $strRet;        
    
}

/**
 * showButton() method returns <input type="submit"> or <button> HTML. Input type should be specified in $arrConfig['type'] member. 
 *
 * @return HTML string
 *
 * @param $strName - button name and id, can be empty or null
 * @param $strValue - button label
 * @param $arrConfing - configuration array. The same as for any other form elements. Supported input types ($arrConfig['type'] values) are:
 *      - submit - <input type="submit" class="eiseIntraActionSubmit"> will be returned
 *      - delete - method will return <button class="eiseIntraDelete">
 *      - button (default) - <button> element will be returned
 */
function showButton($name, $value, $arrConfig=array()){

    if(!is_array($arrConfig)){
        $arrConfig = Array("strAttrib"=>$arrConfig);
    }
    


    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]);
    
    $o = $this->conf['addEiseIntraValueClass'];
    $this->conf['addEiseIntraValueClass'] = false;
    $strClass = $this->handleClass($arrConfig);
    $this->conf['addEiseIntraValueClass'] = $o;

    $value = ($this->conf['auto_translate'] ? $this->translate($value) : $value);

    if($arrConfig['type']=='submit'){
        $strRet = '<input type="submit"'
            .($strName!='' ? ' name="'.htmlspecialchars($name).'" id="'.htmlspecialchars($name).'"' : '')
            .' class="eiseIntraSubmit'.($strClass!='' ? ' ' : '').$strClass.'"'
            .(!$flagWrite ? ' disabled' : '')
            .' value="'.htmlspecialchars($value).'">';
    } else {
        if($arrConfig['type']=='delete')
            $strClass = 'eiseIntraDelete'.($strClass!='' ? ' ' : '').$strClass;
        $strRet = '<button'
            .($name!='' ? ' name="'.htmlspecialchars($name).'" id="'.htmlspecialchars($name).'"' : '')
            .(!$flagWrite ? ' disabled' : '')
            .'>'.htmlspecialchars($value).'</button>';
    }

    return $strRet;

}

/**
 * This method returns HTML for <select> form control.
 * Element id and name are set with $strName parameter. Selected element will be chosen accorging to $strValue. Option values and this variable will be converted being casted to strings.
 * Empty element (with empty value) will be added if $confOptions['defaultText'] option is set. 
 * $arrOptions array can have nested arrays. In this case <optgroup> tag will be added. Option group title can be set via $confOptions['optgroups'] option array. See below.
 * $confOptions is configuration array, it can have the following options:
 *  ['FlagWrite']   boolean If true, usable <select> element will be shown. Otherwise, it will be <div> with chosen option text and hidden <input> with existing value.
 *  ['class']  string contents of <select class="{...}"> attribute. Specified classes will be added to the end of class list.
 *  ['strAttrib']  string Additional <select> element attributes string, e.g. ' data-xx="YY" aria-role="nav" class="my-gorgeous-class"'. Classes will be merged with 'class' option content.
 *  ['required']   boolean If TRUE, 'required' HTML attribute will be added for form validation.
 *  ['defaultText'] string If specified, <option> with empty value will be added to the beginning of dropdown list, option text will be taken from this conf option value. If 'auto_translate' $intra option is TRUE, this value will be translated.
 *  ['deletedOptions']  array Array of option values to be marked as deleted with <option class="deleted">
 *  ['optgroups']   array Array of <optgroup> titles. If $arrOption array member is array, it will search for <optgroup> tag title in this conf option array by the same key.
 *  ['indent']  array Array of integer values for options text indent. 
 *  ['href']  string If you'd like to show hyperlink when combobox is read-only, this option value will be used as <a href="{...}"> 
 *  ['target']  string HREF target. 
 *
 * @param string $strName Input name and id
 * @param string $strValue Field falue
 * @param array $arrOptions Options array where key is <option value=""> and array element value is option text
 * @param variant $arrOptions (optional) Array with configuration options for <select> element. See above.
 *
 * @return string HTML
 */
function showCombo($strName, $strValue, $arrOptions, $confOptions=Array()){
    
    if(!is_array($confOptions)){
        $confOptions = Array("strAttrib"=>$confOptions);
    }
    
    $flagWrite = $this->isEditable($confOptions["FlagWrite"]);
    
    $retVal = "";
    
    $strClass = $this->handleClass($confOptions);
    
    $strAttrib = $confOptions["strAttrib"];

    if( ( $confVar = self::confVariations($confOptions, array('defaultText', 'textIfNull', 'strZeroOptnText')) )!==null ) // backward-compatibility
        $confOptions['defaultText'] = $confVar;

    if ($flagWrite){

        $retVal .= "<select id=\"".$strName."\" name=\"".$strName."\"".$strAttrib.
            ($strClass ? ' class="'.$strClass.'"' : "").
            ($confOptions["required"] ? " required=\"required\"" : "").">\r\n";
        if ( isset($confOptions["defaultText"]) ){
            $retVal .= "<option value=\"\">".htmlspecialchars($this->conf['auto_translate'] ? $this->translate($confOptions["defaultText"]) : $confOptions["defaultText"])."</option>\r\n" ;
        }
        if (!isset($confOptions['deletedOptions']))
            $confOptions['deletedOptions'] = array();
        foreach ($arrOptions as $key => $value){
            if (is_array($value)){ // if there's an optgoup
                $retVal .= '<optgroup label="'.(isset($confOptions['optgroups']) ? $confOptions['optgroups'][$key] : $key).'">';
                foreach($value as $optVal=>$optText){
                    $retVal .= "<option value='$optVal'".((string)$optVal==(string)$strValue ? " SELECTED " : "").
                        (in_array($optVal, $confOptions['deletedOptions']) ? ' class="deleted"' : '').
                        ">".str_repeat('&nbsp;',5*$confOptions["indent"][$key]).htmlspecialchars($optText)."</option>\r\n";
                }
                $retVal .= '</optgroup>';
            } else
                $retVal .= "<option value='$key'".((string)$key==(string)$strValue ? " SELECTED " : "").
                        (in_array($key, $confOptions['deletedOptions']) ? ' class="deleted"' : '').
                        ">".str_repeat('&nbsp;',5*$confOptions["indent"][$key]).htmlspecialchars($value)."</option>\r\n";
        }
        $retVal .= "</select>";

    } else {
        
        foreach ($arrOptions as $key => $value){
            if ((string)$key==(string)$strValue) {
               $valToShow = $value;
               $textToShow = $key;
               break;
            }
        }
        $valToShow=($valToShow!="" ? $valToShow : $confOptions["defaultText"]);
        
        $retVal = "<div id=\"span_{$strName}\""
            .($strClass ? ' class="'.$strClass.'"' : "")
            .'>'
            .($confOptions['href'] ? "<a href=\"{$confOptions['href']}\"".($confOptions["target"] ? " target=\"{$confOptions["target"]}\"" : '').">" : '')
            .htmlspecialchars($valToShow)
            .($confOptions['href'] ? '</a>' : '')
            ."</div>\r\n".
        "<input type=\"hidden\" name=\"{$strName}\" id=\"{$strName}\"".
        " value=\"".htmlspecialchars($textToShow)."\" />\r\n";
        
    
    }
    return $retVal;
}

function showCheckBox($strName, $strValue, $arrConfig=Array()){

    if(!is_array($arrConfig)){
        $arrConfig = Array("strAttrib"=>$arrConfig);
    }

    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]) ;
    
    $strClass = $this->handleClass($arrConfig);

    $id = ( $arrConfig['id'] ? $arrConfig['id'] : $strName );

    $showValueAttr = preg_match('/\[\]$/', $strName);
    
    $strAttrib = $arrConfig["strAttrib"];
    $retVal = "<input name=\"{$strName}\" id=\"{$id}\" type=\"checkbox\"".
    ( ( $strValue && !$showValueAttr ) || $arrConfig['checked'] 
        ? " checked=\"checked\" " 
        : "").
    ($showValueAttr ? ' value="'.htmlspecialchars($strValue).'"' : "").
    ($strClass ? " class=\"{$strClass}\" " : "").
    (!$flagWrite ? " readonly=\"readonly\"" : "").
    ($strAttrib!="" ? $strAttrib : " style='width:auto;'" ).">";

    return $retVal;
}

function showRadio($strName, $strValue, $arrConfig=Array()){

    if(!is_array($arrConfig)){
        $arrConfig = Array("strAttrib"=>$arrConfig);
    }

    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]);
    
    $strClass = $this->handleClass($arrConfig);

    $id = ( $arrConfig['id'] ? $arrConfig['id'] : $strName );
    
    $strAttrib = $arrConfig["strAttrib"];
    $retVal = "<input name=\"{$strName}\" id=\"{$id}\" type=\"radio\" value=\"".htmlspecialchars($strValue)."\"".
    ($arrConfig['checked'] ? " checked=\"checked\" " : "").
    ($strClass ? " class=\"{$strClass}\" " : "").
    (!$flagWrite ? " readonly=\"readonly\"" : "").
    ($strAttrib!="" ? $strAttrib : " style='width:auto;'" ).">";

    return $retVal;
}

function showRadioByArray($strRadioName, $strValue, $arrConfig){
    
    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]);
    
    $oSQL = $this->oSQL;
    
    $retVal = "";
    
    if ($arrConfig["strSQL"]){
        $rs = $oSQL->do_query($arrConfig["strSQL"]);
        while ($rw = $oSQL->fetch_array($rs)){
            $arrData[$rw["optValue"]] = $rw["optText"];
        }
    }
    
    $arrData = $arrConfig["arrOptions"];
    foreach($arrData as $value =>  $text){
        $inpID = $strRadioName."_".$value;
        $retVal .= "<input type=\"radio\" name=\"{$strRadioName}\" value=\"".htmlspecialchars($value)."\"";
        if ($strValue!="" && $value===$strValue)
            $retVal .= " checked";
        else if ($strValue=="" && $value==$arrConfig["strDefaultChecked"])
            $retVal .= " checked";
       $retVal .= " style=\"width:auto;\" id=\"{$inpID}\"".
        ($arrConfig["strAttrib"]!="" ? " ".$arrConfig["strAttrib"] : "").">".
        "<label for=\"{$inpID}\"".($arrConfig["strLabelAttrib"]!="" ? " ".$arrConfig["strLabelAttrib"] : "").">".
        htmlspecialchars($text)."</label><br>\r\n";
   }
   
   return $retVal;
   
}


function showAjaxDropdown($strFieldName, $strValue, $arrConfig) {
    
    if(!is_array($arrConfig)){
        $arrConfig = Array("strAttrib"=>$arrConfig);
    }

    $aSource = array(
        'table'=> eiseIntra::confVariations($arrConfig, array('source', 'strTable')),
        'prefix'=> eiseIntra::confVariations($arrConfig, array('source_prefix', 'prefix', 'strPrefix'))
        );

    if(isset($arrConfig['extra']))
        $aSource['extra'] = $arrConfig['extra'];
    
    $txt = eiseIntra::confVariations($arrConfig, array('text', 'strText'));

    if(!$aSource['table'])
        throw new Exception("AJAX drop-down box has no source specified", 1);


    $flagWrite = $this->isEditable($arrConfig["FlagWrite"]) ;
    
    $oSQL = $this->oSQL;
    
    if ($strValue!="" && $txt==""){
        $rs = $this->getDataFromCommonViews($strValue, "", $aSource['table'], $aSource['prefix']);
        $rw = $oSQL->fetch_array($rs);
        $txt = $rw["optText"];
    }

    if($arrConfig['href'] && $strValue && !$flagWrite ){
        $arrConfig['href'] = preg_replace('/\['.preg_quote($strFieldName, '/').'\]/', $strValue, $arrConfig['href']);
    }
    
    $strOut = "";
    $strOut .= "<input type=\"hidden\" name=\"$strFieldName\" id=\"$strFieldName\" value=\"".htmlspecialchars($strValue)."\">\r\n";

    $attr = (preg_match('/\['.preg_quote($strFieldName, '/').'\]/', $arrConfig['href']) 
                ? 'data-href="'.htmlspecialchars($arrConfig['href']).'" '
                : '')
        .' data-source="'.htmlspecialchars(json_encode($aSource)).'"'
        .$arrConfig["strAttrib"];

    $strOut .= $this->showTextBox($strFieldName."_text", $txt
            , array_merge(
                $arrConfig 
                , Array("strAttrib" => $attr
                    , 'type'=>"ajax_dropdown")
                )
            );

    return $strOut;
    
}

/**
 * This method returns True if user permissions allow to edit the data. It is possible either if FlagWrite is positive at current page or FlagCreate or FlagUpdate are too.
 * Perissions may be forced to allow editing or deny it by setting $flagToForce parameter to True or Flase correspondingly. If it's not set or null it meaningless.
 *
 * @param bool $flagToForce Flag to force permissions.
 *
 * @return bool 
 */
public function isEditable($flagToForce = null){

    return (
        $flagToForce===null 
            ? ( $this->arrUsrData["FlagWrite"] || $this->arrUsrData["FlagCreate"] || $this->arrUsrData["FlagUpdate"] ) 
            : (boolean)$flagToForce
        );

}

/**
 * Static functions that returns first occurence of configuration array $conf key variations passed as $variations parameter (array).
 *
 * @param $conf associative (configuration) array
 * @param $variations enumerated array of variations
 * 
 * @return $conf array value of first occurence of supplied key variations. NULL if key not found
 *
 * @example echo eiseIntra::confVariations(array('foo'=>'bar', 'foo1'=>'bar1'), array('fee', 'foo', 'fuu', 'fyy'));
 * output: bar
 */
public static function confVariations($conf, $variations){
    $retVal = null;
    foreach($variations as $variant){
        if(isset($conf[$variant])){
            $retVal = $conf[$variant];
            break;
        }
    }
    return $retVal;
}

public static function idByValue($name, $value, $prefix=null){
    return ($prefix ? $prefix.'-' : '').preg_replace('/([^a-z0-9\_\.]+)/i', '', $name).($value ? '-'.preg_replace('/[^a-z0-9]+/i', '', $value) : '');
}

/**
 * 
 * Page-formatting functions 
 *
 */

/**
 * Function that loads JavaScript files basing on GLOBAL $arrJS
 *
 */
function loadJS(){

    GLOBAL $js_path, $arrJS;

    $cachePreventor = $this->getCachePreventor();
    
    //------------If there's a dedicated js file for form, we're gonna load it: js/*.js
    $fn = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME);
    $absPath = dirname($_SERVER['DOCUMENT_ROOT'].str_replace('/', DIRECTORY_SEPARATOR, $_SERVER['PHP_SELF'])).DIRECTORY_SEPARATOR;
    $relPath = dirname($_SERVER['PHP_SELF']).'/';
    $subPath = (isset($js_path) ? $js_path : 'js/');
    $jsScript[$relPath.$subPath."{$fn}.js"] = $absPath
        .str_replace('/', DIRECTORY_SEPARATOR, $subPath)
        .$fn.'.js';
    $jsScript[$relPath."{$fn}.js"] = $absPath.$fn.'.js';
    foreach($jsScript as $js=>$file){
        if(file_exists($file))
            $arrJS[] = $js;
    }

    foreach($arrJS as &$js){
        if(dirname($js)==='.')
            $js = $relPath.$js;
    }

    krsort($arrJS);
    $arrJS = array_unique($arrJS);
    ksort($arrJS);
    
    //-----------load each $arrJS
    foreach ($arrJS as $jsHref){
       echo "<script type=\"text/javascript\" src=\"{$jsHref}?{$cachePreventor}\"></script>\r\n";
    }


}

/**
 * Function that loads CSS files basing on GLOBAL $arrCSS
 *
 */
function loadCSS(){
    GLOBAL $arrCSS;

    krsort($arrCSS);
    $arrCSS = array_unique($arrCSS);
    ksort($arrCSS);
    
    $cachePreventor = $this->getCachePreventor();
    
    foreach($arrCSS as $cssHref){
        echo "<link rel=\"STYLESHEET\" type=\"text/css\" href=\"{$cssHref}?{$cachePreventor}\" media=\"screen\">\r\n";
    }

}

/**
 * This function returns GET URI query string for cache prevention of JS and CSS files. Consists of cache prevention parameter, equality sign and digits from versionIntra and version cofiguration parameters of eiseIntra.
 * @return string The query string.
 */
private function getCachePreventor(){

    return self::cachePreventorVar.'='.preg_replace('/\D/', '', $this->conf['versionIntra'].$this->conf['version']);

}

/**
 * Data handling hook function. If $_GET or $_POST ['DataAction'] array member fits contents of $dataAction parameter that can be array or string, 
 * user function $function_name will be called and contents of $_POST or $_GET will be passed as parameters.
 *
 * @param variant $dataAction - string or array of possible <input name=DataAction> values that $function should handle.
 * @param variant $funcOrObj - callback function name or object which method should be invoked. Function should get $_POST or $_GET as first parameter.
 * 
 * @return variant value that return user function.
 */
function dataAction($dataAction, $funcOrObj=null){
    
    $newData = ($_SERVER['REQUEST_METHOD']=='POST' ? $_POST : (array)$_GET);
    $flagIsAJAX = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

    if($funcOrObj===null && is_string( $dataAction ) )
        $funcOrObj = $dataAction;

    $dataAction = (is_array($dataAction) ? $dataAction : array($dataAction));

    if(in_array($newData[self::dataActionKey], $dataAction)
        && ($this->arrUsrData['FlagWrite'] || $this->arrUsrData['FlagCreate'] || $this->arrUsrData['FlagUpdate'])
        ){
        
        $arrParam = func_get_args();
        array_shift($arrParam);
        array_shift($arrParam);

        if(is_callable($funcOrObj)){
            
            return call_user_func_array($funcOrObj, array_merge(Array($newData), $arrParam));

        } elseif(is_object($funcOrObj)){

            $obj = $funcOrObj;
            $method = $newData[self::dataActionKey];
            $ret = array();

            try {

                $ret = call_user_func_array(array($obj, $method), array_merge(Array($newData), $arrParam));
                $status = ($ret===False ? '500' : 'ok');
                $message = $obj->msgToUser;
                $data = (array)$ret;
                
            } catch (Exception $e) {
                $status = '500';
                $message = $e->getMessage();
                $data = array();
            }

            $message = ($status=='ok' ? '' : 'ERROR:').( $message ? $message : ($status=='ok' ? 'Data processed' : 'Error occured').sprintf(": object: %s, method: %s()", get_class($obj), $method) );
            $redirect = $obj->redirectTo;

            if($flagIsAJAX)
                $this->json($status, $message, $data);

            if($redirect)
                $this->redirect($message, $redirect);
            else {
                if(!$this->flagBatch)
                    $this->batchStart();
                $this->batchEcho($message);
                die();
            }
        }
    }

}

/**
 * Data read hook function. If $query['DataAction'] array member fits contents of $dataReadValues parameter that can be array or string, 
 * user function $function_name will be called and contents of $query parameter will be passed. If $query parameter is omitted, function 
 * will take $_GET global array.
 *
 * @param variant $dataReadValues - string or array of possible <input name=DataAction> values that $function should handle.
 * @param string $function - callback function name.
 * @param array $query - associative array data query  
 * 
 * @return variant value that return user function.
 */
function dataRead($dataReadValues, $function){
    
    $query = $_GET;

    $dataReadValues = (is_array($dataReadValues) ? $dataReadValues : array($dataReadValues));

    if(in_array($query[self::dataReadKey], $dataReadValues)
        && is_callable($function)){
            $arrParam = func_get_args();
            array_shift($arrParam);
            array_shift($arrParam);
            $arrArgs = $arrParam;
            return call_user_func_array($function, $arrArgs );
    }

}

function getDateTimeByOperationTime($operationDate, $time){

    $stpOperationDayStart = isset($this->conf['stpOperationDayStart']) ? $this->conf['stpOperationDayStart'] : '00:00'; 
    $stpOperationDayEnd = isset($this->conf['stpOperationDayEnd']) ? $this->conf['stpOperationDayEnd'] : '23:59:59'; 
    $tempDate = Date('Y-m-d');
    if (strtotime($tempDate.' '.$stpOperationDayEnd) <= strtotime($tempDate.' '.$stpOperationDayStart)
    // e.g. 1:30 < 7:30, this means that operation date prolongs to next day till $stpOperationDayEnd
        && strtotime($tempDate.' '.$time) <= strtotime($tempDate.' '.$stpOperationDayEnd)
         // and current time less than $stpOperationDayEnd
        ){
            return (Date('Y-m-d',strtotime($operationDate)+60*60*24).' '.$time);
    } else 
        return $operationDate.' '.$time;
}

function showDatesPeriod($trnStartDate, $trnEndDate){
    $strRet = (!empty($trnStartDate)
            ? $this->DateSQL2PHP($trnStartDate)
            : ""
            );
    $strRet .= ($strRet!="" && !empty($trnEndDate) && !($trnStartDate==$trnEndDate)
            ? " - "
            : "");
    $strRet .= (!empty($trnEndDate) && !($trnStartDate==$trnEndDate)
            ? $this->DateSQL2PHP($trnEndDate)
            : ""
            );
    
    return $strRet;
}

function getUserData($usrID){
    $oSQL = $this->oSQL;
    $rs = $this->getDataFromCommonViews($usrID, "", "svw_user", "");
    $rw = $oSQL->fetch_array($rs);
    
    return ($rw["optValue"]!="" 
        ? ($rw["optText{$this->local}"]==""
            ? $rw["optText"]
            : $rw["optText{$this->local}"])
         : $usrID);
}
function getUserData_All($usrID, $strWhatData='all'){
    
    $rsUser = $this->oSQL->q("SELECT * FROM stbl_user WHERE usrID='$usrID'");
    $rwUser = $this->oSQL->f($rsUser);
    
    $key = strtolower($strWhatData);
    
    switch ($key) {
        case "all":
            return $rwUser;
        case "name":
        case "fn_sn":
        case "sn_fn":
            return $rwUser["usrName"];
        case "namelocal":
            return $rwUser["usrNameLocal"];
        case "email":
        case "e-mail":
            return $rwUser["usrEMail"];
        default:
            return $rwUser[$strWhatData];
   }
}

static function getFullHREF($iframeHREF){
    $prjDir = dirname($_SERVER['REQUEST_URI']);
    $flagHTTPS = preg_match('/^HTTPS/', $_SERVER['SERVER_PROTOCOL']);
    $strURL = 'http'
        .($flagHTTPS ? 's' : '')
        .'://'
        .$_SERVER['HTTP_HOST']
        .($_SERVER['SERVER_PORT']!=($flagHTTPS ? 443 : 80) ? ':'.$_SERVER['SERVER_PORT'] : '')
        .$prjDir.'/'
        .'index.php?pane='.urlencode($iframeHREF);
    return $strURL;
}

static function getSlug(){
    $slug = preg_replace('/[^a-z0-9]+/i', '-', 
            preg_replace('/^[^a-z]+/i', '', dirname($_SERVER['PHP_SELF']))
        )
        .' '.preg_replace('/[^a-z0-9]+/i', '-',
            preg_replace('/^[^a-z]+/i', '', 
                preg_replace('/\.php$/i', '', $_SERVER['PHP_SELF'])
                )
            );
    return $slug;
}

/**
* function to obtain keyboard layout variations when user searches something but miss keyboard layout switch
* 
* It takes multibyte UTF-8-encoded string as the parameter, then it searches variations in static property self::$arrKeyboard and returns it as associative array.
*
* @package eiseIntra
* @since 1.0.15
*
* @param   string   $src    Original user input
* @return  array            Associative array of possible string variations, like array('EN'=>'qwe', 'RU'=>'йцу') 
*/

static function getKeyboardVariations($src){
    
    mb_internal_encoding('UTF-8');
    $toRet = array();

    // 1. look for origin layout. if user has mixed layouts in one criteria - it's definitely bullshit
    foreach(self::$arrKeyboard as $layoutSrc=>$keyboardSrc){
        $destToCompare = '';
        $flagAtLeastOneSymbolFound = false;
        for($i=0;$i<mb_strlen($src);$i++){
            $key = mb_substr($src, $i, 1);
            $keyIx = mb_strpos($keyboardSrc, $key);
            if($keyIx!==false){
                $val = mb_substr($keyboardSrc, $keyIx, 1);
                $flagAtLeastOneSymbolFound = true;
            } else 
                $val = $key;
            $destToCompare .= $val;
        }

        // if we've found original layout
        if( $destToCompare == $src && $flagAtLeastOneSymbolFound ){

            $toRet[$layoutSrc] = $src;

            // ... we look for variations
            foreach(self::$arrKeyboard as $layout=>$keyboard){
                if($layout==$layoutSrc) // skip original layout
                    continue;
                $dest = '';
                for($i=0;$i<mb_strlen($src);$i++){
                    $key = mb_substr($src, $i, 1);
                    $keyIx = mb_strpos($keyboardSrc, $key);
                    $val = ($keyIx===false ? $key : mb_substr($keyboard, $keyIx, 1));
                    $dest .= $val;
                }
                if($dest!=$src)
                    $toRet[$layout] = $dest;
            }

            break;

        } 

    }

    if(count($toRet)==0){
        reset(self::$arrKeyboard);
        $toRet = array(key(self::$arrKeyboard)=>$src);
    }

    return $toRet;

}

/**
 * This function rebuilds style.css for selected theme using style.less located in the same folder as style.css. 
 * REMEMBER TO chmod a+w to this folder!
 */
static function buildLess(){

    GLOBAL $eiseIntraCSSTheme, $eiseIntraFlagBuildLess;

    if(!$eiseIntraFlagBuildLess)
        return;
    
    require_once eiseIntraLibAbsolutePath.'less.php/Less.php';

    try {
        $strThemePath = $_SERVER['DOCUMENT_ROOT'].str_replace('/', DIRECTORY_SEPARATOR, eiseIntraCSSPath.'themes/'.$eiseIntraCSSTheme.'/');
 
        foreach(array('grid', 'list', 'style') as $stylesheet){
            $parser = new Less_Parser( array( 'compress' => true ) );
            $parser->parseFile( $strThemePath.$stylesheet.'.less' );
            $css = $parser->getCss();
            file_put_contents($strThemePath.$stylesheet.'.css', $css);
        }

    } catch (Exception $e) {
        echo '<pre>';
        echo $e->getMessage();
        die();
    }
 
    
 
}
 
 
/**
 * This function dumps specified $to_echo variable using var_export() or simply echoes it, with stack trace ahead
 *
 *  @param $to_echo - variable to dump
 *
 */
static function debug($to_echo){

    $args = func_get_args();
    $lastArgIx = func_num_args()-1;

    $flagStackTrace = $args[$lastArgIx];

    echo '<pre>';
    
    foreach($args as $ix=>$to_echo){
        if($ix===$lastArgIx && is_bool($ix)){
            $flagStackTrace = true;
            break;
        }

        if(is_array($to_echo) || is_object($to_echo)){
            echo htmlspecialchars(var_export($to_echo, true));
        } else 
            echo htmlspecialchars($to_echo);
        echo "\r\n";

    }

   

    if($flagStackTrace){
        $a =  debug_backtrace();

        if($a[0]['function']=='debug' && $a[0]['class']=='eiseIntra'){
            $a = array_reverse($a);
            echo "eiseIntra debug called at [{$a[0]['file']}:{$a[0]['line']}]:\n";
        }

        array_pop($a);

        foreach($a as $num=>$debug_data){
            echo "#{$num} ".($debug_data['class'] ? $debug_data['class'].'::' : '')
                           #.($debug_data['object'] ? $debug_data['object'].'->' : '')
                           .$debug_data['function'].'( '.($debug_data['args'] ? var_export($debug_data['args'], true) : '').' ) '
                           ."called at [{$debug_data['file']}:{$debug_data['line']}]\n";
        }

        /*
        function    string  The current function name. See also __FUNCTION__.
        line    integer The current line number. See also __LINE__.
        file    string  The current file name. See also __FILE__.
        class   string  The current class name. See also __CLASS__
        object  object  The current object.
        type    string  The current call type. If a method call, "->" is returned. If a static method call, "::" is returned. If a function call, nothing is returned.
        args    array   If inside a function, this lists the functions arguments. If inside an included file, this lists the included file name(s).


        #0  c() called at [/tmp/include.php:10]
        #1  b() called at [/tmp/include.php:6]
        #2  a() called at [/tmp/include.php:17]
        #3  include(/tmp/include.php) called at [/tmp/test.php:3]
        */
    }

    echo '</pre>';
 
}


}


class eiseException extends Exception  {
function __construct($msg, $level = 0){
    parent::__construct($msg);
}
}

