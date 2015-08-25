#!/usr/bin/env php
<?php

/**
 * Copyright (c) 2010 Last.fm. All Rights Reserved.
 * 
 *  Features:
 *  
 *  * Full support for GET, POST, auth
 *  * takes your API_KEY, API_SECRET and API_SK (an infinite session key) from env - so you can put it in .bashrc
 *  
 *  
 *  Setup:
 *  
 *  1. First, put your API_KEY and API_SECRET into the environment (you can find them at http://www.last.fm/api/account)
 *     $ export API_KEY=xxxxxxxxxxxx
 *     $ export API_SECRET=xxxxxxxxxx
 *  2. Now, use the client to get a token
 *     $ php ./client.php GET --method=auth.getToken
 *  3. Authorise that token at http://www.last.fm/api/auth/?api_key=API_KEY&token=TOKEN (change the API_KEY and TOKEN)
 *  4. Now, use the client to get a session key
 *     $ php ./client.php GET --method=auth.getSession --token=TOKEN
 *  5. Now you can take the Session key and put it in API_SK:
 *     $ export API_SK=xxxxxxxxxxx
 *     This key should be infinite.
 *  6. Finally, put all 3 export lines into ~/.bashrc
 *  
 *  
 *  *******
 *  
 *  Examples:
 *  
 *  Simple example: artist.getInfo (works without API_SK)
 *    $ php ./client.php GET --method=artist.getInfo --artist=Radiohead
 *    
 *  Logged in example: update your now playing track (requires API_SK)
 *    $ php ./client.php POST --artist="Weather Report" --method=track.updateNowPlaying --track="Man In The Green Shirt" 
 */

### This is a standalone version. It depends only on PEAR/HTTP/Request.php. All other dependent classes are INLINE. ###

error_reporting(E_ERROR | E_WARNING); // stupid deprecation warnings in HTTP_Request with PHP5.3
set_include_path(dirname(realpath(__FILE__)) . PATH_SEPARATOR . get_include_path());
#echo realpath(__FILE__); exit;
require_once("HTTP/Request.php");

class HTTPManager {

    /**
     * @return HTTPManager
    **/
    static function instance() {
        global $httpmanager_instance;
        if (!isset($httpmanager_instance)) {
            $httpmanager_instance = new HTTPManager();
        }
        return $httpmanager_instance;
    }

    protected function get_http_request_class() {
        return 'HTTP_Request';
    }

    /**
     * Returns an HTTP_Request connection
     *
     * @param string $url
     * @param array $params Passed onto HTTP_Request constructor
     * @return HTTP_Request
    **/
    static function getConnection($url, $params = array()) {
        $class = self::get_http_request_class();
        $params = array_merge(array(
            'timeout' => 0.5,
            'readTimeout' => array(5, 0),
        ), $params);
        return new $class($url, $params);
    }
    /**
     * @deprecated Use getConnection instead
    **/
    function _connect($host, $path, $params = array()) {
        return self::getConnection($host . $path, $params);
    }
    /**
     * Returns an HTTP_Request connection that uses the external proxy
     *
     * @param string $url
     * @param float $socketTimeout (seconds)
     * @param array $readTimeout (seconds,microseconds)
     * @param boolean $allowRedirects
     * @param integer $maxRedirects
     * @return HTTP_Request
    **/
    function getProxyConnection($url = null, $socketTimeout = 0.4,
                          $readTimeout = array(5,0), $allowRedirects = true, $maxRedirects = 3) {
        //global $HTTP_PROXY_ADDRESS, $HTTP_PROXY_PORT;
        $connection = self::getConnection($url, array(
            'timeout' => $socketTimeout,
            'readTimeout' => $readTimeout,
            'allowRedirects' => $allowRedirects,
            'maxRedirects' => $maxRedirects,
        ));
        //$connection->setProxy($HTTP_PROXY_ADDRESS, $HTTP_PROXY_PORT);
        return $connection;
    }
    /**
     * @deprecated Use getProxyConnection instead
    **/
    function HTTP_Request($url = null, $socketTimeout = 0.4,
                          $readTimeout = array(5,0), $allowRedirects = true, $maxRedirects = 3) {
        return self::getProxyConnection($url, $socketTimeout, $readTimeout, $maxRedirects);
    }
}

abstract class Script
{
    protected $_argc;
    protected $_argv = array();
    protected $_aFlag = array();

    public function __construct($argc=0, array $argv = array())
    {
        $this->_argv = $argv;
        $this->_argc = $argc;
        $this->_parseFlags();
    }

    /**
    * @desc Stick --[blah] arguments into a flag array, respect --[blah]=[val] and assign the values if so
    */
    protected function _parseFlags()
    {
        $count=0;
        foreach ($this->_argv as $n => $arg)
        {
            // flags
            if($arg === '--')
            {
                unset($this->_argv[$n]);
                break; // -- treated as "end parsing flags" like in most posix apps
            }
            if(substr($arg, 0, 2) == '--')
            {
                $pos = strpos($arg, '=');
                if($pos !== false)
                {
                    $flag = substr($arg, 2, $pos-2);
                    $val = substr($arg, $pos+1);
                }
                else
                {
                    $flag = substr($arg, 2);
                    $val = true;
                }

                $this->setFlag($flag, $val);
                unset($this->_argv[$n]);
            }
            $count++;
        }

        // consolidate
        $this->_argv = array_merge($this->_argv);
    }

    /**
    * @abstract
    */
    abstract function run();

    public function getFlag($key)
    {
        return isset($this->_aFlag[$key]) ? $this->_aFlag[$key] : null ;
    }

    public function setFlag($key, $val)
    {
        $this->_aFlag[$key] = $val;
    }

    public function getFlags()
    {
        return $this->_aFlag;
    }

    public function getArgs()
    {
        return $this->_argv;
    }

    public function printFlags()
    {
        foreach ($this->_aFlag as $name=>$val) {
            debug($name .':'.$val);
        }
    }
}

abstract class ScriptWithHelp extends Script
{
    protected $banner = "--== Last.fm Script (Change This Banner Text) ==--";
    protected $_script_name;
    protected $_other_options = '';

    public function __construct($argc, array $argv)
    {
        $this->_script_name = array_shift($argv);
        parent::__construct($argc, $argv);
    }

    /**
     * @var Array of 'flag_name' => 'help text'
     */
    protected $options = array(
        'help' => 'this message',
        'quiet' => 'don\'t show any log output',
    );

    public function help()
    {
        $longest_flag = 0;
        $flag_summary = array_keys($this->options);
        foreach($flag_summary as &$f)
        {
            $longest_flag = max($longest_flag, strlen($f));
            $f = "[--$f]";
        }
        $flag_summary = implode(' ', $flag_summary);

        $other_options = $this->_other_options;

        echo "Usage: {$this->_script_name} $flag_summary $other_options\n";

        foreach($this->options as $flag => $help_text)
        {
             printf("\t--%-{$longest_flag}s - %s\n", $flag, $help_text);
        }
    }

    public function banner()
    {
        $this->echobold($this->banner . "\n");
    }

    /**
     * Call this from your overridden run();
     *
     * @return boolean
     */
    protected function onStart()
    {
        $this->getFlag('quiet') || $this->banner();
        if($this->getFlag('help'))
        {
            $this->help();
            return false;
        }
        return true;
    }

    protected function echobold($s)
    {
        printf("%s[%sm%s%s[%sm", chr(27), 1, $s, chr(27), 0);
    }
}

class WSSimpleClient extends ScriptWithHelp
{
    protected $banner = "--== Music Hack Day Last.fm WS Client ==--";
    protected $_other_options = '<POST|GET> [--key=val, --key=val, ... ]';

    protected $options = array();

    public function onStart()
    {
        if(count($this->_argv) < 1) return false;
        return true;
    }

    public function run()
    {
        $this->banner();
        if($this->onStart())
        {
            $req = new WSSimpleClientRequest();

            $api_root = trim(getenv('API_ROOT'));
            if($api_root) $req->setApiRoot($api_root);

            $api_key = trim(getenv('API_KEY'));
            if($api_key) $req->setApiKey($api_key);

            $api_secret = trim(getenv('API_SECRET'));
            if($api_secret) $req->setApiSecret($api_secret);

            $sk = trim(getenv('API_SK'));
            if($sk) $req->setSk($sk);

            $req->setParams($this->_aFlag);

            switch(strtoupper($this->_argv[0]))
            {
                case 'GET':
                    echo $req->get();
                    break;

                case 'POST+GET':
                    echo $req->post_get();
                    break;

                case 'POST':
                    echo $req->post();
                    break;
            }
        }
        else
        {
            $this->help();
        }
    }
}

/**
 * This is a class which enables you to hit our live WS with a set of params for testing and generally playing around.
 *
 * @author ben
 */
class WSSimpleClientRequest
{
    protected $api_root = 'http://ws.audioscrobbler.com/2.0/';

    protected $api_key = '';
    protected $api_secret = '';
    protected $sk = '';
    protected $token;
    protected $params = array();

    public function setParams(array $params)
    {
        $this->params = $params;
    }

    public function setApiRoot($url)
    {
        $this->api_root = $url;
    }

    public function setApiKey($key)
    {
        $this->api_key = $key;
    }

    public function setApiSecret($secret)
    {
        $this->api_secret = $secret;
    }

    public function setSk($sk)
    {
        $this->sk = $sk;
    }

    public function get()
    {
        $this->prepare();
        $url = $this->api_root . '?' . http_build_query($this->params);
        $rq = $this->getHTTPRequest($url);

        echo "request:\n\n" . $rq->_buildRequest() . "\n\n";

        $rc = $rq->sendRequest();
        if(PEAR::isError($rc))
            throw new Exception('GET failed: ' . $rc->getMessage() . "\nResponse:" . $rq->getResponseBody());

        return $rq->getResponseBody();
    }

    public function post_get()
    {
        $this->prepare();
        $url = $this->api_root . '?' . http_build_query($this->params);
        $rq = $this->getHTTPRequest($url);
        $rq->setMethod('POST');

        echo "request:\n\n" . $rq->_buildRequest() . "\n\n";

        $rc = $rq->sendRequest();
        if(PEAR::isError($rc))
            throw new Exception('POST+GET failed: ' . $rc->getMessage() . "\nResponse:" . $rq->getResponseBody());

        return $rq->getResponseBody();
    }

    public function post()
    {
        $this->prepare();
        $url = $this->api_root;
        $rq = $this->getHTTPRequest($url);
        $rq->setMethod('POST');
        foreach($this->params as $key => $val)
            $rq->addPostData($key, $val);

        echo "request:\n\n" . $rq->_buildRequest() . "\n\n";

        $rc = $rq->sendRequest();
        if(PEAR::isError($rc))
            throw new Exception('POST failed: ' . $rc->getMessage() . "\nResponse:" . $rq->getResponseBody());

        return $rq->getResponseBody();
    }

    protected function prepare()
    {
        if(!isset($this->params['api_key']))
        {
            $this->params['api_key'] = $this->api_key;
        }

        if(!isset($this->params['sk']))
        {
            $this->params['sk'] = $this->sk;
        }

        if($this->api_secret && !isset($this->params['api_sig']))
        {
            ksort($this->params);

            // due to reasons, format and callback are not included in the api_sig calculation
            if(isset($this->params['format']))
            {
                $format = $this->params['format'];
                unset($this->params['format']);
            }

            if(isset($this->params['callback']))
            {
                $callback = $this->params['callback'];
                unset($this->params['callback']);
            }

            $sig_string = '';
            foreach($this->params as $key => $val)
            {
                $sig_string .= $key . $val;
            }
            $this->params['api_sig'] = md5($sig_string . $this->api_secret);

            if(isset($format))   $this->params['format']   = $format;
            if(isset($callback)) $this->params['callback'] = $callback;
        }
    }

    /**
     * @return HTTP_Request
     */
    protected function getHTTPRequest($url, $timeout=null)
    {
        $rq = HTTPManager::HTTP_Request($url, $timeout);
        return $rq;
    }
}

$_SERVER['HTTP_HOST'] = 'www.last.fm';

$script = new WSSimpleClient($argc, $argv);
$script->run();
