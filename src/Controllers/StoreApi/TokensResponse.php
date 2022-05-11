<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Controllers\StoreApi;

use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class TokensResponse extends StoreApiResponse
{
    public function __construct(Struct $object)
    {
        parent::__construct($object);
    }
}
