// Import the template from the specified file
import template from './sw-order-detail-general.html.twig';

// In this case, we are overriding the 'sw-order-detail-general' component
Shopware.Component.override('sw-order-detail-general', {
    // Assign the imported template to the template property of the component
    // This will replace the default template of the component with our custom one
    template
});
