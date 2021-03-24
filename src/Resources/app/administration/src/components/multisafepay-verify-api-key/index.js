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
            }
            return this.$parent.$parent.$parent.$parent.actualConfigData.null;
        },
        actualPluginConfig() {
            let currentSalesChannelId = this.$parent.$parent.$parent.currentSalesChannelId;
            if (typeof currentSalesChannelId !== 'undefined') {
                return this.$parent.$parent.$parent.actualConfigData[currentSalesChannelId];
            }
            currentSalesChannelId = this.$parent.$parent.$parent.$parent.currentSalesChannelId;
            return this.$parent.$parent.$parent.$parent.actualConfigData[currentSalesChannelId];
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
