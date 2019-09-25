<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\API\Object;

/**
 * @codeCoverageIgnore
 */
class Orders extends Core
{
    public $success;
    public $data;

    /**
     * @param array $body
     * @param string $endpoint
     * @return mixed
     * @throws \Exception
     */
    public function patch($body, $endpoint = '')
    {
        $result = parent::patch(json_encode($body), $endpoint);
        $this->success = $result->success;
        $this->data = $result->data;
        return $result;
    }

    /**
     * @param $type
     * @param $id
     * @param array $body
     * @param bool $queryString
     * @return mixed
     * @throws \Exception
     */
    public function get($type, $id, $body = [], $queryString = false)
    {
        $result = parent::get($type, $id, $body, $queryString);
        $this->success = $result->success;
        $this->data = $result->data;
        return $this->data;
    }

    /**
     * @param $body
     * @param string $endpoint
     * @return mixed
     * @throws \Exception
     */
    public function post($body, $endpoint = 'orders')
    {
        $result = parent::post(json_encode($body), $endpoint);
        $this->success = $result->success;
        $this->data = $result->data;
        return $this->data;
    }

    /**
     * @return mixed
     */
    public function getPaymentLink()
    {
        return $this->data->payment_url;
    }
}
