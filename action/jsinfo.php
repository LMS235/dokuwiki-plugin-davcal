<?php

if(!defined('DOKU_INC')) die();

class action_plugin_davcal_jsinfo extends DokuWiki_Action_Plugin {

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'add_jsinfo_information');
    }

    function add_jsinfo_information(&$event, $param) {
      global $conf;
      global $JSINFO;
      
      $lang = $conf['lang'];
      
      if(strpos($lang, "de") === 0)
      {
          $lc = 'de';
      }
      else 
      {
          $lc = 'en';    
      }
      
      $JSINFO['plugin']['davcal']['language'] = $lc;
    }  
}
