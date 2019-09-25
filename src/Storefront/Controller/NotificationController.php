<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class NotificationController extends StorefrontController
{
    /**
     * @Route("/multisafepay/notification",
     *     name="frontend.multisafepay.notification",
     *     options={"seo"="false"}, methods={"GET"}
     *     )
     * @return Response
     */
    public function notification(): Response
    {
        $response = new Response();
        return $response->setContent('OK');
    }
}
