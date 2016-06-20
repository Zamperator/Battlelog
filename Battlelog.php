<?php

/**
 * Class BattlelogException
 * @package Battlelog
 */
class BattlelogException extends Exception {}

/**
 * Class Battlelog
 *
 * Retrieves current server and player data for your
 * Battlefield 1/3/4 & Hardline server
 * You can request the return value as json or array format
 *
 * @author Zam
 * @website http://www.probegriffeln.de
 * @required PHP 5.4+ (<7.0)
 * @version 0.6
 * @updated 2016/09/19
 * @package Battlelog
 *
 * Todo: Add alternative caching methods like memcache/xcache
 */
class Battlelog
{
    protected static $_instance;

    private $_sBattleLogUrl               = '';
    private $_sServerGUID                 = '';

    /**
     * @var array
     */
    private $_aValidGameIdList            = ['bf3','bf4','bfh','bf1'];
    /**
     * @var array
     */
    private $_aValidReturnTypes           = ['json','array'];

    /**
     * @var string
     * file cache dir without final slash
     * Todo: Add alternative caching methods like memcache/xcache
     */
    protected $sCacheDir                  = './cache';
    /**
     * @var string
     * php owner on your server
     */
    protected $sServerChown               = 'www-data';
    /**
     * @var bool
     * Set false if you want to deactivate cach
     */
    protected $bCacheContent              = true;
    /**
     * @var int
     * Cache for server data in Seconds
     */
    protected $iServerInfoCacheTimeout    = 600;
    /**
     * @var int
     * Cache for Player / GAme data in Seconds
     */
    protected $iGameInfoCacheTimeout      = 0;
    /**
     * @var string
     */
    protected $sUserAgent                 = '';

    /**
     * @param string $sUrlString
     */
    final function __construct( $sUrlString = '' )
    {
        if ( $sUrlString !== '' ) {
            $this->_sBattleLogUrl = trim(strtolower($sUrlString));
        }

        $sPregGUID = '((([a-f0-9]+){4,12}\-?){5})';
        $sGameID = 'bf4';

        $sPregString = '#^https?:\/\/battlelog\.battlefield\.com\/('
            . implode('|', $this->_aValidGameIdList)
            . ')(\/[a-z]{2})?\/servers\/show\/pc\/'
            . $sPregGUID
            . '\/?.*?$#';

        if ( preg_match($sPregString, $this->_sBattleLogUrl, $aMatch) ) {
            $sGameID = trim($aMatch[1]);
            $this->_sServerGUID = trim($aMatch[3]);
        }

        if ( !preg_match('#^'.$sPregGUID.'$#', $this->_sServerGUID) || !in_array($sGameID, $this->_aValidGameIdList)) {
            $this->_error( 'No valid battlelog url found' );
        }

    } // end __construct

    /**
     * @param string $sCacheDir
     */
    final public function setCacheDir( $sCacheDir = '' ) {
        if ( strlen($sCacheDir) > 1 ) {
            $this->sCacheDir = preg_replace('#\.\.\/#', './', $sCacheDir);
        }
    } // end public function setCacheDir

    /**
     * @param string $sUserAgent
     */
    final public function setUserAgent( $sUserAgent = '' ) {
        if ( strlen($sUserAgent) > 1 ) {
            $this->sUserAgent = $sUserAgent;
        }
    } // end public function setUserAgent

    /**
     * @param bool|true $bState
     */
    final public function useCache( $bState = true ) {
        $this->bCacheContent = (boolean)$bState;
    } // end public function useCache

    /**
     * @param string $sUrlString
     * @return Battlelog
     */
    static public function getInstance($sUrlString = '') {
        if(!is_object(self::$_instance)) {
            self::$_instance = new self($sUrlString);
        }
        return self::$_instance;
    } // end static public function getInstance

    /**
     * @param string $sCacheFileName
     * @return string
     */
    final private function _getCacheFilePath( $sCacheFileName = 'sServerInfoCache' )
    {
        if ( !is_dir($this->sCacheDir) ) {
            if ( !mkdir($this->sCacheDir) ) {
                $this->_error( 'Unable to create cache directory: ' . realpath($this->sCacheDir) );
            } else {
                if ( $this->sServerChown ) {
                    if ( !chown($this->sServerChown, $this->sCacheDir)
                        || !chgrp($this->sServerChown, $this->sCacheDir)) {
                        $this->_error( 'Could not change cache directory owner or group to ' . $this->sServerChown );
                    }
                }
            }
        }
        return $this->sCacheDir . '/cache-'.md5($this->_sServerGUID . $sCacheFileName);

    } // end private function _getCacheFilePath

    /**
     * @param string $sErrorString
     * @throws BattlelogException
     */
    final private function _error( $sErrorString = '' ) {
        //throw new BattlelogException((string)$sErrorString);
        header('Content-Type: text/plain;');
        die((string)$sErrorString);
    } // end private function _error

    /**
     * @param string $sUrlString
     * @param string $sReturnType
     * @return mixed|string
     */
    final public static function getDataNow($sUrlString = '', $sReturnType = 'array') {
        $oInstance = self::getInstance($sUrlString);
        return $oInstance->getData( $sReturnType );
    } // end public static function getDataNow

    /**
     * @param string $sUrl
     * @return mixed|string
     */
    final private function _getDataFromUrl( $sUrl = '' )
    {
        if ( !$sUrl ) {
            $this->_error('Please enter a valid battlelog url');
        }

        $sUserAgent = $this->sUserAgent?:($_SERVER['HTTP_USER_AGENT']?:getenv('HTTP_USER_AGENT'));

        if ( function_exists('curl_init') ) {

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HEADER, $sUserAgent);
            curl_setopt($ch, CURLOPT_URL, $sUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            if ( ($sError = curl_error($ch)) ) {
                curl_close($ch);
                $this->_error('Error: ' . $sError);
            }

            $sContent = curl_exec($ch);
            curl_close($ch);

        }
        else {
            // file content version
            $aHeaderData = ['Accept-language: de', "User-Agent: {$sUserAgent}"];
            $aContextOptions = [
                'http' => [
                    'method' 	=> 'GET',
                    'user_agent'=> $sUserAgent,
                    'header' 	=> implode("\r\n", $aHeaderData) . "\r\n",
                ]
            ];
            $oContext = stream_context_create($aContextOptions);
            $sContent = file_get_contents( $sUrl, false, $oContext );
            unset($aHeaderData, $aContextOptions, $oContext);
        }

        return $sContent;
    } // end private function _getDataFromUrl

    /**
     * @param string $sCacheName
     * @param int $iTimeout in Seconds
     * @param string $sRequestUrl
     * @return mixed|string
     */
    final private function _getContent( $sCacheName = '', $iTimeout = 300, $sRequestUrl = '')
    {
        if (!$this->bCacheContent ) {
            $iTimeout = 0;
        }

        $sContent   = '';
        $sCachePath = $this->_getCacheFilePath($sCacheName);

        // Get data from battlelog or cache

        if ( is_file($sCachePath) ) {
            if ( time()-filemtime($sCachePath) > $iTimeout ) {
                unlink($sCachePath);
            }
            $sContent = file_get_contents($sCachePath);
        }

        if ( !$sContent || !$iTimeout ) {
            $sContent = $this->_getDataFromUrl($sRequestUrl);
        }

        if ( $iTimeout && $sContent !== "" ) {
            @file_put_contents($sCachePath, $sContent) or $this->_error('Unable to write cache file');
        }

        return $sContent;

    } // end private function _getContent

    /**
     * @param string $sReturnType
     * @return array|bool|mixed|string
     */
    final public function getPlayerData( $sReturnType = 'array' )
    {
        if ( !$this->_sBattleLogUrl ) {
            $this->_error( 'No valid battlelog url found' );
        }

        $sRequestUrl    = 'http://keeper.battlelog.com/snapshot/' . $this->_sServerGUID . '/';
        $sContent       = $this->_getContent('sGameInfoCache', $this->iGameInfoCacheTimeout, $sRequestUrl);
        $aArrayData     = json_decode($sContent, true);

        if ( !is_array($aArrayData) ) {
            return false;
        }

        if ( !in_array($sReturnType, $this->_aValidReturnTypes)) {
            $sReturnType = 'array';
        }

        // Return
        return ( $sReturnType === 'json' ) ? $sContent : $aArrayData;

    } // end public function getPlayerData

    /**
     * @param string $sReturnType
     * @return array|bool|mixed|string
     */
    final public function getServerData( $sReturnType = 'array' )
    {
        if ( !$this->_sBattleLogUrl ) {
            $this->_error( 'No valid battlelog url found' );
        }

        $sRequestUrl    = $this->_sBattleLogUrl . '?json=1';
        $sContent       = $this->_getContent('sServerInfoCache', $this->iServerInfoCacheTimeout, $sRequestUrl);
        $aArrayData     = json_decode($sContent, true);

        if ( !is_array($aArrayData) ) {
            return false;
        }

        if ( !in_array($sReturnType, $this->aValidReturnTypes)) {
            $sReturnType = 'array';
        }

        // Return
        return ( $sReturnType === 'json' ) ? $sContent : $aArrayData;

    } // end public function getServerData

} // end class
