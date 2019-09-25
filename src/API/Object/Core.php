<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\API\Object;

use MultiSafepay\Shopware6\API\MspClient;

/**
 * @codeCoverageIgnore
 */
class Core
{
    protected $mspapi;
    public $result;

    /**
     * Core constructor.
     * @param MspClient $mspapi
     */
    public function __construct(MspClient $mspapi)
    {
        $this->mspapi = $mspapi;
    }

    /**
     * @param $body
     * @param string $endpoint
     * @return mixed
     * @throws \Exception
     */
    public function post($body, $endpoint = 'orders')
    {
        $this->result = $this->processRequest('POST', $endpoint, $body);
        return $this->result;
    }

    /**
     * @param $body
     * @param string $endpoint
     * @return mixed
     * @throws \Exception
     */
    public function patch($body, $endpoint = '')
    {
        $this->result = $this->processRequest('PATCH', $endpoint, $body);
        return $this->result;
    }

    /**
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @param $endpoint
     * @param $transactionId
     * @param array $body
     * @param bool $queryString
     * @return mixed
     * @throws \Exception
     */
    public function get($endpoint, $transactionId, $body = array(), $queryString = false)
    {
        if (!$queryString) {
            $url = "{$endpoint}/{$transactionId}";
        } else {
            $url = "{$endpoint}?{$queryString}";
        }

        $this->result = $this->processRequest('GET', $url, $body);
        return $this->result;
    }

    /**
     * @param $http_method
     * @param $api_method
     * @param null $http_body
     * @return mixed
     * @throws \Exception
     */
    protected function processRequest($http_method, $api_method, $http_body = null)
    {
        $body = $this->mspapi->processAPIRequest($http_method, $api_method, $http_body);
        if (!($object = json_decode($body))) {
            throw new \Exception("'{$body}'.");
        }

        return $object;
    }
}
