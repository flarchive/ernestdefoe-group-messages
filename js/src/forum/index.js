import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import DialogsDropdown from 'ext:flarum/messages/forum/components/DialogsDropdown';

import GroupComposeModal from './components/GroupComposeModal';
import applyGroupRendering from './groupRendering';
import applyReply from './reply';
import applyQuote from './quote';

// Group Messages — forum frontend. Phase 4 (in progress): the group compose
// flow. Further slices add group-aware list/header rendering, participant
// management, reactions, reply/quote, and "seen by".
//
// DialogsDropdown lives in flarum/messages' MAIN bundle (not an async chunk),
// so it's safe to extend at initializer time — unlike MessagesPageHero.
app.initializers.add('ernestdefoe-group-messages', () => {
  if (!('flarum-messages' in flarum.extensions) || !DialogsDropdown) return;

  // Group-aware dialog list item, conversation header, and "…" control menu.
  applyGroupRendering();

  // Reply: action, composer indicator, and inline reference rendering.
  applyReply();

  // Quote: insert a markdown blockquote of a message into the composer.
  applyQuote();

  // Prepend a "New group message" action to the messages dropdown.
  override(DialogsDropdown.prototype, 'getContent', function (original) {
    return [
      <div className="DialogsDropdown-newGroup">
        <Button
          className="Button Button--block Button--link hasIcon"
          icon="fas fa-users"
          onclick={() => app.modal.show(GroupComposeModal)}
        >
          {app.translator.trans('ernestdefoe-group-messages.forum.compose.group_button')}
        </Button>
      </div>,
      original(),
    ];
  });
});
