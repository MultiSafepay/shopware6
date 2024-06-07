// This file contains the HttpClient class, which is responsible for making API requests to the Shopware backend
export default class HttpClient {
    constructor() {
        // Access key and context token are retrieved from the window object
        this.accessKey = window.accessKey;
        this.contextToken = window.contextToken;
    }

    // This function makes a POST request to the specified URL with the provided payload
    post(url, payload = {}) {
        return fetch('/store-api/v3/multisafepay/tokenization/tokens', {
            method: 'POST',
            headers: this.getBasicHeaders(),
            body: JSON.stringify(payload)
        })
            .then(response => {
                // If the response is not OK, throw an error
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                // Otherwise, return the response as JSON
                return response.json();
            });
    }

    // This function returns the basic headers required for the API requests
    getBasicHeaders() {
        return {
            'Content-Type': 'application/json',
            'sw-access-key': this.accessKey,
            'sw-context-token': this.contextToken
        };
    }
}
