<?php

/**
 * YREWRITE Addon
 * @author jan.kristinus@yakamara.de
 * @package redaxo4.5
 */

class rex_yrewrite
{

  /*
  * TODOS:
  * - call_by_article_id: forward, not_allowed
  */

  static $use_levenshtein = false;
  static $domainsByMountId = array();
  static $domainsByName = array();
  static $AliasDomains = array();
  static $pathfile = "";
  static $call_by_article_id = 'allowed'; // forward, allowed, not_allowed
  static $pathes = array();

  static function setLevenshtein($use_levenshtein = true) {
      self::$use_levenshtein = $use_levenshtein;
  }


  // ----- domain

  static function setDomain($name, $domain_article_id, $start_article_id, $notfound_article_id) 
  {
    self::$domainsByMountId[$domain_article_id] = array(
      "domain" => $name,
      "domain_article_id" => $domain_article_id, 
      "start_article_id" => $start_article_id, 
      "notfound_article_id" => $notfound_article_id,
    );
    self::$domainsByName[$name] = array(
      "domain" => $name,
      "domain_article_id" => $domain_article_id, 
      "start_article_id" => $start_article_id, 
      "notfound_article_id" => $notfound_article_id,
    );
  }

  static function setAliasDomain($from_domain, $to_domain) 
  {
    if(isset(self::$domainsByName[$to_domain])) {
      self::$AliasDomains[$from_domain] = $to_domain;
    }
  }

  static function setPathFile($pathfile) 
  {
    self::$pathfile = $pathfile;
    if(!file_exists(self::$pathfile)) {
      self::generatePathFile(array());
    }
    $content = file_get_contents(self::$pathfile);
    self::$pathes = json_decode($content, true);
  }

  // ----- article
  
  static function getDomainByArticleId($aid) {
    foreach(self::$domainsByName as $domain => $v) {
      if(isset(self::$pathes[$domain][$aid])) {
        return $domain;
      }
    }
    return "default";
  }  

  static function getArticleIdByUrl($domain, $url) {
    foreach(self::$pathes[$domain] as $c_article_id => $c_o) {
      foreach($c_o as $c_clang => $c_url) {
        if($url == $c_url) {
          return $c_article_id;
        }
      }
    }
    return false;
  }

  // ----- url
  
  static function prepare()
  {
    global $REX;

		$article_id = -1;
		$clang = $REX["CUR_CLANG"];

    // REXPATH wird auch im Backend benötigt, z.B. beim bearbeiten von Artikeln

    if (!$REX['REDAXO']) {

      // call_by_article allowed
      if(self::$call_by_article_id == "allowed" && rex_request("article_id","int")>0) {
        return true;
      }

      $url = urldecode($_SERVER['REQUEST_URI']);
      $domain = $_SERVER['HTTP_HOST'];
      $port = $_SERVER['SERVER_PORT'];
      
      // because of server differences
      if (substr($url,0,1) == '/') {
        $url = substr($url, 1);
      }
      
      // delete params
      if(($pos = strpos($url, '?')) !== false) {
        $url = substr($url, 0, $pos);
      }
  
      // delete anker
      if(($pos = strpos($url, '#')) !== false) {
        $url = substr($url, 0, $pos);
      }

      // no domain found -> set default
      if(!isset(self::$pathes[$domain])) {
      
        // check for aliases
        if(isset(self::$AliasDomains[$domain])) {
          $domain = self::$AliasDomains[$domain];
          // forward to original domain permanent move 301
          
          $http = "http://";
          if($_SERVER["SERVER_PORT"] == 443) {
            $http = "https://";
          }

          header ('HTTP/1.1 301 Moved Permanently');
          header ('Location: '.$http.$domain.'/'.$url);
          exit;
          
        } else {
          $domain = "default";
        }
      }

      $REX['DOMAIN_ARTICLE_ID'] = self::$domainsByName[$domain]['domain_article_id'];
      $REX['START_ARTICLE_ID'] = self::$domainsByName[$domain]['start_article_id'];
      $REX['NOTFOUND_ARTICLE_ID'] = self::$domainsByName[$domain]['notfound_article_id'];

      // if no path -> startarticle
      if($url == "") {
        $REX['ARTICLE_ID'] = self::$domainsByName[$domain]['start_article_id'];
        return true;
      }

      // normal exact check
      foreach(self::$pathes[$domain] as $i_id => $i_cls) {
        if($i_cls[$REX["CUR_CLANG"]] == $url || $i_cls[$REX["CUR_CLANG"]].'/' == $url) {
          $REX['ARTICLE_ID'] = $i_id;
          return true;
        }
      }

      if(rex_register_extension_point('YREWRITE_PREPARE', '', array()) ) {
        return true;
      }

      // Check levenshtein
      if (self::$use_levenshtein)
      {
      /*
        foreach (self::$pathes as $key => $var)
        {
          foreach ($var as $k => $v)
          {
            $levenshtein[levenshtein($path, $v)] = $key.'#'.$k;
          }
        }
  
        ksort($levenshtein);
        $best = explode('#', array_shift($levenshtein));
        
        rex_yrewrite::setArticleId($best[0]);
        $clang = $best[1];
      */
      }

      // no article found -> domain not found article
      $REX['ARTICLE_ID'] = self::$domainsByName[$domain]['notfound_article_id'];

      return true;
    }
  }

  static function rewrite($params)
  {
    // Url wurde von einer anderen Extension bereits gesetzt
    if($params['subject'] != '') {
  		return $params['subject'];
    }

    global $REX;
    
    $id         = $params['id'];
    $name       = $params['name'];
    $clang      = $params['clang'];
    $divider    = $params['divider'];
    $urlparams  = $params['params'];

    $url = urldecode($_SERVER['REQUEST_URI']);
    $domain = $_SERVER['HTTP_HOST'];
    $port = $_SERVER['SERVER_PORT'];

    $www = "http://";
    if($port == 443) {
      $www = "https://";
    }

    $path = "";

    // same domain id check
    if(isset(self::$pathes[$domain][$id][$clang])) {
      $path = '/'.self::$pathes[$domain][$id][$clang];
      if($REX["REDAXO"]) {
        $path = self::$pathes[$domain][$id][$clang];
      }
    }

    if($path == "") {
      foreach(self::$pathes as $i_domain => $i_id) {
        if(isset(self::$pathes[$i_domain][$id][$clang])) {
          $path = $www.$i_domain.'/'.self::$pathes[$i_domain][$id][$clang];
          break;
        }
      }
    }

    // params
    $urlparams = $urlparams == '' ? '' : '?'.substr($urlparams,1,strlen($urlparams));
    $urlparams = str_replace('/amp;','/',$urlparams);
    $urlparams = str_replace('?amp;','?',$urlparams);

    return $path.$urlparams;

  }
  
  
  /*
  *
  *  function: generatePathFile
  *  - updates or generates the file-domain-path filelist
  *  - 
  *
  */
  
  static function generatePathFile($params)
  {
    global $REX;
  	
    if(!isset($params['extension_point'])) {
      $params['extension_point'] = '';
    }
      
    $where = '';
    switch($params['extension_point']) {
    
      // clang and id specific update
      case 'CAT_DELETED':
      case 'ART_DELETED':
        foreach(self::$pathes as $domain => $c) {
          unset(self::$pathes[$domain][$params['id']]);
        }
        break;
      case 'CAT_ADDED':
      case 'CAT_UPDATED':
      case 'ART_ADDED':
      case 'ART_UPDATED':
        $where = '(id='. $params['id'] .' AND clang='. $params['clang'] .') OR (path LIKE "%|'. $params['id'] .'|%" AND clang='. $params['clang'] .')';
        break;
      // update_all / ARTICLE_GENERATED
      case 'CLANG_ADDED':
      case 'CLANG_UPDATED':
      case 'CLANG_DELETED':
      case 'ALL_GENERATED':
      default:
        $where = '1=1';
        self::$pathes = array();
  			break;
    }
    
    if($where != '') {
      $db = new rex_sql();
      // $db->debugsql=true;
      $db->setQuery('SELECT id,clang,path,startpage,yrewrite_url FROM '. $REX['TABLE_PREFIX'] .'article WHERE '. $where.' and revision=0');
      
      while($db->hasNext())
      {
      
        // _____ Sprachenkey zuerst
        $clang = $db->getValue('clang');
        $pathname = '';
        if (count($REX['CLANG']) > 1) {
          $pathname = $REX['CLANG'][$clang].'/';
        }
        
        // _____ pfad über kategorien bauen
        $domain = "default";
        $path = trim($db->getValue('path'), '|');
        if($path != '') {
          $path = explode('|', $path);
          $path = array_reverse($path,true);
          
          foreach ($path as $p) {
            if(array_key_exists($p, self::$domainsByMountId)) {
              $domain = self::$domainsByMountId[$p]["domain"];
              break;
            }
            $ooc = OOCategory::getCategoryById($p, $clang);

            $name = $ooc->getName();
            $pathname = rex_yrewrite::prependToPath($pathname, $name);
          }
        }

        // _____ Artikelnamen anhängen + .html
        $ooa = OOArticle::getArticleById($db->getValue('id'), $clang);
        if($ooa->isStartArticle()) {
          $ooc = $ooa->getCategory();
          $catname = $ooc->getName();
          $pathname = rex_yrewrite::appendToPath($pathname, $catname);
        } else {
  				$name = $ooa->getName();
  				$pathname = rex_yrewrite::appendToPath($pathname, $name);
  			}
        
    		$pathname = preg_replace('/[-]{1,}/', '-', $pathname);
        $pathname = substr($pathname,0,strlen($pathname)-1).'.html';

        if($db->getValue('yrewrite_url') != "") {
          $pathname = $db->getValue('yrewrite_url');
        }

        self::$pathes[$domain][$db->getValue('id')][$db->getValue('clang')] = $pathname;

        $db->next();
      }
    }
    
    rex_put_file_contents(self::$pathfile, json_encode(self::$pathes) );
  }
  
  static function appendToPath($path, $name)
  {
    if ($name != '') {
      $name = strtolower(rex_parse_article_name($name));
      $path .= $name.'/';
    }
    return $path;
  }
  
  static function prependToPath($path, $name)
  {
    if ($name != '') {
      $name = strtolower(rex_parse_article_name($name));
      $path = $name.'/'.$path;
    }
    return $path;
  }
  

  // ----- page
  
  static function setShowLink($params) 
  {
    global $I18N;
    $return = array();
    foreach($params["subject"] as $a) {
      if(strip_tags($a) == $I18N->msg('show')) {
        $return[] = '<a href="/' . rex_getUrl($params["article_id"],$params["clang"]) . '" onclick="window.open(this.href); return false;" '. rex_tabindex() .'>' . $I18N->msg('show') . '</a>';
      } else {
        $return[] = $a;
      }
    }
    return $return;
  }


}
