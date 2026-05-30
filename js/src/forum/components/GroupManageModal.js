import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Avatar from 'flarum/common/components/Avatar';
import Stream from 'flarum/common/utils/Stream';
import username from 'flarum/common/helpers/username';
import extractText from 'flarum/common/utils/extractText';
import UserSelectionModal from 'flarum/common/components/UserSelectionModal';

import { groupIcon } from '../groupRendering';

/**
 * Manage a group conversation: rename + re-icon (managers), add/remove
 * participants (managers), promote/demote moderators (owner), and leave
 * (anyone). All controls are gated on the actor's role, which the server
 * reports via the dialog's `actorRole` / `roles` fields. Each action hits the
 * matching /dialogs/{id}/… endpoint, then reloads the dialog (with users) so
 * the list re-renders.
 */
export default class GroupManageModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.dialog = this.attrs.dialog;
    this.name = Stream(this.dialog.title() || '');
    this.icon = Stream(this.dialog.attribute('iconUrl') || '');
    this.saving = false;
    this.busy = false; // a participant/role action is in flight
  }

  className() {
    return 'GroupManageModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-group-messages.forum.manage.title');
  }

  actorRole() {
    return this.dialog.attribute('actorRole');
  }
  isOwner() {
    return this.actorRole() === 'owner';
  }
  isManager() {
    return this.isOwner() || this.actorRole() === 'moderator';
  }
  roleOf(user) {
    return (this.dialog.attribute('roles') || {})[String(user.id())] || 'member';
  }

  apiUrl() {
    return app.forum.attribute('apiUrl') + '/dialogs/' + this.dialog.id();
  }

  content() {
    const participants = (this.dialog.users() || []).filter(Boolean);

    return (
      <div className="Modal-body">
        {this.isManager() && (
          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-group-messages.forum.manage.name_label')}</label>
            <div className="GroupManageModal-nameRow">
              <input className="FormControl GroupManageModal-icon" maxlength="8" placeholder="🙂" bidi={this.icon} />
              <input
                className="FormControl"
                maxlength="150"
                bidi={this.name}
                placeholder={extractText(app.translator.trans('ernestdefoe-group-messages.forum.compose.name_placeholder'))}
              />
              <Button className="Button Button--primary" loading={this.saving} disabled={this.busy} onclick={() => this.saveSettings()}>
                {app.translator.trans('ernestdefoe-group-messages.forum.manage.save_button')}
              </Button>
            </div>
          </div>
        )}

        <div className="Form-group">
          <label>{app.translator.trans('ernestdefoe-group-messages.forum.manage.participants_label', { count: participants.length })}</label>
          <div className="GroupManageModal-participants">{participants.map((user) => this.participantRow(user))}</div>
          {this.isManager() && (
            <Button className="Button GroupManageModal-add" icon="fas fa-user-plus" disabled={this.busy} onclick={() => this.addPeople()}>
              {app.translator.trans('ernestdefoe-group-messages.forum.manage.add_people')}
            </Button>
          )}
        </div>

        <div className="Form-group">
          <Button className="Button Button--danger Button--block" icon="fas fa-sign-out-alt" disabled={this.busy} onclick={() => this.leave()}>
            {app.translator.trans('ernestdefoe-group-messages.forum.manage.leave_button')}
          </Button>
        </div>
      </div>
    );
  }

  participantRow(user) {
    const role = this.roleOf(user);
    const isSelf = user.id() === app.session.user.id();

    return (
      <div className="GroupManageModal-participant">
        <Avatar user={user} />
        <span className="GroupManageModal-participant-name">{username(user)}</span>
        <span className={'GroupManageModal-role GroupManageModal-role--' + role}>
          {app.translator.trans('ernestdefoe-group-messages.forum.manage.role_' + role)}
        </span>
        <div className="GroupManageModal-participant-actions">
          {this.isOwner() &&
            !isSelf &&
            role !== 'owner' &&
            (role === 'moderator' ? (
              <Button
                className="Button Button--icon Button--link"
                icon="fas fa-arrow-down"
                disabled={this.busy}
                title={extractText(app.translator.trans('ernestdefoe-group-messages.forum.manage.demote_tooltip'))}
                onclick={() => this.setModerator(user, false)}
              />
            ) : (
              <Button
                className="Button Button--icon Button--link"
                icon="fas fa-arrow-up"
                disabled={this.busy}
                title={extractText(app.translator.trans('ernestdefoe-group-messages.forum.manage.promote_tooltip'))}
                onclick={() => this.setModerator(user, true)}
              />
            ))}
          {this.canRemove(user) && (
            <Button
              className="Button Button--icon Button--link"
              icon="fas fa-times"
              disabled={this.busy}
              title={extractText(app.translator.trans('ernestdefoe-group-messages.forum.manage.remove_tooltip'))}
              onclick={() => this.remove(user)}
            />
          )}
        </div>
      </div>
    );
  }

  /** Members are removable by any manager; moderators only by the owner; the owner never. Self leaves via Leave. */
  canRemove(user) {
    if (user.id() === app.session.user.id()) return false;
    const role = this.roleOf(user);
    if (role === 'owner') return false;
    if (role === 'moderator') return this.isOwner();
    return this.isManager();
  }

  /** Run a mutating request, then reload the dialog (with users) and redraw. */
  run(request) {
    this.busy = true;
    return request
      .then(() => app.store.find('dialogs', this.dialog.id(), { include: 'users' }))
      .then((d) => {
        this.dialog = d;
      })
      .catch(() => app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-group-messages.lib.error.generic')))
      .then(() => {
        this.busy = false;
        this.saving = false;
        m.redraw();
      });
  }

  saveSettings() {
    this.saving = true;
    return this.run(
      app.request({
        method: 'POST',
        url: this.apiUrl() + '/settings',
        body: { data: { attributes: { title: this.name().trim() || null, iconUrl: this.icon().trim() || null } } },
      })
    );
  }

  setModerator(user, makeModerator) {
    return this.run(
      app.request({
        method: 'POST',
        url: this.apiUrl() + (makeModerator ? '/moderators' : '/remove-moderator'),
        body: { data: { attributes: { userId: Number(user.id()) } } },
      })
    );
  }

  remove(user) {
    return this.run(
      app.request({
        method: 'POST',
        url: this.apiUrl() + '/remove-participant',
        body: { data: { attributes: { userId: Number(user.id()) } } },
      })
    );
  }

  addPeople() {
    const dialog = this.dialog;
    const reopen = (d) => app.modal.show(GroupManageModal, { dialog: d });

    app.modal.show(UserSelectionModal, {
      title: app.translator.trans('ernestdefoe-group-messages.forum.manage.add_people'),
      selected: [],
      onsubmit: (users) => {
        const existing = new Set((dialog.users() || []).filter(Boolean).map((u) => u.id()));
        const ids = [...new Set(users.filter((u) => u && !existing.has(u.id())).map((u) => Number(u.id())))];

        // The picker replaced this modal; perform the add (if any) and reopen
        // the manager with the refreshed dialog.
        (ids.length
          ? app
              .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/dialogs/' + dialog.id() + '/participants', body: { data: { attributes: { userIds: ids } } } })
              .then(() => app.store.find('dialogs', dialog.id(), { include: 'users' }))
          : Promise.resolve(dialog)
        )
          .then(reopen)
          .catch(() => {
            app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-group-messages.lib.error.generic'));
            reopen(dialog);
          });
      },
    });
  }

  async leave() {
    this.busy = true;
    try {
      await app.request({ method: 'POST', url: this.apiUrl() + '/leave' });
      // The actor is no longer a participant (or the dialog was deleted), so
      // don't refetch it — just return to the list, which reloads from server.
      app.modal.close();
      m.route.set(app.route('dialogs'));
    } catch (e) {
      this.busy = false;
      app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-group-messages.lib.error.generic'));
      m.redraw();
    }
  }
}
