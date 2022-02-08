<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GoogleAnalytics;
use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class GoogleAnalyticsBuilder implements OrderRequestBuilderInterface
{
    /** @var SettingsService */
    private $settingsService;

    /**
     * SecondsActiveBuilder constructor.
     *
     * @param SettingsService $settingsService
     */
    public function __construct(
        SettingsService $settingsService
    ) {
        $this->settingsService = $settingsService;
    }

    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        if (!$this->settingsService->getSetting('GoogleAnalytics', $salesChannelContext->getSalesChannelId())) {
            return;
        }

        $orderRequest->addGoogleAnalytics($this->getGoogleAnalyticsDetails($salesChannelContext));
    }

    private function getGoogleAnalyticsDetails(SalesChannelContext $salesChannelContext): GoogleAnalytics
    {
        $googleAnalytics = new GoogleAnalytics();

        return $googleAnalytics->addAccountId($this->settingsService->getSetting(
            'GoogleAnalytics',
            $salesChannelContext->getSalesChannelId()
        ));
    }
}
