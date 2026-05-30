import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Dropdown from 'flarum/common/components/Dropdown';

// A small fixed palette keeps the picker dependency-free (no reliance on the
// emoji extension) while covering the common reactions.
const PRESET = ['👍', '❤️', '😂', '😮', '😢', '🎉'];

/**
 * The reactions bar shown under a dialog message: a pill per distinct
 * reaction (emoji + count, highlighted when the actor reacted) plus an
 * "add reaction" button with a small emoji palette.
 *
 * This lives inside an AbstractPost, whose subtree is frozen by a
 * SubtreeRetainer (it only re-renders when loading/freshness change). So the
 * picker uses Flarum's Dropdown — which opens via a CSS class toggled in the
 * DOM, not a Mithril redraw — and the pill counts update because pushing the
 * refreshed message into the store bumps `message.freshness`, which the
 * retainer watches.
 */
export default class GroupReactions extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.message = this.attrs.message;
    this.busy = false;
  }

  view() {
    const reactions = this.message.attribute('reactions') || [];
    const canReact = !!app.session.user;

    if (!reactions.length && !canReact) return null;

    return (
      <div className="GroupReactions">
        {reactions.map((r) => (
          <button
            type="button"
            className={'GroupReactions-pill' + (r.mine ? ' GroupReactions-pill--mine' : '')}
            disabled={this.busy}
            onclick={() => (r.mine ? this.send('unreact', r.reaction) : this.send('react', r.reaction))}
          >
            <span className="GroupReactions-emoji">{r.reaction}</span>
            <span className="GroupReactions-count">{r.count}</span>
          </button>
        ))}

        {canReact && (
          <Dropdown
            className="GroupReactions-add"
            buttonClassName="Button Button--icon Button--flat GroupReactions-addButton"
            menuClassName="GroupReactions-picker"
            icon="far fa-face-smile"
            caretIcon={null}
            accessibleToggleLabel={app.translator.trans('ernestdefoe-group-messages.forum.reactions.add_label', {}, true)}
          >
            {PRESET.map((emoji) => (
              <button type="button" className="GroupReactions-pickerEmoji" onclick={() => this.react(emoji)}>
                {emoji}
              </button>
            ))}
          </Dropdown>
        )}
      </div>
    );
  }

  react(emoji) {
    const existing = (this.message.attribute('reactions') || []).find((r) => r.reaction === emoji);
    if (existing && existing.mine) return; // already reacted with this emoji
    return this.send('react', emoji);
  }

  send(action, emoji) {
    this.busy = true;
    return app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/dialog-messages/' + this.message.id() + '/' + action,
        body: { data: { attributes: { reaction: emoji } } },
      })
      .then((payload) => app.store.pushPayload(payload))
      .catch(() => app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-group-messages.lib.error.generic')))
      .then(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
