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
});
