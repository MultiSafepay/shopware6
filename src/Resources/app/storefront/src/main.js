// Import all necessary Storefront plugins

// MultiSafepayTokenization is used for handling tokenization of payment details
import MultiSafepayTokenization from './multisafepay-tokenization/multisafepay-tokenization.plugin';

// MultiSafepayComponent is a general component for MultiSafepay related functionalities
import MultiSafepayComponent from './multisafepay-component/multisafepay-component.plugin';

// MultiSafepayApplePay is used for handling Apple Pay transactions
import MultiSafepayApplePay from './multisafepay-apple-pay/multisafepay-apple-pay.plugin';

// Register the plugin via the existing 'PluginManager'
// 'PluginManager' is a global object provided by Shopware for managing plugins
const PluginManager = window.PluginManager

// Register the MultiSafepayTokenization plugin
//  It will be initialized on elements with the 'data-multisafepay-tokenization' attribute
PluginManager.register('MultisafepayTokenization', MultiSafepayTokenization, '[data-multisafepay-tokenization]');

// Register the MultiSafepayComponent plugin
// It will be initialized on elements with the 'data-multisafepay-component' attribute
PluginManager.register('MultisafepayComponent', MultiSafepayComponent, '[data-multisafepay-component]');

// Register the MultiSafepayApplePay plugin
// It will be initialized on elements with the 'data-multisafepay-apple-pay' attribute
PluginManager.register('MultisafepayApplePay', MultiSafepayApplePay, '[data-multisafepay-apple-pay]');
