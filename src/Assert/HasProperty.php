<?php

namespace Rorschach\Assert;

use GuzzleHttp\Psr7\Response;
use Rorschach\Parser;

class HasProperty
{
    private $response;
    private $expect;

    /**
     * HasProperty constructor.
     * @param Response $response
     * @param $expect
     */
    public function __construct(Response $response, $expect)
    {
        $this->response = $response;
        $this->expect = $expect;
    }

    /**
     * @return bool
     */
    public function assert()
    {
        $body = json_decode((string)$this->response->getBody(), true);

        try {
            $searches = explode('.', $this->expect);
            return self::_assert($this->expect, $searches, $body);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param $pattern
     * @param $searches
     * @param $object
     *
     * @return boolean
     *
     * @throws \Exception
     */
    protected static function _assert($pattern, $searches, $object)
    {
        foreach ($searches as $idx => $col) {
            // .. の場合は、配列
            if ($col === '') {
               if (is_array($object)) {
                    $childSearches = array_slice($searches, $idx + 1);
                    foreach ($object as $child) {
                        self::_assert($pattern, $childSearches, $child);
                    }
                } else {
                    throw new \Exception('No pattern found:: ' . $pattern);
                }

                return true;
            }

            // 配列以外
            if (is_array($object) && array_key_exists($col, $object)) {
                $object = $object[$col];
            } else {
                throw new \Exception('No pattern found:: ' . $pattern);
            }
        }

        return true;
    }
}
