<?php

namespace Rorschach\Assert;

use GuzzleHttp\Psr7\Response;
use Rorschach\Parser;

class Value
{
    private $response;
    private $col;
    private $expect;

    /**
     * Value constructor.
     * @param Response $response
     * @param $col
     * @param $expect
     */
    public function __construct(Response $response, $col, $expect)
    {
        $this->response = $response;
        $this->col = $col;
        $this->expect = $expect;
    }

    /**
     * @return bool
     */
    public function assert()
    {
        $body = json_decode((string)$this->response->getBody(), true);

        try {
            $searches = explode('.', $this->col);
            return self::_assert($this->col, $searches, $this->expect, $body);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param $pattern
     * @param $searches
     * @param $object
     * @param $expect
     *
     * @return boolean
     *
     * @throws \Exception
     */
    protected static function _assert($pattern, $searches, $expect, $object)
    {
        foreach ($searches as $idx => $col) {
            // .. の場合は、配列
            if ($col === '') {
                if (is_array($object)) {
                    $childSearches = array_slice($searches, $idx + 1);
                    foreach ($object as $child) {
                        if (! self::_assert($pattern, $childSearches, $expect, $child)) {
                            return false;
                        }
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

        return ($expect == $object);
    }
}
