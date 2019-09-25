<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\API;

use Exception;
use MultiSafepay\Shopware6\API\Object\Orders;
use MultiSafepay\Shopware6\API\Object\Issuers;

/**
 * @codeCoverageIgnore
 */
class MspClient
{
    public $orders;
    public $issuers;
    public $transactions;
    public $gateways;
    protected $api_key;
    public $api_url;
    public $api_endpoint;
    public $request;
    public $response;
    public $debug;

    /**
     * MspClient constructor.
     */
    public function __construct()
    {
        $this->orders = new Orders($this);
        $this->issuers = new Issuers($this);
    }

    /**
     * @return mixed
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param $url
     */
    public function setApiUrl($url): void
    {
        $this->api_url = trim($url);
    }

    /**
     * @param $debug
     */
    public function setDebug($debug): void
    {
        $this->debug = trim($debug);
    }

    /**
     * @param $api_key
     */
    public function setApiKey($api_key): void
    {
        $this->api_key = trim($api_key);
    }

    /**
     * @param $http_method
     * @param $api_method
     * @param null $http_body
     * @return bool|string
     * @throws \Exception
     */
    public function processAPIRequest($http_method, $api_method, $http_body = null)
    {
        if (empty($this->api_key)) {
            throw new \Exception('Please configure your MultiSafepay API Key.');
        }

        $url = $this->api_url . $api_method;
        $ch = curl_init($url);

        $request_headers = [
            'Accept: application/json',
            'api_key:' . $this->api_key,
        ];

        if ($http_body !== null) {
            $request_headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $http_body);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http_method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

        $body = curl_exec($ch);

        if ($this->debug) {
            $this->request = $http_body;
            $this->response = $body;
        }

        if (curl_errno($ch)) {
            throw new Exception("Unable to communicate with the 
            MultiSafepay payment server (" . curl_errno($ch) . "): " . curl_error($ch) . ".");
        }

        curl_close($ch);
        return $body;
    }
}
