import Plugin from 'src/plugin-system/plugin.class';

export default class multisafepayComponent extends Plugin {

    static options = {
        tokens: null,
        env: 'test',
        apiToken: null,
        currency: 'EUR',
        amount: null,
        gateway: 'CREDITCARD',
        country: 'NL',
        locale: null,
        customerId: null,
        showTokenization: false
    };

    init()
    {
        if ( document.readyState !== 'loading' ) {
            this.onDOMContentLoaded()
        } else {
            document.addEventListener('DOMContentLoaded', this.onDOMContentLoaded.bind(this))
        }

        let self = this;

        document.getElementById('confirmOrderForm').querySelector('button').addEventListener('click', this.onSubmitClick.bind(this))
        document.getElementById('confirmOrderForm').addEventListener('submit', this.onSubmit.bind(this));
    }

    onDOMContentLoaded()
    {
        var multisafepayOptions = {
            debug: false,
            env: this.options.env,
            apiToken: this.options.apiToken,
            order: {
                customer: {
                    locale: this.options.locale,
                    country: this.options.country,
                },
                currency: this.options.currency,
                amount: this.options.amount,
                template: {
                    settings: {
                        embed_mode: true
                    }
                }
            },
            recurring:{model: "cardOnFile", tokens: this.options.tokens},
        }

        if (this.options.showTokenization) {
            multisafepayOptions.order.recurring = {model: 'cardOnFile'}
        }

        this.multiSafepay = new MultiSafepay(multisafepayOptions);

        this.multiSafepay.init('payment', {
            container: '#multisafepay-checkout',
            gateway: this.options.gateway,
        });
    }

    onSubmitClick()
    {
        if (this.multiSafepay.getErrors().count > 0) {
            event.preventDefault();
            return false;
        }
        document.getElementById('multisafepay-payload').value = this.multiSafepay.getPaymentData().payload;
        document.getElementById('multisafepay-tokenize').value = this.multiSafepay.getPaymentData().tokenize;
        return true;
    }

    onSubmit()
    {
        if (this.multiSafepay.getErrors().count > 0) {
            event.preventDefault();
            return false;
        }
        document.getElementById('multisafepay-payload').value = this.multiSafepay.getPaymentData().payload;
        return true;
    }
}
