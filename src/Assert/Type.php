<?php

namespace Rorschach\Assert;

use GuzzleHttp\Psr7\Response;
use Rorschach\Parser;

class Type
{
    private $response;
    private $col;
    private $expect;

    /**
     * Type constructor.
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
     * @return array
     *
     * @throws \Exception
     */
    public function assert()
    {
        $body = json_decode((string)$this->response->getBody(), true);

        $expects = explode('|', $this->expect);
        $nullable = in_array('nullable', $expects);

        if ($nullable) {
            if ($expects[0] == 'nullable') {
                $type = $expects[1];
            } else {
                $type = $expects[0];
            }
        } else {
            $type = $expects[0];
        }

        try {
            $searches = explode('.', $this->col);
            return self::_assert($this->col, $searches, $nullable, $type, $body);
        } catch (\Exception $e) {
            $errors = [$e->getMessage()];
            return $errors;
        }
    }

    /**
     * @param $pattern
     * @param $searches
     * @param $nullable
     * @param $type
     * @param $object
     * @param $errors
     *
     * @return array
     *
     * @throws \Exception
     */
    protected static function _assert($pattern, $searches, $nullable, $type, $object, $errors = [])
    {
        foreach ($searches as $idx => $col) {
            // .. の場合は、配列
            if ($col === '') {
                if (is_array($object)) {
                    $childSearches = array_slice($searches, $idx + 1);
                    foreach ($object as $child) {
                        $errors = self::_assert($pattern, $childSearches, $nullable, $type, $child, $errors);
                    }
                } else {
                    throw new \Exception('No pattern found:: ' . $pattern);
                }

                return $errors;
            }

            // 配列以外
            if (is_array($object) && array_key_exists($col, $object)) {
                $object = $object[$col];
            } else {
                throw new \Exception('No pattern found:: ' . $pattern);
            }
        }

        // if given nullable and value is null, skip.
        if ($nullable && is_null($object)) {
            return $errors;
        }

        $type = strtolower($type);

        switch ($type) {
            case 'str':
            case 'string':
                if (!is_string($object)) {
                    $errors[] = "{$pattern} is not string.";
                }
                break;

            case 'int':
            case 'integer':
                if (!is_int($object)) {
                    $errors[] = "{$pattern} is not integer.";
                }
                break;

            case 'double':
            case 'float':
                if (!is_float($object)) {
                    $errors[] = "{$pattern} is not float.";
                }
                break;

            case 'array':
                if (!is_array($object) || array_values($object) !== $object) {
                    $errors[] = "{$pattern} is not array.";
                }
                break;

            case 'obj':
            case 'object':
                if (!is_array($object) || array_values($object) === $object) {
                    $errors[] = "{$pattern} is not object.";
                }
                break;

            case 'bool':
            case 'boolean':
                if (!is_bool($object)) {
                    $errors[] = "{$pattern} is not boolean.";
                }
                break;

            default:
                throw new \Exception('Unknown type selected.');
        }

        return $errors;
    }
}
