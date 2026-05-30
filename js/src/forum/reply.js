import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import username from 'flarum/common/helpers/username';
import ComposerState from 'flarum/forum/states/ComposerState';

const reg = flarum.reg;

// A reply target waiting for the composer to finish opening. Opening the
// message composer means loading two lazy chunks (ComposerBody, then
// MessageComposer) through flarum/messages' own webpack runtime — fiddly to
// drive from here. So instead we click the existing reply placeholder (which
// runs that proven import path) and let MessageComposer.oninit pick this up.
let pendingReply = null;

/**
 * Begin replying to a message: ensure the message composer is open for that
 * dialog with the target stashed on the composer state. The target rides along
 * as `replyToId` when the message is sent (see the data() extension) and drives
 * the "replying to" indicator in the composer header.
 */
export function startReply(message) {
  const dialog = message.dialog();
  if (!dialog) return;

  // Already composing to this dialog — just set the target.
  if (app.composer.composingMessageTo(dialog)) {
    app.composer.replyTo = message;
    m.redraw();
    return;
  }

  // Otherwise open the composer via the reply placeholder, then consume the
  // pending target in MessageComposer.oninit.
  pendingReply = message;
  const placeholder = document.querySelector('.MessageStream .ReplyPlaceholder');
  if (placeholder) placeholder.click();
}

/** The inline "in reply to …" reference shown above a replying message. */
function replyReference(message) {
  const replyToId = message.attribute('replyToId');
  if (!replyToId) return null;

  const target = app.store.getById('dialog-messages', String(replyToId));

  return (
    <div className="GroupReplyReference">
      <Icon name="fas fa-reply" />
      {target ? (
        <span className="GroupReplyReference-body">
          <span className="GroupReplyReference-author">{username(target.user())}</span>
          <span className="GroupReplyReference-snippet">{(target.contentPlain() || '').slice(0, 80)}</span>
        </span>
      ) : (
        <span className="GroupReplyReference-body GroupReplyReference-body--missing">
          {app.translator.trans('ernestdefoe-group-messages.forum.reply.unknown')}
        </span>
      )}
    </div>
  );
}

export default function applyReply() {
  // A freshly loaded composer body should never inherit a previous reply
  // target — clear it on every load(). startReply() re-sets it afterwards.
  extend(ComposerState.prototype, 'load', function () {
    this.replyTo = null;
  });

  reg.onLoad('flarum-messages', 'forum/components/MessageComposer', (MessageComposer) => {
    // Consume a pending reply target once the composer opens for its dialog.
    extend(MessageComposer.prototype, 'oninit', function () {
      const dialog = pendingReply && pendingReply.dialog();
      if (pendingReply && dialog && this.attrs.replyingTo && dialog.id() === this.attrs.replyingTo.id()) {
        this.composer.replyTo = pendingReply;
        pendingReply = null;
      }
    });

    // "Replying to {user}: …" indicator with a cancel control.
    extend(MessageComposer.prototype, 'headerItems', function (items) {
      const replyTo = this.composer.replyTo;
      if (!replyTo) return;

      items.add(
        'groupReplyingTo',
        <div className="GroupReplyingTo">
          <Icon name="fas fa-reply" />
          <span className="GroupReplyingTo-text">
            {app.translator.trans('ernestdefoe-group-messages.forum.reply.replying_to', { username: username(replyTo.user()) })}
            <span className="GroupReplyingTo-snippet">{(replyTo.contentPlain() || '').slice(0, 60)}</span>
          </span>
          <Button
            className="Button Button--icon Button--link GroupReplyingTo-cancel"
            icon="fas fa-times"
            aria-label={app.translator.trans('ernestdefoe-group-messages.forum.reply.cancel', {}, true)}
            onclick={() => {
              this.composer.replyTo = null;
              m.redraw();
            }}
          />
        </div>,
        50
      );
    });

    // Attach replyToId to the created message (server field is writableOnCreate).
    extend(MessageComposer.prototype, 'data', function (data) {
      const replyTo = this.composer.replyTo;
      const dialog = replyTo && replyTo.dialog();
      if (replyTo && dialog && this.attrs.replyingTo && dialog.id() === this.attrs.replyingTo.id()) {
        data.replyToId = Number(replyTo.id());
      }
    });
  });

  reg.onLoad('flarum-messages', 'forum/components/Message', (Message) => {
    // Reply affordance in the message footer, alongside the reactions bar
    // (priority just below GroupReactions so it sits right after the picker).
    extend(Message.prototype, 'footerItems', function (items) {
      const message = this.attrs.message;
      if (!message.dialog || !message.dialog()) return;

      items.add(
        'groupReply',
        <Button className="Button Button--link Message-reply" icon="fas fa-reply" onclick={() => startReply(message)}>
          {app.translator.trans('ernestdefoe-group-messages.forum.reply.reply_button')}
        </Button>,
        4
      );
    });

    // Prepend the "in reply to …" reference to a replying message's content.
    extend(Message.prototype, 'content', function (items) {
      const ref = replyReference(this.attrs.message);
      if (ref) items.unshift(ref);
    });
  });
}
