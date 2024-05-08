<?php

namespace Battlelog;

use Exception;

/**
 * Class Battlelog
 *
 * Retrieves current server and player data for your
 * Battlefield 1/3/4 & Hardline server
 * You can request the return value as json or array format
 *
 * @author Zam
 * @website ***
 * @required PHP >=8.3
 * @version 0.6
 * @updated 2024/05/08
 * @package Battlelog
 *
 * Usage:
 * $BattleLog = new BattleLog();
 * $data = $BattleLog->getPlayerData(); - returns player data
 * $data = $BattleLog->getServerData(); - returns server data
 *
 * Options:
 * $BattleLog->useCache(false); - deactivate cache
 * $BattleLog->setCacheDirectory('/path/to/cache'); - set cache directory
 * $BattleLog->setUserAgent('...'); - set user agent
 *
 * Hint: This class isn't finished and isn't working, because the API is not available anymore
 * Todo: Add alternative caching methods like memcache/xcache (Obsolete now)
 */
class Battlelog
{
    /**
     * @var int
     * Cache for server data in Seconds
     */
    const int CACHE_TIMEOUT = 600;

    /**
     * @var string
     * php owner on your working directory
     */
    protected string $directoryOwner = 'www-data';

    protected static Battlelog $instance;

    /**
     * @var string
     */
    protected string $battleLogUrl = '';
    protected string $serverGUID = '';
    /**
     *  file cache dir without slash
     *  Todo: Add alternative caching methods like memcache/xcache
     **/
    protected string $cacheDirectory = './cache';

    /**
     * @var array
     */
    protected array $validGameIdList = ['bf3', 'bf4', 'bfh', 'bf1'];
    protected array $validReturnTypes = ['json', 'array'];

    /**
     * @var bool
     * Set false if you want to deactivate cache
     */
    protected bool $cacheContent = true;

    /**
     * @var int
     * Cache for Player / Game data in seconds
     */
    protected int $gameInfoCacheTimeout = 0;
    /**
     * @var string
     */
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:54.0) Gecko/20100101 Firefox/54.0';

    /**
     * @param string $url
     * @param string $cacheDirectory
     */
    public function __construct(string $url = '', string $cacheDirectory = '')
    {
        if ($url !== '') {
            $this->battleLogUrl = trim(strtolower($url));
        }

        $regexGUID = '((([a-f0-9]+){4,12}-?){5})';
        $gameID = 'bf4';

        $regexString = '#^https?://battlelog\.battlefield\.com/('
            . implode('|', $this->validGameIdList)
            . ')(/[a-z]{2})?/servers/show/pc/'
            . $regexGUID
            . '/?.*?$#';

        if (preg_match($regexString, $this->battleLogUrl, $match)) {
            $gameID = trim($match[1]);
            $this->serverGUID = trim($match[3]);
        }

        if (!preg_match('#^' . $regexGUID . '$#', $this->serverGUID) || !in_array($gameID, $this->validGameIdList)) {
            $this->error('No valid battlelog url found');
        }

        $this->setUserAgent();
        $this->setCacheDirectory($cacheDirectory ?: $this->cacheDirectory);
    }

    /**
     * @param string $cacheDirectory
     * @return void
     */
    public function setCacheDirectory(string $cacheDirectory = ''): void
    {
        if (strlen($cacheDirectory) > 1) {
            $this->cacheDirectory = preg_replace('#[.]{2}/#', './', $cacheDirectory);
        }
    } // end public function setCacheDir

    /**
     * @param string $userAgent
     * @return void
     */
    public function setUserAgent(string $userAgent = ''): void
    {
        $this->userAgent = (strlen($userAgent) > 1
            ? $userAgent
            : ($_SERVER['HTTP_USER_AGENT'] ?: getenv('HTTP_USER_AGENT')))
            ?: $this->userAgent;
    }

    /**
     * @param bool $state
     * @return void
     */
    public function useCache(bool $state = true): void
    {
        $this->cacheContent = $state;
    }

    /**
     * @param string $cacheFileName
     * @return string
     */
    private function getCacheFilePath(string $cacheFileName = 'serverInfoCache'): string
    {
        if (!is_dir($this->cacheDirectory)) {
            if (!mkdir($this->cacheDirectory)) {
                $this->error('Unable to create cache directory: ' . realpath($this->cacheDirectory));
            } else {
                if ($this->directoryOwner) {
                    if (!chown($this->directoryOwner, $this->cacheDirectory)
                        || !chgrp($this->directoryOwner, $this->cacheDirectory)
                    ) {
                        $this->error('Could not change cache directory owner or group to ' . $this->directoryOwner);
                    }
                }
            }
        }
        return $this->cacheDirectory . '/cache-' . md5($this->serverGUID . $cacheFileName);
    }

    /**
     * @param string $errorString
     * @return void
     */
    private function error(string $errorString): void
    {
        header('Content-Type: text/plain;');
        exit($errorString);
    }

    /**
     * @param string $url
     * @return string
     */
    private function getDataFromUrl(string $url): string
    {
        $this->setUserAgent();

        $content = '';

        if (function_exists('curl_init')) {
            try {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_HEADER, $this->userAgent);
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                if (($error = curl_error($ch))) {
                    curl_close($ch);
                    $this->error('Error: ' . $error);
                }

                $content = curl_exec($ch);
            } catch (Exception $e) {
                $this->error('Error: ' . $e->getMessage());
            } finally {
                curl_close($ch);
            }
        } else {
            // file content version
            $aHeaderData = ['Accept-language: de', "User-Agent: $this->userAgent"];
            $aContextOptions = [
                'http' => [
                    'method' => 'GET',
                    'user_agent' => $this->userAgent,
                    'header' => implode("\r\n", $aHeaderData) . "\r\n",
                ]
            ];
            $context = stream_context_create($aContextOptions);
            $content = file_get_contents($url, false, $context);

            unset($aHeaderData, $aContextOptions, $context);
        }

        return $content;
    }

    /**
     * @param string $cacheName
     * @param int $cacheTimeout
     * @param string $requestUrl
     * @return false|string
     */
    private function getContent(string $cacheName = '', int $cacheTimeout = 0, string $requestUrl = ''): false|string
    {
        if (!$requestUrl && !$cacheName) {
            $this->error('No valid request');
        }

        $timeout = (!$this->cacheContent) ? 0 : $cacheTimeout;

        $content = '';
        $cachePath = $this->getCacheFilePath($cacheName);

        // Get data from battlelog or cache
        if (is_file($cachePath)) {
            if (time() - filemtime($cachePath) > $timeout) {
                unlink($cachePath);
            }
            $content = file_get_contents($cachePath);
        }

        if (!$content || !$timeout) {
            $content = $this->getDataFromUrl($requestUrl);
        }

        if ($timeout && !empty($content)) {
            @file_put_contents($cachePath, $content) || $this->error('Unable to write cache file');
        }

        return $content;
    }

    /**
     * @param string $returnType
     * @return mixed
     */
    public function getPlayerData(string $returnType = 'array'): mixed
    {
        if (!$this->battleLogUrl) {
            $this->error('No valid battlelog url found');
        }

        $requestUrl = 'https://keeper.battlelog.com/snapshot/' . $this->serverGUID . '/';
        $content = $this->getContent('sGameInfoCache', $this->gameInfoCacheTimeout, $requestUrl);
        $arrayData = json_decode($content, true) ?? [];

        if (empty($arrayData)) {
            return false;
        }

        if (!in_array($returnType, $this->validReturnTypes)) {
            $returnType = 'array';
        }

        return ($returnType === 'json') ? $content : $arrayData;
    }

    /**
     * @param string $returnType
     * @return mixed
     */
    public function getServerData(string $returnType = 'array'): mixed
    {
        if (!$this->battleLogUrl) {
            $this->error('No valid battlelog url found');
        }

        $requestUrl = $this->battleLogUrl . '?json=1';
        $content = $this->getContent('serverInfoCache', self::CACHE_TIMEOUT, $requestUrl);
        $arrayData = json_decode($content, true) ?? [];

        if (empty($arrayData)) {
            return false;
        }

        if (!in_array($returnType, $this->validReturnTypes)) {
            $returnType = 'array';
        }

        return ($returnType === 'json') ? $content : $arrayData;
    }
}
