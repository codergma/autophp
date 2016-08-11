<?php

/**
 * @author ricolau<ricolau@qq.com>
 * @vesion 2016-08-11
 * @description 用户在 cache_codis::__call() 异常时回调
 * 
 * */
class plugin_codiserror extends plugin_abstract{
    
    public function call($tag, plugin_context &$ptx){
        /**
         * 需要注册到事件:  plugin::register( 'cache_codis::__call::error', new plugin_codiserror())
         * 
         * 需要对指定的url performance 抽样,
         * 如果执行时间超过1s,则必须记录log
         * 
         * 
         * 
         */
         //maybe caught exception, but just throw


d('================: '.__METHOD__);
       
       $data = &$ptx->getData(); 
       $cacheRedis = &$data['obj'];
       $cacheRedis->connect();
       $cacheRedis->flag =2222222;
       $ret = call_user_func(array(&$cacheRedis,$data['func']), $data['args']);

      // de($ptx, 222222, $con,$ret);
       $ptx->breakOut = $ret;
        
    }


}
