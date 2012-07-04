<?php
/**
 * 
 * This file is part of the Aura project for PHP.
 * 
 * @license http://opensource.org/licenses/bsd-license.php BSD
 * 
 */
namespace Aura\Http\Cookie;

use Aura\Http\Cookie;
use Aura\Http\Cookie\Factory as CookieFactory;
use Aura\Http\Exception as Exception;
use Aura\Http\Message\Response\Stack as ResponseStack;

/**
 * 
 * Create and read a Netscape HTTP cookie file
 * 
 * @see http://curl.haxx.se/rfc/cookie_spec.html
 * 
 * @package Aura.Http
 * 
 */
class Jar
{
    /**
     * 
     * @var array The list of cookies.
     * 
     */
    protected $list = [];

    /**
     * 
     * @var Aura\Http\Cookie\Factory
     * 
     */
    protected $factory;
    
    protected $stream;
    
    // mark as true if we opened the file ourselves
    protected $close = false;
    
    public function __construct(
        CookieFactory $factory,
        $storage
    ) {
        $this->factory = $factory;
        if (is_string($storage)) {
            $this->stream = fopen($storage, 'r+');
            $this->close = true;
        } elseif (is_resource($storage)) {
            $this->stream = $storage;
            $this->close = false;
        } else {
            throw new Exception('Unknown storage type.');
        }
        
        // load from the storage stream
        $this->load();
    }
    
    public function __destruct()
    {
        if ($this->close) {
            fclose($this->stream);
        }
    }
    
    public function __toString()
    {
        $text = [
            '# Netscape HTTP Cookie File',
            '# http://curl.haxx.se/rfc/cookie_spec.html',
            '# This file was generated by Aura. Edit at your own risk!'.
            '',
        ];
        
        foreach ($this->list as $cookie) {
            $text[] = $cookie->toJarString();
        }
        
        return implode(PHP_EOL, $text);
    }
    
    protected function load()
    {
        rewind($this->stream);
        $lines = null;
        while (! feof($this->stream)) {
            $lines .= fread($this->stream, 8192);
        }
        $lines = explode("\n", $lines);

        foreach ($lines as $line) {
            
            // skip blank lines
            $line = trim($line);
            if (! $line) {
                continue;
            }
            
            // skip comments
            if ('#' == $line[0] && '#HttpOnly_' != substr($line, 0, 10)) {
                continue;
            }
            
            // create the cookie
            $cookie = $this->factory->newInstance();
            $cookie->setFromJar($line);
            
            // skip if expired
            if ($cookie->isExpired()) {
                continue;
            }
            
            // retain
            $this->add($cookie);
        }
    }
    
    /**
     *
     * Add a Aura\Http\Cookie to the cookiejar. The cookie will not be written 
     * until `save()` is called.
     * 
     * @param Aura\Http\Cookie $cookie
     *
     */
    public function add(Cookie $cookie)
    {
        $key = $cookie->getName() . $cookie->getDomain() . $cookie->getPath();
        $this->list[$key] = $cookie;
    }
    
    public function addFromResponseStack(ResponseStack $stack)
    {
        foreach ($stack as $response) {
            $cookies = $response->getCookies()->getAll();
            foreach ($cookies as $cookie) {
                $this->add($cookie);
            }
        }
    }
    
    /**
     *
     * Save the cookies to storage.
     *
     * @return void
     *
     */
    public function save()
    {
        rewind($this->stream);
        fwrite($this->stream, $this->__toString());
    }

    /**
     *
     * List all stored cookies with an optional matching URL. The matching URL
     * must contain a scheme and host.
     *
     * @param string $matching_url
     *
     * @return array
     * 
     * @throws Aura\Http\Exception If the matching URL does not contain a
     * scheme or domain.
     *
     */
    public function getAll($matching_url = null)
    {
        if (! $matching_url) {
            return $this->list;
        }

        $url = parse_url($matching_url);

        if (! isset($url['scheme'], $url['host'])) {
            $msg = 'The `$matching_url` argument must contain a ' .
                   'scheme and a host name.';
            throw new Exception($msg);
        }

        $path = empty($url['path']) ? '/' : $url['path'];
        $return = [];

        foreach ($this->list as $key => $cookie) {
            if ($cookie->isMatch($url['scheme'], $url['host'], $path)) {
                $return[$key] = $cookie;
            }
        }

        return $return;
    }
}