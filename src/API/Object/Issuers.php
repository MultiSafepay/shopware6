<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\API\Object;

/**
 * @codeCoverageIgnore
 */
class Issuers extends Core
{
    public $success;
    public $data;

    /**
     * @param string $endpoint
     * @param string $type
     * @param array $body
     * @param bool $queryString
     * @return mixed
     * @throws \Exception
     */
    public function get($endpoint = 'issuers', $type = 'ideal', $body = array(), $queryString = false)
    {
        $result = parent::get($endpoint, $type, $body, $queryString);
        $this->success = $result->success;
        $this->data = $result->data;

        return $this->data;
    }
}
