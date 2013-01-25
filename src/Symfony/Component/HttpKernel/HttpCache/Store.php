<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This code is partially based on the Rack-Cache library by Ryan Tomayko,
 * which is released under the MIT license.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\HttpCache;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Finder\Finder;

/**
 * Store implements all the logic for storing cache metadata (Request and Response headers).
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Store implements StoreInterface, StoreHousekeepingInterface
{
    protected $root;
    private $keyCache;
    private $locks;
    private $responseContentList;

    /**
     * Constructor.
     *
     * @param string $root The path to the cache directory
     */
    public function __construct($root)
    {
        $this->root = $root;
        if (!is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }
        $this->keyCache = new \SplObjectStorage();
        $this->locks = array();
        $this->responseContentList = array();
    }

    /**
     * Deletes from disk all the stale response-header files and the response-content files
     *
     * @return int the number of the cleared entries
     */
    public function clear()
    {

        $finder = new Finder();

        $finder->depth('< 5')->files()->notName('*.lck')->in($this->root . DIRECTORY_SEPARATOR . 'md');
        $counter = 0;
        foreach ($finder as $file) {
            $counter += $this->purgeKey($this->getKeyByPath($file->getPathname()));
        }
        foreach ($this->getResponseContentList() as $key => $isNeeded) {
            if (!$isNeeded) {
                $file = $this->getPath($key);
                if ($this->deleteFile($file)) {
                    $counter++;
                }
            }
        }
        return $counter;
    }

    /**
     * When the Response is stale
     * it deletes the header file and add its response content file into the black list,
     * otherwise if the Response is fresh add the response content file into the white list.
     *
     * @param $key
     * @return int the number of file deleted
     */
    protected function purgeKey($key)
    {
        $metadata = $this->getMetadata($key);
        $expiredResponse = 0;
        $contentDeleted = 0;
        foreach ($metadata as $entry) {
            $response = $this->restoreResponse($entry[1]);
            $ResponseContentKey = $response->headers->get('x-content-digest');
            if (!$response->isFresh()) {
                $this->addToResponseContentList($ResponseContentKey, false);
                $expiredResponse++;
            } else {
                $this->addToResponseContentList($ResponseContentKey, true);
            }
        }
        // removes the file, only if all entries are expired
        if (count($metadata) == $expiredResponse) {
            $file = $this->getPath($key);
            if ($this->deleteFile($file)) {
                $contentDeleted++;
            }
            // deletes locks
            $this->deleteFile($this->getPath($key . '.lck'));
        }
        return $contentDeleted;
    }

    /**
     * Add a key of a response in the responseList.
     * Set the value to false if there is no other response that needs that key,
     * true if the key is needed by a non expired response.
     *
     * @param $key
     * @param bool $isNeeded
     * @return bool the current key value
     */
    private function addToResponseContentList($key, $isNeeded = true)
    {
        if (isset($this->responseContentList) && array_key_exists($key, $this->responseContentList)) {
            $this->responseContentList[$key] = $this->responseContentList[$key] || $isNeeded;
        } else {
            $this->responseContentList[$key] = $isNeeded;
        }
        return $this->responseContentList[$key];
    }

    /**
     * Deletes a file
     *
     * @param $file
     * @param $ignoreError
     * @return bool
     */
    protected function deleteFile($file, $ignoreError = true)
    {
        if (is_file($file)) {
            if ($ignoreError) {
                @unlink($file);
            } else {
                unlink($file);
            }
            return true;
        }
        return false;
    }

    /**
     * Cleanups storage.
     */
    public function cleanup()
    {
        // unlock everything
        foreach ($this->locks as $lock) {
            @unlink($lock);
        }

        $error = error_get_last();
        if (1 === $error['type'] && false === headers_sent()) {
            // send a 503
            header('HTTP/1.0 503 Service Unavailable');
            header('Retry-After: 10');
            echo '503 Service Unavailable';
        }
    }

    /**
     * Locks the cache for a given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean|string true if the lock is acquired, the path to the current lock otherwise
     */
    public function lock(Request $request)
    {
        $path = $this->getPath($this->getCacheKey($request).'.lck');
        if (!is_dir(dirname($path)) && false === @mkdir(dirname($path), 0777, true)) {
            return false;
        }

        $lock = @fopen($path, 'x');
        if (false !== $lock) {
            fclose($lock);

            $this->locks[] = $path;

            return true;
        }

        return !file_exists($path) ?: $path;
    }

    /**
     * Releases the lock for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean False if the lock file does not exist or cannot be unlocked, true otherwise
     */
    public function unlock(Request $request)
    {
        $file = $this->getPath($this->getCacheKey($request).'.lck');

        return $this->deleteFile($file);
    }

    public function isLocked(Request $request)
    {
        return is_file($this->getPath($this->getCacheKey($request).'.lck'));
    }

    /**
     * Locates a cached Response for the Request provided.
     *
     * @param Request $request A Request instance
     *
     * @return Response|null A Response instance, or null if no cache entry was found
     */
    public function lookup(Request $request)
    {
        $key = $this->getCacheKey($request);

        if (!$entries = $this->getMetadata($key)) {
            return null;
        }

        // find a cached entry that matches the request.
        $match = null;
        foreach ($entries as $entry) {
            if ($this->requestsMatch(isset($entry[1]['vary'][0]) ? $entry[1]['vary'][0] : '', $request->headers->all(), $entry[0])) {
                $match = $entry;

                break;
            }
        }

        if (null === $match) {
            return null;
        }

        list($req, $headers) = $match;
        if (is_file($body = $this->getPath($headers['x-content-digest'][0]))) {
            return $this->restoreResponse($headers, $body);
        }

        // TODO the metaStore referenced an entity that doesn't exist in
        // the entityStore. We definitely want to return nil but we should
        // also purge the entry from the meta-store when this is detected.
        return null;
    }

    /**
     * Writes a cache entry to the store for the given Request and Response.
     *
     * Existing entries are read and any that match the response are removed. This
     * method calls write with the new list of cache entries.
     *
     * @param Request  $request  A Request instance
     * @param Response $response A Response instance
     *
     * @return string The key under which the response is stored
     *
     * @throws \RuntimeException
     */
    public function write(Request $request, Response $response)
    {
        $key = $this->getCacheKey($request);
        $storedEnv = $this->persistRequest($request);

        // write the response body to the entity store if this is the original response
        if (!$response->headers->has('X-Content-Digest')) {
            $digest = $this->generateContentDigest($response);

            if (false === $this->save($digest, $response->getContent())) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            $response->headers->set('X-Content-Digest', $digest);

            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', strlen($response->getContent()));
            }
        }

        // read existing cache entries, remove non-varying, and add this one to the list
        $entries = array();
        $vary = $response->headers->get('vary');
        foreach ($this->getMetadata($key) as $entry) {
            if (!isset($entry[1]['vary'][0])) {
                $entry[1]['vary'] = array('');
            }

            if ($vary != $entry[1]['vary'][0] || !$this->requestsMatch($vary, $entry[0], $storedEnv)) {
                $entries[] = $entry;
            }
        }

        $headers = $this->persistResponse($response);
        unset($headers['age']);

        array_unshift($entries, array($storedEnv, $headers));

        if (false === $this->save($key, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }

        return $key;
    }

    /**
     * Returns content digest for $response.
     *
     * @param Response $response
     *
     * @return string
     */
    protected function generateContentDigest(Response $response)
    {
        return 'en'.sha1($response->getContent());
    }

    /**
     * Invalidates all cache entries that match the request.
     *
     * @param Request $request A Request instance
     *
     * @throws \RuntimeException
     */
    public function invalidate(Request $request)
    {
        $modified = false;
        $key = $this->getCacheKey($request);

        $entries = array();
        foreach ($this->getMetadata($key) as $entry) {
            $response = $this->restoreResponse($entry[1]);
            if ($response->isFresh()) {
                $response->expire();
                $modified = true;
                $entries[] = array($entry[0], $this->persistResponse($response));
            } else {
                $entries[] = $entry;
            }
        }

        if ($modified) {
            if (false === $this->save($key, serialize($entries))) {
                throw new \RuntimeException('Unable to store the metadata.');
            }
        }

        // As per the RFC, invalidate Location and Content-Location URLs if present
        foreach (array('Location', 'Content-Location') as $header) {
            if ($uri = $request->headers->get($header)) {
                $subRequest = Request::create($uri, 'get', array(), array(), array(), $request->server->all());

                $this->invalidate($subRequest);
            }
        }
    }

    /**
     * Determines whether two Request HTTP header sets are non-varying based on
     * the vary response header value provided.
     *
     * @param string $vary A Response vary header
     * @param array  $env1 A Request HTTP header array
     * @param array  $env2 A Request HTTP header array
     *
     * @return Boolean true if the the two environments match, false otherwise
     */
    private function requestsMatch($vary, $env1, $env2)
    {
        if (empty($vary)) {
            return true;
        }

        foreach (preg_split('/[\s,]+/', $vary) as $header) {
            $key = strtr(strtolower($header), '_', '-');
            $v1 = isset($env1[$key]) ? $env1[$key] : null;
            $v2 = isset($env2[$key]) ? $env2[$key] : null;
            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets all data associated with the given key.
     *
     * Use this method only if you know what you are doing.
     *
     * @param string $key The store key
     *
     * @return array An array of data associated with the key
     */
    private function getMetadata($key)
    {
        if (false === $entries = $this->load($key)) {
            return array();
        }

        return unserialize($entries);
    }

    /**
     * Purges data for the given URL.
     *
     * @param string $url A URL
     *
     * @return Boolean true if the URL exists and has been purged, false otherwise
     */
    public function purge($url)
    {
        if (is_file($path = $this->getPath($this->getCacheKey(Request::create($url))))) {
            unlink($path);

            return true;
        }

        return false;
    }

    /**
     * Loads data for the given key.
     *
     * @param string $key The store key
     *
     * @return string The data associated with the key
     */
    private function load($key)
    {
        $path = $this->getPath($key);

        return is_file($path) ? file_get_contents($path) : false;
    }

    /**
     * Save data for the given key.
     *
     * @param string $key  The store key
     * @param string $data The data to store
     *
     * @return Boolean
     */
    private function save($key, $data)
    {
        $path = $this->getPath($key);
        if (!is_dir(dirname($path)) && false === @mkdir(dirname($path), 0777, true)) {
            return false;
        }

        $tmpFile = tempnam(dirname($path), basename($path));
        if (false === $fp = @fopen($tmpFile, 'wb')) {
            return false;
        }
        @fwrite($fp, $data);
        @fclose($fp);

        if ($data != file_get_contents($tmpFile)) {
            return false;
        }

        if (false === @rename($tmpFile, $path)) {
            return false;
        }

        @chmod($path, 0666 & ~umask());
    }

    public function getPath($key)
    {
        return $this->root.DIRECTORY_SEPARATOR.substr($key, 0, 2).DIRECTORY_SEPARATOR.substr($key, 2, 2).DIRECTORY_SEPARATOR.substr($key, 4, 2).DIRECTORY_SEPARATOR.substr($key, 6);
    }

    public function getKeyByPath($path)
    {
        $path = substr($path, strlen($this->root.DIRECTORY_SEPARATOR));
        return str_replace(DIRECTORY_SEPARATOR, '', $path);
    }

    /**
     * get the responseContentList
     * @return array
     */
    public function getResponseContentList()
    {
        return $this->responseContentList;
    }
    /**
     * Returns a cache key for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return string A key for the given Request
     */
    private function getCacheKey(Request $request)
    {
        if (isset($this->keyCache[$request])) {
            return $this->keyCache[$request];
        }

        return $this->keyCache[$request] = 'md'.sha1($request->getUri());
    }

    /**
     * Persists the Request HTTP headers.
     *
     * @param Request $request A Request instance
     *
     * @return array An array of HTTP headers
     */
    private function persistRequest(Request $request)
    {
        return $request->headers->all();
    }

    /**
     * Persists the Response HTTP headers.
     *
     * @param Response $response A Response instance
     *
     * @return array An array of HTTP headers
     */
    private function persistResponse(Response $response)
    {
        $headers = $response->headers->all();
        $headers['X-Status'] = array($response->getStatusCode());

        return $headers;
    }

    /**
     * Restores a Response from the HTTP headers and body.
     *
     * @param array  $headers An array of HTTP headers for the Response
     * @param string $body    The Response body
     *
     * @return Response
     */
    private function restoreResponse($headers, $body = null)
    {
        $status = $headers['X-Status'][0];
        unset($headers['X-Status']);

        if (null !== $body) {
            $headers['X-Body-File'] = array($body);
        }

        return new Response($body, $status, $headers);
    }
}
