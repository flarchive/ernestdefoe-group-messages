import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import UserSelectionModal from 'flarum/common/components/UserSelectionModal';
import Stream from 'flarum/common/utils/Stream';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import extractText from 'flarum/common/utils/extractText';

/**
 * Create a group message: pick 2+ people, name it (optional emoji icon), and
 * write the first message. Creates the group via POST /api/dialogs/group, then
 * posts the first message through flarum/messages' normal message endpoint and
 * navigates to the new conversation.
 */
export default class GroupComposeModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    this.selected = [];                 // User[]
    this.groupName = Stream('');
    this.icon = Stream('');
    this.body = Stream('');
    this.loading = false;
  }

  className() {
    return 'GroupComposeModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-group-messages.forum.compose.group_title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label>{app.translator.trans('ernestdefoe-group-messages.forum.compose.recipients_label')}</label>
          <div className="GroupComposeModal-recipients">
            {this.selected.map((user) => (
              <span className="GroupComposeModal-recipient">
                <Avatar user={user} /> {username(user)}
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-times"
                  onclick={() => {
                    this.selected = this.selected.filter((u) => u.id() !== user.id());
                  }}
                />
              </span>
            ))}
            <Button className="Button Button--icon" icon="fas fa-plus" onclick={() => this.pickPeople()}>
              {app.translator.trans('ernestdefoe-group-messages.forum.manage.add_people')}
            </Button>
          </div>
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('ernestdefoe-group-messages.forum.compose.name_label')}</label>
          <div className="GroupComposeModal-nameRow">
            <input
              className="FormControl GroupComposeModal-icon"
              maxlength="8"
              placeholder="🙂"
              bidi={this.icon}
            />
            <input
              className="FormControl"
              maxlength="150"
              placeholder={extractText(app.translator.trans('ernestdefoe-group-messages.forum.compose.name_placeholder'))}
              bidi={this.groupName}
            />
          </div>
        </div>

        <div className="Form-group">
          <textarea
            className="FormControl"
            rows="3"
            placeholder={extractText(app.translator.trans('ernestdefoe-group-messages.forum.compose.message_placeholder'))}
            bidi={this.body}
          />
        </div>

        <div className="Form-group">
          <Button
            className="Button Button--primary Button--block"
            type="submit"
            loading={this.loading}
            disabled={this.selected.length < 2 || !this.body().trim()}
          >
            {app.translator.trans('ernestdefoe-group-messages.forum.compose.create_button')}
          </Button>
        </div>
      </div>
    );
  }

  pickPeople() {
    app.modal.show(UserSelectionModal, {
      title: app.translator.trans('ernestdefoe-group-messages.forum.compose.recipients_label', {}, true),
      selected: this.selected,
      onsubmit: (users) => {
        // Drop the actor and de-duplicate by id (the picker can momentarily
        // hand back the same user twice while its list re-renders).
        const seen = new Set();
        this.selected = users.filter((u) => {
          if (!u || u.id() === app.session.user.id() || seen.has(u.id())) return false;
          seen.add(u.id());
          return true;
        });
        // Re-open this modal (UserSelectionModal replaced it).
        app.modal.show(GroupComposeModal, { reopenSelected: this.selected, ...this.attrs });
        m.redraw();
      },
    });
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    // Restore selection when re-opened after the user picker.
    if (this.attrs.reopenSelected) {
      this.selected = this.attrs.reopenSelected;
    }
  }

  async onsubmit(e) {
    e.preventDefault();
    const userIds = [...new Set(this.selected.map((u) => Number(u.id())))];
    if (userIds.length < 2 || !this.body().trim()) return;
    this.loading = true;

    try {
      const created = await app.request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/dialogs/group',
        body: {
          data: {
            attributes: {
              userIds,
              title: this.groupName().trim() || null,
              iconUrl: this.icon().trim() || null,
            },
          },
        },
      });

      const dialogId = created.data.id;
      const dialog = await app.store.find('dialogs', dialogId);

      await app.store.createRecord('dialog-messages').save({
        content: this.body().trim(),
        relationships: { dialog },
      });

      app.modal.close();
      m.route.set('/messages/dialog/' + dialogId);
    } catch (err) {
      this.loading = false;
      app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-group-messages.forum.compose.create_error'));
      m.redraw();
    }
  }
}
