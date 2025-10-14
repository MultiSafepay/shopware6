// Import all necessary Storefront plugins

// MultiSafepayComponent is a general component for MultiSafepay related functionalities
import MultiSafepayComponent from './multisafepay-component/multisafepay-component.plugin';

// MultiSafepayApplePay is used for handling Apple Pay transactions
import MultiSafepayApplePay from './multisafepay-apple-pay/multisafepay-apple-pay.plugin';

// Inject accessibility styles for EAA 2025 compliance
(function() {
    if (!document.getElementById('multisafepay-accessibility-styles')) {
        const style = document.createElement('style');
        style.id = 'multisafepay-accessibility-styles';
        style.textContent = `
            /* MultiSafepay Plugin - Accessibility Styles - EAA 2025 Compliance */
            .sr-only {
                position: absolute !important;
                width: 1px !important;
                height: 1px !important;
                padding: 0 !important;
                margin: -1px !important;
                overflow: hidden !important;
                clip: rect(0, 0, 0, 0) !important;
                white-space: nowrap !important;
                border: 0 !important;
            }
        `;
        document.head.appendChild(style);
    }
})();

// Register the plugin via the existing 'PluginManager'
// 'PluginManager' is a global object provided by Shopware for managing plugins
const PluginManager = window.PluginManager;

// Register the MultiSafepayComponent plugin
// It will be initialized on elements with the 'data-multisafepay-component' attribute
PluginManager.register('MultisafepayComponent', MultiSafepayComponent, '[data-multisafepay-component]');

// Register the MultiSafepayApplePay plugin
// It will be initialized on elements with the 'data-multisafepay-apple-pay' attribute
PluginManager.register('MultisafepayApplePay', MultiSafepayApplePay, '[data-multisafepay-apple-pay]');
