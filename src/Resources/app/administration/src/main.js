import './main.scss';
import './components/multisafepay-refund';
import './components/multisafepay-verify-api-key';
import './components/multisafepay-support';
import './paymentMethodSettings'
import './override/sw-order-detail-general';
import './override/sw-order-detail'
import MultiSafepayApiService from './service/MultiSafepayApi.service';
import localeDE from './snippets/de_DE.json';
import localeEN from './snippets/en_GB.json';
import localeNL from './snippets/nl_NL.json';

const { Application } = Shopware;

Application.addServiceProvider('multiSafepayApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new MultiSafepayApiService(initContainer.httpClient, container.loginService);
});

Shopware.Locale.extend('de-DE', localeDE);
Shopware.Locale.extend('en-GB', localeEN);
Shopware.Locale.extend('nl-NL', localeNL);
