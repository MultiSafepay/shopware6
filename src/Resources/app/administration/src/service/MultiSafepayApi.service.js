const ApiService = Shopware.Classes.ApiService;

export default class MultiSafepayApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'multisafepay')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    refund(amount, orderId)
    {
        const apiRoute = `${this.getApiBasePath()}/refund`;

        // Send both the normalized decimal and cents so PHP can avoid float ambiguity.
        const numericAmount = typeof amount === 'string' ? Number(amount.replace(',', '.')) : Number(amount);
        const amountInCents = Number.isFinite(numericAmount)
            ? Math.round((numericAmount + Number.EPSILON) * 100)
            : 0;

        return this.httpClient.post(
            apiRoute,
            {
                amount: Number.isFinite(numericAmount) ? numericAmount.toFixed(2) : amount,
                amountInCents,
                orderId: orderId
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        }).catch((response) => {
            return ApiService.handleResponse(response);
        });
    }

    getRefundData(orderId, forceRefresh = false)
    {
        const apiRoute = `${this.getApiBasePath()}/get-refund-data`;

        // The refund block can bypass the short PSP cache after a new refund is created.
        return this.httpClient.post(
            apiRoute,
            {
                orderId: orderId,
                forceRefresh: Boolean(forceRefresh)
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    dismissReturnManagementRefundError(orderId, error)
    {
        const apiRoute = `${this.getApiBasePath()}/dismiss-return-management-refund-error`;

        // Dismissals are matched by amount fingerprints, not by translated UI copy.
        return this.httpClient.post(
            apiRoute,
            {
                orderId: orderId,
                amounts: error?.amounts || null
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        }).catch((response) => {
            return ApiService.handleResponse(response);
        });
    }

    verifyApiKey(globalPluginConfig, actualPluginConfig)
    {
        const apiRoute = `${this.getApiBasePath()}/verify-api-key`;
        const headers = this.getBasicHeaders();

        // Compare the persisted global config with the current form values before saving.
        return this.httpClient.post(
            apiRoute,
            {
                globalPluginConfig: globalPluginConfig,
                actualPluginConfig: actualPluginConfig
            },
            {
                headers
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    isTokenizationAllowed(paymentMethodId)
    {
        const apiRoute = `${this.getApiBasePath()}/tokenization-allowed`;

        // Payment-method settings use this lightweight check before showing tokenization fields.
        return this.httpClient.post(
            apiRoute,
            {
                paymentMethodId: paymentMethodId
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        }).catch((response) => {
            return ApiService.handleResponse(response);
        });
    }

    isComponentAllowed(paymentMethodId)
    {
        const apiRoute = `${this.getApiBasePath()}/component-allowed`;

        // Components support is payment-method specific, so the admin checks it on demand.
        return this.httpClient.post(
            apiRoute,
            {
                paymentMethodId: paymentMethodId
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

    // Method to check if manual capture is allowed for a specific payment method
    isManualCaptureAllowed(paymentMethodId)
    {
        // Define the API route for checking if manual capture is allowed
        const apiRoute = `${this.getApiBasePath()}/manual-capture-allowed`;

        // Make a POST request to the manual capture allowed API route
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
    
    isReturnManagementAvailable()
    {
        const apiRoute = `${this.getApiBasePath()}/return-management-available`;
        
        // The settings card is only useful when Shopware Return Management is available at runtime.
        return this.httpClient.get(
                                   apiRoute,
                                   {
                                   headers: this.getBasicHeaders()
                                   }
                                   ).then((response) => {
                                          return ApiService.handleResponse(response);
                                          }).catch((response) => {
                                                   return ApiService.handleResponse(response);
                                                   });
    }
}
