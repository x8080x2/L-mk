const { createMicrosoftProvider } = require('./microsoft');
const { createGoDaddyProvider } = require('./godaddy');

function createProviders(deps) {
    return [
        createGoDaddyProvider(deps),
        createMicrosoftProvider(deps)
    ];
}

module.exports = { createProviders };
