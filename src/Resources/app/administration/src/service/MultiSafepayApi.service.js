// Define the API service class
const ApiService = Shopware.Classes.ApiService;

// Define a new class that extends ApiService
export default class MultiSafepayApiService extends ApiService {
    // Constructor for the class
    constructor(httpClient, loginService, apiEndpoint = 'multisafepay')
    {
        // Call the parent class constructor
        super(httpClient, loginService, apiEndpoint);
    }

    // Method to refund a certain amount for a specific order
    refund(amount, orderId)
    {
        // Define the API route for refund
        const apiRoute = `${this.getApiBasePath()}/refund`;

        // Make a POST request to the refund API route
        return this.httpClient.post(
            apiRoute,
            {
                amount: amount * 100, // Convert the amount to cents
                orderId: orderId // The ID of the order to refund
            },
            {
                headers: this.getBasicHeaders() // Get the basic headers for the request
            }
        ).then((response) => {
            return ApiService.handleResponse(response); // Handle the response from the API
        }).catch((response) => {
            return ApiService.handleResponse(response); // Handle the error response from the API
        });
    }

    // Method to get refund data for a specific order
    getRefundData(orderId)
    {
        // Define the API route for getting refund data
        const apiRoute = `${this.getApiBasePath()}/get-refund-data`;

        // Make a POST request to the get refund data API route
        return this.httpClient.post(
            apiRoute,
            {
                orderId: orderId // The ID of the order to get refund data for
            },
            {
                headers: this.getBasicHeaders() // Get the basic headers for the request
            }
        ).then((response) => {
            return ApiService.handleResponse(response); // Handle the response from the API
        });
    }

    // Method to verify the API key
    verifyApiKey(globalPluginConfig, actualPluginConfig)
    {
        // Define the API route for verifying the API key
        const apiRoute = `${this.getApiBasePath()}/verify-api-key`;
        const headers = this.getBasicHeaders(); // Get the basic headers for the request

        // Make a POST request to the verify API key API route
        return this.httpClient.post(
            apiRoute,
            {
                globalPluginConfig: globalPluginConfig, // The global plugin configuration
                actualPluginConfig: actualPluginConfig // The actual plugin configuration
            },
            {
                headers
            }
        ).then((response) => {
            return ApiService.handleResponse(response); // Handle the response from the API
        });
    }

    // Method to check if tokenization is allowed for a specific payment method
    isTokenizationAllowed(paymentMethodId)
    {
        // Define the API route for checking if tokenization is allowed
        const apiRoute = `${this.getApiBasePath()}/tokenization-allowed`;

        // Make a POST request to the tokenization allowed API route
        return this.httpClient.post(
            apiRoute,
            {
                paymentMethodId: paymentMethodId // The ID of the payment method to check
            },
            {
                headers: this.getBasicHeaders() // Get the basic headers for the request
            }
        ).then((response) => {
            return ApiService.handleResponse(response); // Handle the response from the API
        }).catch((response) => {
            return ApiService.handleResponse(response); // Handle the error response from the API
        });
    }

    // Method to check if a component is allowed for a specific payment method
    isComponentAllowed(paymentMethodId)
    {
        // Define the API route for checking if a component is allowed
        const apiRoute = `${this.getApiBasePath()}/component-allowed`;

        // Make a POST request to the component allowed API route
        return this.httpClient.post(
            apiRoute,
            {
                paymentMethodId: paymentMethodId // The ID of the payment method to check
            },
            {
                headers: this.getBasicHeaders() // Get the basic headers for the request
            }
        ).then((response) => {
            return ApiService.handleResponse(response); // Handle the response from the API
        }).catch((response) => {
            return ApiService.handleResponse(response); // Handle the error response from the API
        });
    }
}
