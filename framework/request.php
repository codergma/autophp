<?php

/**
 * @author ricolau<ricolau@qq.com>
 * @version 2016-09-06
 * @desc client request lib
 *
 */
class request{

    protected static $_hasInit = false;
    protected static $_getData = array();
    protected static $_postData = array();
    protected static $_cookie = array();
    protected static $_antiXssMode = true;
    protected static $_addslashesMode = false;
    
    protected static $_requestTime = null;
    protected static $_requestTimeInt = null;

    public static function init($antiXssModeOn = true, $addslashesModeOn = false){
        if(!self::$_hasInit){
            self::$_hasInit = true;
        }else{
            throw new exception_base('request can not be inited for twice~', exception_base::error);
        }

        self::$_antiXssMode = $antiXssModeOn;
        self::$_addslashesMode = $addslashesModeOn;

        self::$_getData = self::_formatDeep($_GET);
        self::$_postData = self::_formatDeep($_POST);
        self::$_cookie = self::_formatDeep($_COOKIE);
        
        self::$_requestTime = auto::getRuntimeStart();
        self::$_requestTimeInt = intval(self::$_requestTime);
        self::_destroyOriginalData();
    }
    
    public static function hasInit(){
        return self::$_hasInit;
    }

    protected static function _checkInit(){
        if(!self::$_hasInit){
            throw new exception_base('request has not been inited! ',exception_base::error);
        }
    }
    
    public static function time($highPrecision = false){
        self::_checkInit();
        
        return !$highPrecision ? self::$_requestTimeInt :self::$_requestTime; 
    }

    public static function getAntiXssMode(){
        return self::$_antiXssMode;
    }

    public static function getAddslashesMode(){
        return self::$_addslashesMode;
    }
    
    public static function method(){
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * get $_GET parameter
     * @param str $key
     * @param enum $type = str / int
     * @param type $default
     * @return
     */
    public static function get($key, $type = 'str', $default = null){
        return self::_deal(self::$_getData, $key, $type, $default);
    }

    public static function getAll(){
        return self::$_getData;
    }

    /**
     *
     * @param str $key
     * @param enum $type = str / int
     * @param type $default
     * @return
     * @desc get post data
     */
    public static function post($key, $type = 'str', $default = null){
        return self::_deal(self::$_postData, $key, $type, $default);
    }

    /**
     * @desc get all post data
     * @return just as the original $_POST
     */
    public static function postAll(){
        return self::$_postData;
    }

    /**
     * @desc get array from $_GET
     * @param str $key
     * @return array
     */
    public static function getArray($key){
        return self::_deal(self::$_getData, $key, 'array', array());
    }

    /**
     * @desc get cookie
     * @param str $key
     * @param enum $type = str/int
     * @param type $default, default return value when got null
     * @return type
     */
    public static function cookie($key, $type = 'str', $default = null){
        return self::_deal(self::$_cookie, $key, $type, $default);
    }

    public static function cookieAll(){
        return self::$_cookie;
    }

    /**
     * @desc get post array from $_POST
     * @param str $key
     * @return type
     */
    public static function postArray($key){
        return self::_deal(self::$_postData, $key, 'array', null);
    }

    /**
     * @desc none $_REQUEST arguments get support~ just for safe reason!
     * @param str $key
     * @param enum $type = str/int
     * @param type $default, default value
     * @return null
     */
//    public function request($key, $type = 'int', $default = null){
//        return null;
//    }

    /**
     * @set request arguments by key/value
     * @param str $key
     * @param type $val
     * @param enum $type = get / post
     * @return type
     */
    public static function setParam($key, $val, $type = 'get'){
        self::_checkInit();
        
        self::setParams(array($key=>$val), $type);
    }

    /**
     * can set params batch by method
     * @param array $data
     * @param enum $type = get / post
     * @return bool
     */
    public static function setParams($data, $type = 'get'){
        self::_checkInit();
        if(!is_array($data)){
            return false;
        }

        if($type == 'get'){
            //$_GET = util::array_merge($_GET, $data);
            self::$_getData = util::array_merge(self::$_getData, self::_formatDeep($data));
        }else{
            //$_POST = util::array_merge($_POST, $data);
            self::$_postData = util::array_merge(self::$_postData, self::_formatDeep($data));
        }
    }

    protected static function _deal($data, $key, $type, $default){
        if($key == null || !isset($data[$key])){
            return $default;
        }

        switch($type){
            case 'int':
                return intval($data[$key]);
                break;

            case 'str'://不能用, case 'str' || 'string' || 'array'  php会当做 case ('str' || 'string' || 'array')执行
                return $data[$key];
                break;
            
            case 'string':
                return $data[$key];
                break;
            
            case 'array':
                return $data[$key];
                break;
           

            default:
                return $default;
                break;
        }
    }

    public static function formatText($txt){
        $txt = trim($txt);
        if(self::$_antiXssMode){
            $txt = htmlspecialchars($txt);
        }
        if(self::$_addslashesMode){
            $txt = addslashes($txt);
        }
        return $txt;
    }

    protected static function _formatDeep($data){
        if(!is_array($data)){
            return self::formatText($data);
        }else{
            foreach($data as $key => $val){
                $key2 = self::_formatDeep($key);
                $val = self::_formatDeep($val);
                if($key2 != $key){
                    unset($data[$key]);
                    $key = $key2;
                }
                $data[$key] = $val;
            }
            return $data;
        }
    }

    /**
     * @desc destroy original request arguments
     */
    protected static function _destroyOriginalData(){
        $_GET = NULL;
        $_POST = NULL;
        $_REQUEST = NULL;
        $_COOKIE = null;
    }

    public static function ip(){
        if(getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), "unknown")){
            $ip = getenv("HTTP_CLIENT_IP");
        }else if(getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), "unknown")){
            $ip = getenv("HTTP_X_FORWARDED_FOR");
        }else if(getenv("REMOTE_ADDR") && strcasecmp(getenv("REMOTE_ADDR"), "unknown")){
            $ip = getenv("REMOTE_ADDR");
        }else if(isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], "unknown")){
            $ip = $_SERVER['REMOTE_ADDR'];
        }else{
            $ip = "unknown";
        }
        return($ip);
    }

    public static function isAjax(){
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
    }

}
