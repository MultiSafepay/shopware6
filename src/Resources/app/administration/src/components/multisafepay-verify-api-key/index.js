const {Component, Mixin} = Shopware;
const {Criteria} = Shopware.Data;
import template from './multisafepay-verify-api-key.html.twig';


Component.register('multisafepay-verify-api-key', {
    template,
    props: ['label'],
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
        pluginConfig() {
            return this.$parent.$parent.$parent.actualConfigData.null;
        }
    },
    methods: {
        check() {
            this.isLoading = true;

            this.multiSafepayApiService.verifyApiKey(this.pluginConfig).then((ApiResponse) => {
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
