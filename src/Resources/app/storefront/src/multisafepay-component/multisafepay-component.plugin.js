// Import the Plugin class from the plugin system
import Plugin from 'src/plugin-system/plugin.class';

// Define the multisafepayComponent class that extends the Plugin class
export default class multisafepayComponent extends Plugin {

    // Define the default options for the multisafepayComponent
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
        showTokenization: false,
        template_id: null
    };

    // Initialize the multisafepayComponent
    init()
    {
        // Check if the document is ready
        if ( document.readyState !== 'loading' ) {
            this.onDOMContentLoaded()
        } else {
            // If the document is not ready, add an event listener for the DOMContentLoaded event
            document.addEventListener('DOMContentLoaded', this.onDOMContentLoaded.bind(this))
        }

        // Add event listeners for the click and submit events of the confirmOrderForm
        document.getElementById('confirmOrderForm').querySelector('button').addEventListener('click', this.onSubmitClick.bind(this))
        document.getElementById('confirmOrderForm').addEventListener('submit', this.onSubmit.bind(this));
    }

    // Function to be called when the DOMContentLoaded event is fired
    onDOMContentLoaded()
    {
        // Define the options for the MultiSafepay instance
        const multisafepayOptions = {
            debug: false,
            env: this.options.env,
            apiToken: this.options.apiToken,
            order: {
                payment_options: {
                    template_id: this.options.template_id
                },
                customer: {
                    locale: this.options.locale,
                    country: this.options.country
                },
                currency: this.options.currency,
                amount: this.options.amount
            },
            recurring: this.options.showTokenization ? {model: 'cardOnFile', tokens: this.options.tokens} : undefined
        }

        // Initialize the MultiSafepay instance
        this.multiSafepay = new MultiSafepay(multisafepayOptions);

        // Initialize the payment with the MultiSafepay instance
        this.multiSafepay.init('payment', {
            container: '#multisafepay-checkout',
            gateway: this.options.gateway
        });
    }

    // Function to be called when the 'submit' button is clicked
    onSubmitClick(event)
    {
        // Check if there are any errors with the MultiSafepay instance
        if (this.multiSafepay.getErrors().count > 0) {
            event.preventDefault();
            return false;
        }
        // Set the value of the multisafepay-payload and multisafepay-tokenize inputs
        document.getElementById('multisafepay-payload').value = this.multiSafepay.getPaymentData().payload || '';
        document.getElementById('multisafepay-tokenize').value = this.multiSafepay.getPaymentData().tokenize || false;
        return true;
    }

    // Function to be called when the form is submitted
    onSubmit(event)
    {
        // Check if there are any errors with the MultiSafepay instance
        if (this.multiSafepay.getErrors().count > 0) {
            event.preventDefault();
            return false;
        }
        // Set the value of the multisafepay-payload input
        document.getElementById('multisafepay-payload').value = this.multiSafepay.getPaymentData().payload || '';
        return true;
    }
}
