import './components/multisafepay-refund';
import './components/multisafepay-verify-api-key';
import './components/multisafepay-support';
import './paymentMethodSettings'
import template from './extension/sw-order-detail/sw-order-detail.html.twig';
import MultiSafepayApiService from './service/MultiSafepayApi.service';
import localeDE from './snippets/de_DE.json';
import localeEN from './snippets/en_GB.json';
import localeNL from './snippets/nl_NL.json';

const { Component, Application } = Shopware;


Component.override('sw-order-detail-base', {
    template
});

Application.addServiceProvider('multiSafepayApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new MultiSafepayApiService(initContainer.httpClient, container.loginService);
});

Shopware.Locale.extend('de-DE', localeDE);
Shopware.Locale.extend('en-GB', localeEN);
Shopware.Locale.extend('nl-NL', localeNL);
