// Import the template from the specified file
import template from './sw-order-detail.html.twig';

// In this case, we are overriding the 'sw-order-detail-base' component
Shopware.Component.override('sw-order-detail-base', {
    // Assign the imported template to the template property of the component
    // This will replace the default template of the component with our custom one
    template,
    created() {
        this.$super('created');
        if (this.$root && typeof this.$root.$on === 'function') {
            this.$root.$on('multisafepay-refund-order-updated', this.onMultiSafepayOrderUpdated);
        }
    },
    beforeDestroy() {
        this.$super('beforeDestroy');
        if (this.$root && typeof this.$root.$off === 'function') {
            this.$root.$off('multisafepay-refund-order-updated', this.onMultiSafepayOrderUpdated);
        }
    },
    methods: {
        onMultiSafepayOrderUpdated(order) {
            if (order && this.order && order.id === this.order.id) {
                this.order = order;
            }
        }
    }
});
