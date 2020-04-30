import { Component, Application } from 'src/core/shopware';
import './components/multisafepay-refund'
import template from './extension/sw-order-detail/sw-order-detail.html.twig'
import MultiSafepayApiService from './service/MultiSafepayApi.service'


Component.override('sw-order-detail-base', {
    template
});

Application.addServiceProvider('multiSafepayApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new MultiSafepayApiService(initContainer.httpClient, container.loginService);
});
