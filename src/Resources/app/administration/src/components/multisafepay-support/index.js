// Import the Component object from Shopware
const {Component} = Shopware;

// Import the SCSS file for this component
import './multisafepay-support.scss'

// Import the Twig template for this component
import template from './multisafepay-support.html.twig';

// Register the 'multisafepay-support' component with Shopware
Component.register('multisafepay-support', {
    // Define the template for this component
    template,
    
    // Add any data properties that might be needed
    data() {
        return {};
    },
    
    // Add created lifecycle method to ensure the component is properly initialized
    created() {
        // Making sure the component is visible
    },

    // Add mounted lifecycle hook to ensure visibility
    mounted() {
        // Find the template parent and force display:block
        const parentTemplate = this.$el.closest('template.sw-form-field-renderer');
        if (parentTemplate) {
            parentTemplate.style.display = 'block';
        }
        
        // Also try to find any parent elements that might be hidden
        let currentEl = this.$el;
        while (currentEl) {
            if (window.getComputedStyle(currentEl).display === 'none') {
                currentEl.style.display = 'block';
            }
            currentEl = currentEl.parentElement;
        }
    }
});
