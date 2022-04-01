const {Component, Mixin} = Shopware;
import template from './multisafepay-verify-api-key.html.twig';


Component.register('multisafepay-verify-api-key', {
    template,
    inject: [
        'multiSafepayApiService'
    ],
    mixins: [
        Mixin.getByName('notification')
    ],
    data() {
        return {
            isLoading: false,
        };
    },
    computed: {
        globalPluginConfig() {
            let config = this.$parent.$parent.$parent.actualConfigData;
            if (config) {
                return config.null;
            } else if (this.$parent.$parent.$parent.$parent.actualConfigData) {
                return this.$parent.$parent.$parent.$parent.actualConfigData.null;
            } else if (this.$parent.$parent.$parent.$parent.$parent.actualConfigData) {
                //Since 6.4.9.0
                return this.$parent.$parent.$parent.$parent.$parent.actualConfigData.null
            }

        },
        actualPluginConfig() {
            if (this.$parent.$parent.$parent.currentSalesChannelId) {
                let currentSalesChannelId = this.$parent.$parent.$parent.currentSalesChannelId;
                return this.$parent.$parent.$parent.actualConfigData[currentSalesChannelId];
            } else if (this.$parent.$parent.$parent.$parent.currentSalesChannelId) {
                let currentSalesChannelId = this.$parent.$parent.$parent.$parent.currentSalesChannelId;
                return this.$parent.$parent.$parent.$parent.actualConfigData[currentSalesChannelId];
            } else if (this.$parent.$parent.$parent.$parent.$parent.currentSalesChannelId) {
                //Since 6.4.9.0
                let currentSalesChannelId = this.$parent.$parent.$parent.$parent.$parent.currentSalesChannelId;
                return this.$parent.$parent.$parent.$parent.$parent.actualConfigData[currentSalesChannelId];
            } else {
                return null
            }
        }
    },
    methods: {
        check() {
            this.isLoading = true;

            this.multiSafepayApiService.verifyApiKey(this.globalPluginConfig, this.actualPluginConfig).then((ApiResponse) => {
                if (ApiResponse.success === false) {
                    this.createNotificationWarning({
                        title: 'MultiSafepay',
                        message: this.$tc('multisafepay-verify-api-key.error')
                    })
                    this.isLoading = false;
                    return;
                }
                this.createNotificationSuccess({
                    title: 'MultiSafepay',
                    message: this.$tc('multisafepay-verify-api-key.success')
                });
                this.isLoading = false;
            });
        },
    }
});
