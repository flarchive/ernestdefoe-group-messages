import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';

const reg = flarum.reg;

// A quote waiting for the composer to open (see startReply for the same
// pending-target + placeholder-click pattern).
let pendingQuote = null;

/** Build a markdown blockquote of a message's plain content. */
function buildQuote(message) {
  const content = (message.contentPlain() || '').trim();
  if (!content) return '';
  return (
    content
      .split('\n')
      .map((line) => '> ' + line)
      .join('\n') + '\n\n'
  );
}

/** Insert text at the composer cursor once the editor is ready. */
function insertQuote(text) {
  if (!text) return;

  app.composer.editorReady().then(() => {
    if (!app.composer.editor) return;

    const cursor = app.composer.editor.getSelectionRange()[0];
    const preceding = (app.composer.fields.content() || '').slice(0, cursor);
    const lead = preceding.length === 0 ? '' : '\n\n';

    app.composer.editor.insertAtCursor(lead + text, false);
  });
}

/** Quote a message into the dialog's composer (frontend-only; no backend). */
export function quoteMessage(message) {
  const dialog = message.dialog();
  if (!dialog) return;

  const text = buildQuote(message);
  if (!text) return;

  if (app.composer.composingMessageTo(dialog)) {
    insertQuote(text);
  } else {
    pendingQuote = text;
    const placeholder = document.querySelector('.MessageStream .ReplyPlaceholder');
    if (placeholder) placeholder.click();
  }
}

export default function applyQuote() {
  reg.onLoad('flarum-messages', 'forum/components/MessageComposer', (MessageComposer) => {
    // Insert a pending quote once the composer opens for its dialog.
    extend(MessageComposer.prototype, 'oncreate', function () {
      if (!pendingQuote) return;
      const text = pendingQuote;
      pendingQuote = null;
      insertQuote(text);
    });
  });

  reg.onLoad('flarum-messages', 'forum/components/Message', (Message) => {
    extend(Message.prototype, 'footerItems', function (items) {
      const message = this.attrs.message;
      if (!message.dialog || !message.dialog()) return;

      items.add(
        'groupQuote',
        <Button className="Button Button--link Message-quote" icon="fas fa-quote-right" onclick={() => quoteMessage(message)}>
          {app.translator.trans('ernestdefoe-group-messages.forum.quote.quote_button')}
        </Button>,
        3
      );
    });
  });
}
