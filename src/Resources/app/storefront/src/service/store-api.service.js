import axios from 'axios'

export default class HttpClient {
    constructor() {
        this.accessKey = window.accessKey;
        this.contextToken = window.contextToken
    }

    post(url, payload = {}) {
        return axios.post('/store-api/v3/multisafepay/tokenization/tokens',
            payload,
            {
                headers: this.getBasicHeaders(),
            }).then(function (response) {
                return response
        })
    }

    getBasicHeaders(){
        return {
            'sw-access-key': this.accessKey,
            'sw-context-token': this.contextToken
        }
    }
}
