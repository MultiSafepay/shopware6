// <plugin root>/src/Resources/app/storefront/build/webpack.config.js
const { join, resolve } = require('path');
module.exports = () => {
    return {
        resolve: {
            alias: {
                '@axios': resolve(
                    join(__dirname, '..', 'node_modules', 'axios')
                )
            }
        }
    };
}
