<?php

/**
 * @author ricolau<ricolau@qq.com>
 * @version 2017-06-20
 * @desc codis
 *
 */
class cache_codis extends cache_abstract{

    protected $_reentrantTimes = array();
    protected $_reentrantTimesLimit = 5;
    protected $_redis = null;
    protected $_reentrantErrorStartTime = array();

    const connect_timeout_default = 0.05;

    public function __construct($alias, $conf){
        $this->_alias = $alias;
        $this->_confs = $conf;

        $this->_confFormated = false;

        if(!class_exists('Redis')){
            throw new exception_cache(
            'class Redis not exists!' . (!auto::isOnlineMode() ? var_export($this->_confs, true) : ''), exception_cache::type_driver_not_exist
            );
        }
    }

    protected function _formatServer(){

        $this->_confs['serversFormat'] = array();

        $weightTotal = 0;
        foreach($this->_confs['servers'] as $k => $svr){
            $this->_confs['serversFormat'][$k] = $svr;

            if(isset($svr['weight'])){
                $svr['weight'] = intval($svr['weight']);
            }else{
                $svr['weight'] = 1;
            }

            $this->_confs['serversFormat'][$k]['weight'] = ($svr['weight'] <= 0) ? 1 : $svr['weight'];
            $this->_confs['serversFormat'][$k]['connectTimeout'] = isset($svr['connectTimeout']) ? $svr['connectTimeout'] : self::connect_timeout_default;
            $weightTotal += $this->_confs['serversFormat'][$k]['weight'];
        }
        $this->_confs['weightTotal'] = $weightTotal;
        $this->_confs['serverCount'] = count($this->_confs['serversFormat']);

        $tmpSvrs = array_values($this->_confs['serversFormat']);
        $this->_confs['serversFormat'] = array();

        $cnt = $this->_confs['serverCount'];
        for($i = 0; $i < $cnt; $i++){
            for($j = 0; $j < $cnt - $i - 1; $j++){
                if($tmpSvrs[$j]['weight'] < $tmpSvrs[$j + 1]['weight']){
                    $temp = $tmpSvrs[$j];
                    $tmpSvrs[$j] = $tmpSvrs[$j + 1];
                    $tmpSvrs[$j + 1] = $temp;
                }
            }
        }

        $this->_confs['serversFormat'] = array_values($tmpSvrs);

        $this->_confFormated = true;
        $this->_reentrantTimesLimit = $this->_confs['serverCount'] + 1;
    }

    protected function _getServer(){

        if(!$this->_confFormated || !$this->_confs['serversFormat']){
            $this->_formatServer();
        }

        $hitRate = rand(0, $this->_confs['weightTotal']);
        $weightCollect = 0;
        $hitSvr = array();
        foreach($this->_confs['serversFormat'] as $k => $svr){
            $weightCollect += $svr['weight'];
            if($hitRate <= $weightCollect){
                $hitSvr = array('key' => $k, 'server' => $svr);
                break;
            }
        }
        if($hitSvr){
            unset($this->_confs['serversFormat'][$k]);
        }else{
            $key = - 1;
            $hitSvr = array('key' => $key, 'server' => array_pop($this->_confs['serversFormat']));
        }
        $this->_confs['weightTotal'] -= $hitSvr['server']['weight'];
        $this->_confs['hitServer'] = $hitSvr;

        return $hitSvr;
    }

    public function connect(){
        $_debugMicrotime = microtime(true);

        if(!$this->_confs['servers']){
            throw new exception_cache(
            'codis connection servers empty!' . (!auto::isOnlineMode() ? var_export($this->_confs, true) : ''), exception_cache::type_server_connection_error
            );
        }


        while(true){
            $server = $this->_getServer();

            if(!$server || !$server['server']['host'] || !$server['server']['port']){
                throw new exception_cache(
                'codis connection host and port error!' . (auto::isDebug() ? var_export($server, true) : ''), exception_cache::type_server_connection_error
                );
            }
            try{
                $this->_redis = null;
                $this->_redis = new Redis();
                $con = $this->_redis->connect($server['server']['host'], $server['server']['port'], $server['server']['connectTimeout']);
                if($con && isset($server['server']['options'])){
                    foreach($server['server']['options'] as $ok=>$ov){
                        $this->_redis->setOption($ok,$ov);
                    }
                }
                ($timeCost = microtime(true) - $_debugMicrotime) && performance::add(__METHOD__, $timeCost, array('alias' => $this->_alias, 'hitServer' => $server, 'ret' => performance::summarize($con)));
            }catch(Exception $e){
                ($timeCost = microtime(true) - $_debugMicrotime) && performance::add(__METHOD__.'::error', $timeCost, array('alias' => $this->_alias, 'hitServer' => $server, 'ret' => performance::summarize($e),'line'=>__LINE__));

                //catch exception just ignore and take it to the below flow
                //continue;
            }
            if($con){
                break;
            }
            //设置重入次数上限,防止程序陷入死循环重入崩溃
            $seqid = md5($this->_alias . __METHOD__);
            if(isset($this->_reentrantTimes[$seqid]) && $this->_reentrantTimes[$seqid] >= $this->_reentrantTimesLimit){
                throw new exception_cache(
                'codis connection error too many times!' . (auto::isDebug() ? var_export($this->_confs, true) : ''), exception_cache::type_server_connection_error
                );
            }
            if(!isset($this->_reentrantTimes[$seqid])){
                $this->_reentrantTimes[$seqid] = 0;
            }
            $this->_reentrantTimes[$seqid] += 1;

            $ptx = new plugin_context(__METHOD__, array('conf' => $this->_confs, 'alias' => $this->_alias, 'obj' => &$this, 'hitServer' => $server,));
            plugin::call(__METHOD__ . '::error', $ptx);
            if($ptx->breakOut !== null){
                return $ptx->breakOut;
            }
        }

        return $this;
    }

    public function __call($funcName, $arguments){
        $method = __CLASS__ . '::' . $funcName;
        $_debugMicrotime = microtime(true);
        if(!$this->_redis){
            throw new exception_cache('codis object error!' . (auto::isDebug() ? var_export($this->_confs, true) : ''), exception_cache::type_server_connection_error);
        }
        try{
            $ret = call_user_func_array(array($this->_redis, $funcName), $arguments);
        }catch(RedisException $e){

            ($timeCost = microtime(true) - $_debugMicrotime) && performance::add($method . '::error', $timeCost, array('conf' => $this->_confs, 'alias' => $this->_alias, 'exception' => $e, 'func' => $funcName, 'args' => $arguments));

            //设置重入次数上限,防止程序陷入死循环重入崩溃
            $seqid = md5($this->_alias . serialize($arguments) . $funcName);
            if(isset($this->_reentrantTimes[$seqid]) && $this->_reentrantTimes[$seqid] >= $this->_reentrantTimesLimit){
                ($timeCost = microtime(true) - $this->_reentrantErrorStartTime[$seqid]) && performance::add(__METHOD__.'::errorMax', $timeCost, array('conf' => $this->_confs, 'alias' => $this->_alias, 'exception' => $e, 'func' => $funcName, 'args' => $arguments));
                
                $this->_reentrantErrorStartTime[$seqid] = null;
                throw $e;
            }
            if(!isset($this->_reentrantTimes[$seqid])){
                $this->_reentrantErrorStartTime[$seqid] = microtime(true);
                $this->_reentrantTimes[$seqid] = 0;
            }
            $this->_reentrantTimes[$seqid] += 1;

            $ptx = new plugin_context($method, array('conf' => $this->_confs, 'alias' => $this->_alias,
                'exception' => &$e, 'obj' => &$this, 'func' => $funcName, 'args' => $arguments));
            plugin::call(__METHOD__ . '::error', $ptx);
            if($ptx->breakOut !== null){
                return $ptx->breakOut;
            }
            throw $e;
        }
        ($timeCost = microtime(true) - $_debugMicrotime) && performance::add($method, $timeCost, array('alias' => $this->_alias, 'args' => $arguments, 'ret' => performance::summarize($ret, $method)));

        return $ret;
    }

}
