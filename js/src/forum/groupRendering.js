import app from 'flarum/forum/app';
import { override, extend } from 'flarum/common/extend';
import classList from 'flarum/common/utils/classList';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import Button from 'flarum/common/components/Button';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/helpers/humanTime';
import highlight from 'flarum/common/helpers/highlight';

import GroupManageModal from './components/GroupManageModal';
import GroupReactions from './components/GroupReactions';

// flarum/messages' DialogListItem, DialogSection and MessageStream live in an
// async chunk (only DialogsDropdown is in the main bundle), so they're
// undefined at initializer time. reg.onLoad fires the handler immediately if
// the module is already registered, otherwise when its chunk loads — the
// correct seam for extending lazy components.
const reg = flarum.reg;

/** A circular group glyph: the chosen emoji, or a fallback people icon. */
export function groupIcon(dialog) {
  const emoji = (dialog.attribute('iconUrl') || '').trim();
  return (
    <span className="GroupMessages-icon">{emoji ? <span className="GroupMessages-icon-emoji">{emoji}</span> : <Icon name="fas fa-users" />}</span>
  );
}

export default function applyGroupRendering() {
  // ----- Dialog list item: group icon + group name instead of a recipient.
  reg.onLoad('flarum-messages', 'forum/components/DialogListItem', (DialogListItem) => {
    override(DialogListItem.prototype, 'view', function (original, vnode) {
      const dialog = this.attrs.dialog;
      if (dialog.type() !== 'group') return original(vnode);

      const unread = dialog.unreadCount();
      const actions = this.attrs.actions ? this.actionItems().toArray() : [];
      // flarum/messages 2.0 says when the last message is the reader's own.
      const lastMessage = dialog.lastMessage();
      const preview = this.preview ? this.preview() : lastMessage ? lastMessage.contentPlain()?.slice(0, 80) : '';

      return (
        <li
          className={classList('DialogListItem', 'DialogListItem--group', {
            'DialogListItem--unread': unread,
            'DialogListItem--actions': actions.length,
            active: this.attrs.active,
          })}
        >
          <Link href={app.route.dialog(dialog)} className={classList('DialogListItem-button', { active: this.attrs.active })}>
            <div className="DialogListItem-avatar">
              {groupIcon(dialog)}
              {!!unread && (
                <div className="Bubble Bubble--primary">
                  <span aria-hidden="true">{unread}</span>
                  <span className="sr-only">
                    {app.translator.trans('ernestdefoe-group-messages.forum.dialog.unread_count_text', { count: unread })}
                  </span>
                </div>
              )}
            </div>
            <div className="DialogListItem-content">
              <div className="DialogListItem-title">
                <span className="DialogListItem-name">{dialog.title()}</span>
                {humanTime(dialog.lastMessageAt())}
              </div>
              <div className="DialogListItem-lastMessage">{preview}</div>
            </div>
          </Link>
          {/* Beside the link, not inside it, as flarum/messages 2.0 has it: a button nested in a link is neither to a browser nor a screen reader. */}
          {!!actions.length && <div className="DialogListItem-actions">{actions}</div>}
        </li>
      );
    });
  });

  // ----- Conversation header: group icon, name, participant count; plus a
  // "Group settings" item in the "…" control menu.
  reg.onLoad('flarum-messages', 'forum/components/DialogSection', (DialogSection) => {
    override(DialogSection.prototype, 'view', function (original) {
      const dialog = this.attrs.dialog;
      if (dialog.type() !== 'group') return original();

      // MessageStream is in the same chunk as DialogSection, so it's loaded by
      // the time this renders — fetch it lazily rather than import it at init.
      const MessageStream = reg.get('flarum-messages', 'forum/components/MessageStream');
      const count = dialog.attribute('participantCount') || (dialog.users() || []).filter(Boolean).length;

      return (
        <div className="DialogSection">
          <div className="DialogSection-header DialogSection-header--group">
            {groupIcon(dialog)}
            <div className="DialogSection-header-info">
              <h2 className="DialogSection-header-info-title">{dialog.title()}</h2>
              <div className="DialogSection-header-info-helperText">
                {app.translator.trans('ernestdefoe-group-messages.forum.dialog.participant_count', { count })}
              </div>
            </div>
            <div className="DialogSection-header-actions">{this.actionItems().toArray()}</div>
          </div>
          {/* `near` opens a permalink on its message in flarum/messages 2.0; rc.8 reads the route itself. */}
          {MessageStream && <MessageStream dialog={dialog} state={this.messages} near={this.near} />}
        </div>
      );
    });

    extend(DialogSection.prototype, 'controlItems', function (items) {
      const dialog = this.attrs.dialog;
      if (dialog.type() !== 'group') return;

      items.add(
        'manageGroup',
        <Button icon="fas fa-users-cog" onclick={() => app.modal.show(GroupManageModal, { dialog })}>
          {app.translator.trans('ernestdefoe-group-messages.forum.manage.title')}
        </Button>,
        50
      );
    });
  });

  // ----- Global search (flarum/messages 2.0): a group message is in the
  // group, not a "conversation with" whichever member happens to be listed.
  reg.onLoad('flarum-messages', 'forum/components/MessageSearchResult', (MessageSearchResult) => {
    extend(MessageSearchResult.prototype, 'contentItems', function (items) {
      const message = this.attrs.message;
      const dialog = message.dialog();
      if (!dialog || dialog.type() !== 'group' || !items.has('text')) return;

      items.setContent(
        'text',
        <div className="MessageSearchResult-text">
          <div className="MessageSearchResult-title">
            <span className="MessageSearchResult-conversation">{dialog.title()}</span>
            {humanTime(message.createdAt())}
          </div>
          <div className="MessageSearchResult-excerpt">{highlight(message.contentPlain() ?? '', this.highlightRegExp(), 175)}</div>
        </div>
      );
    });
  });

  // ----- Reactions bar + "seen by" avatars under dialog messages.
  reg.onLoad('flarum-messages', 'forum/components/Message', (Message) => {
    extend(Message.prototype, 'footerItems', function (items) {
      items.add('groupReactions', <GroupReactions message={this.attrs.message} />, 5);
    });

    // Read receipts: small avatars on a group's last message for the other
    // participants who have read up to it (from the dialog's seenBy field).
    extend(Message.prototype, 'footerItems', function (items) {
      const message = this.attrs.message;
      const dialog = message.dialog && message.dialog();
      if (!dialog || dialog.type() !== 'group') return;
      if (Number(message.id()) !== Number(dialog.lastMessageId())) return;

      const selfId = app.session.user && app.session.user.id();
      const ids = (dialog.attribute('lastMessageSeenByIds') || []).filter((id) => String(id) !== String(selfId));
      const users = ids.map((id) => app.store.getById('users', String(id))).filter(Boolean);
      if (!users.length) return;

      const names = users.map((u) => u.username()).join(', ');

      items.add(
        'groupSeenBy',
        <div className="GroupSeenBy" title={app.translator.trans('ernestdefoe-group-messages.forum.dialog.seen_by', { names }, true)}>
          {users.map((u) => (
            <Avatar user={u} />
          ))}
        </div>,
        -10
      );
    });
  });
}
