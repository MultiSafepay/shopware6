// Import the Plugin class from the plugin system
import Plugin from 'src/plugin-system/plugin.class';
// Import the StoreApi service
import StoreApi from '../service/store-api.service';

// Define the multisafepayTokenization class, which extends the Plugin class
export default class multisafepayTokenization extends Plugin {

    // Define the default options for the plugin
    static options = {
        activePaymentMethod: null,
        paymentMethods: null
    };

    // Initialize the plugin
    init() {
        // Create a new instance of the StoreApi service
        this._client = new StoreApi();
        // Fetch data from the server
        this.fetchData();
    }

    // Fetch data from the server
    fetchData() {
        let self = this
        // Define the payload to send to the server
        const payload = JSON.stringify({
            paymentMethodId: this.options.activePaymentMethod.id,
            paymentMethods: this.options.paymentMethods
        });
        // Send a POST request to the server and handle the response
        this._client.post('/store-api/v3/multisafepay/tokenization/tokens', payload).then(function (response) {
            // Extract the tokens from the response data
            const tokens = response.data.tokens
            // Set up the HTML elements for the tokens
            self.setupHtml(tokens)
        })
    }

    // Set up the HTML elements for the tokens
    setupHtml(tokens) {
        // Get the checkout field element
        const multiSafepayCheckoutField = document.getElementById('multisafepay-checkout')

        // Create a span element to contain the checkbox
        const span = document.createElement('span');
        span.classList.add('custom-control', 'custom-checkbox');

        // Create a checkbox input element
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.classList.add('custom-control-input');
        checkbox.id = 'saveToken';
        checkbox.name = 'saveToken';
        checkbox.setAttribute('form', 'confirmOrderForm');

        // Create a label for the checkbox
        const label = document.createElement('label')
        label.htmlFor = 'saveToken';
        label.classList.add('custom-control-label');
        label.innerText = 'Save your credit card for next purchase';

        // If there are any tokens, create a select list for them
        let selectList;
        if (tokens.length !== 0) {
            // Hide the checkbox if there are any tokens
            span.style['display'] = 'none';
            // Create and append a select list
            selectList = document.createElement('select');
            selectList.id = 'multisafepay-tokenization';
            selectList.classList.add('custom-select');
            selectList.style['margin-bottom'] = '2rem';
            selectList.setAttribute('name', 'active_token');
            selectList.setAttribute('form', 'confirmOrderForm');

            // Add an event listener to the select list to show or hide the checkbox when the selected option changes
            selectList.addEventListener('change', function () {
                if (selectList.value === '') {
                    span.style['display'] = 'block'
                } else {
                    span.style['display'] = 'none'
                }
            })

            // Add an option to the select list for each token
            tokens.forEach((element, index) => {
                const option = document.createElement('option')
                option.textContent = element.name
                option.value = element.token;
                selectList.appendChild(option);

                // Select the first option by default
                if (index === 0) {
                    option.setAttribute('selected', 'selected')
                }
            })
        }

        // Add the checkbox and label to the span
        span.appendChild(checkbox)
        span.appendChild(label)
        // Add the select list and span to the checkout field
        if (selectList !== undefined) {
            multiSafepayCheckoutField.appendChild(selectList);
        }
        multiSafepayCheckoutField.appendChild(span)
    }
}
