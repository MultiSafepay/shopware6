import template from './sw-order-general-info.html.twig';

Shopware.Component.override('sw-order-general-info', {
    template,

    computed: {
        paymentMethodDisplay() {
            if (this.transaction
                && this.transaction.customFields
                && this.transaction.customFields.multisafepay_payment_method_display_admin
            ) {
                return this.transaction.customFields.multisafepay_payment_method_display_admin;
            }

            if (this.transaction
                && this.transaction.customFields
                && this.transaction.customFields.multisafepay_payment_method_display
            ) {
                return this.transaction.customFields.multisafepay_payment_method_display;
            }

            if (this.order
                && this.order.transactions
                && this.order.transactions.length > 0
            ) {
                const lastTransaction = this.order.transactions[this.order.transactions.length - 1];

                if (lastTransaction
                    && lastTransaction.customFields
                    && lastTransaction.customFields.multisafepay_payment_method_display_admin
                ) {
                    return lastTransaction.customFields.multisafepay_payment_method_display_admin;
                }

                if (lastTransaction
                    && lastTransaction.customFields
                    && lastTransaction.customFields.multisafepay_payment_method_display
                ) {
                    return lastTransaction.customFields.multisafepay_payment_method_display;
                }
            }

            if (this.transaction
                && this.transaction.paymentMethod
                && this.transaction.paymentMethod.translated
                && this.transaction.paymentMethod.translated.distinguishableName
            ) {
                return this.transaction.paymentMethod.translated.distinguishableName;
            }

            if (this.transaction
                && this.transaction.paymentMethod
                && this.transaction.paymentMethod.translated
                && this.transaction.paymentMethod.translated.name
            ) {
                return this.transaction.paymentMethod.translated.name;
            }

            if (this.transaction
                && this.transaction.paymentMethod
                && this.transaction.paymentMethod.name
            ) {
                return this.transaction.paymentMethod.name;
            }

            if (this.order
                && this.order.transactions
                && this.order.transactions.length > 0
            ) {
                const lastTransaction = this.order.transactions[this.order.transactions.length - 1];

                if (lastTransaction && lastTransaction.paymentMethod) {
                    if (lastTransaction.paymentMethod.translated
                        && lastTransaction.paymentMethod.translated.distinguishableName
                    ) {
                        return lastTransaction.paymentMethod.translated.distinguishableName;
                    }

                    if (lastTransaction.paymentMethod.translated
                        && lastTransaction.paymentMethod.translated.name
                    ) {
                        return lastTransaction.paymentMethod.translated.name;
                    }

                    if (lastTransaction.paymentMethod.name) {
                        return lastTransaction.paymentMethod.name;
                    }
                }
            }

            return '';
        },
    },
});
