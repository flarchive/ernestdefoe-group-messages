const config = require('flarum-webpack-config');

// useExtensions externalises flarum/messages' modules so we can import its
// components (DialogListItem, MessagesPageHero, Message, ...) at runtime via
// Flarum's module registry instead of bundling them.
module.exports = config({ useExtensions: ['flarum/messages'] });
