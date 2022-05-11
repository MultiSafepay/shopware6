import Plugin from 'src/plugin-system/plugin.class';
import StoreApiClient from 'src/service/store-api-client.service';

export default class multisafepayTokenization extends Plugin {

    static options = {
        activePaymentMethod: null,
        paymentMethods: null
    };

    init()
    {
        this._client = new StoreApiClient();
        this.fetchData();
    }

    fetchData()
    {
        const payload = JSON.stringify({paymentMethodId: this.options.activePaymentMethod.id, paymentMethods: this.options.paymentMethods});
        this._client.post(
            'store-api/v3/multisafepay/tokenization/tokens',
            payload,
            (response) => {
                var tokens = JSON.parse(response).tokens
                this.setupHtml(tokens)
            }
        );
    }

    setupHtml(tokens)
    {
        const multiSafepayCheckoutField = document.getElementById('multisafepay-checkout')

        var span = document.createElement('span');
        span.classList = 'custom-control custom-checkbox';


        var checkbox = document.createElement('input')
        checkbox.type = 'checkbox'
        checkbox.classList = 'custom-control-input'
        checkbox.id = 'saveToken'
        checkbox.name = 'saveToken'
        checkbox.setAttribute('form', 'confirmOrderForm')

        var label = document.createElement('label')
        label.htmlFor = 'saveToken'
        label.classList = 'custom-control-label'
        label.innerText = 'Save your credit card for next purchase'




        if (tokens.length !== 0) {
            span.style['display'] = 'none'
            //Create and append select list
            var selectList = document.createElement("select");
            selectList.id = "multisafepay-tokenization";
            selectList.classList = ['custom-select']
            selectList.style['margin-bottom'] = '2rem'
            selectList.setAttribute('name', 'active_token')
            selectList.setAttribute('form', 'confirmOrderForm')

            selectList.addEventListener('change', function (event) {
                if (selectList.value === '') {
                    span.style['display'] = 'block'
                } else {
                    span.style['display'] = 'none'
                }
            })

            let emptyOption = document.createElement("option")
            emptyOption.textContent = 'Use new payment details'
            emptyOption.value = '';
            selectList.appendChild(emptyOption);

            tokens.forEach((element, index) => {
                var option = document.createElement("option")
                option.textContent = element.name
                option.value = element.token;
                selectList.appendChild(option);

                if (index === 0) {
                    option.setAttribute('selected', 'selected')
                }
            })
        }

        span.appendChild(checkbox)
        span.appendChild(label)
        if (selectList !== undefined) {
            multiSafepayCheckoutField.appendChild(selectList);
        }
        multiSafepayCheckoutField.appendChild(span)
    }
}
