//This is needed to not show Apple Pay in other browser than Safari for shopware 6.4

import Plugin from 'src/plugin-system/plugin.class';

export default class multisafepayApplePay extends Plugin {

    init()
    {
        if ( document.readyState !== 'loading' ) {
            this.onDOMContentLoaded()
        } else {
            document.addEventListener('DOMContentLoaded', this.onDOMContentLoaded.bind(this))
        }
    }

    onDOMContentLoaded()
    {
        for (const paymentMethodId in this.options) {
            const paymentMethod = this.options[paymentMethodId];
            if (paymentMethod.formattedHandlerIdentifier === 'handler_multisafepay_applepaypaymenthandler') {
                var elementId = "paymentMethod" + paymentMethodId;
                var applePaySelectElement = document.getElementById(elementId).parentElement.parentElement.parentElement;
                applePaySelectElement.style.display = 'none';

                try {
                    if (window.ApplePaySession && window.ApplePaySession.canMakePayments()) {
                        applePaySelectElement.style.display = 'block';
                    }
                } catch (error) {
                    console.warn('MultiSafepay error when trying to initialize Apple Pay:', error);
                }
            }
        }
    }

}
