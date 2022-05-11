// Import all necessary Storefront plugins
import MultiSafepayTokenization from './multisafepay-tokenization/multisafepay-tokenization.plugin';
import MultiSafepayComponent from './multisafepay-component/multisafepay-component.plugin';

// Register the plugin via the existing PluginManager
const PluginManager = window.PluginManager
PluginManager.register('MultisafepayTokenization', MultiSafepayTokenization, '[data-multisafepay-tokenization]');
PluginManager.register('MultisafepayComponent', MultiSafepayComponent, '[data-multisafepay-component]');
