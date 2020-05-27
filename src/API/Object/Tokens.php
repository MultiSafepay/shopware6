<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\API\Object;

/**
 * @codeCoverageIgnore
 */
class Tokens extends Core
{
    public $success;
    public $data;
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
}
