import ApiService from 'src/core/service/api.service';

export default class MultiSafepayApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'multisafepay')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    refund(amount, orderId)
    {
        const apiRoute = `${this.getApiBasePath()}/refund`;

        return this.httpClient.post(
            apiRoute,
            {
                amount: amount * 100,
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

    getRefundData(orderId)
    {
        const apiRoute = `${this.getApiBasePath()}/get-refund-data`;

        return this.httpClient.post(
            apiRoute,
            {
                orderId: orderId
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    verifyApiKey(globalPluginConfig, actualPluginConfig)
    {
        const apiRoute = `${this.getApiBasePath()}/verify-api-key`;
        const headers = this.getBasicHeaders()

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
}
