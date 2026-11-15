import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import highlight from 'flarum/common/helpers/highlight';

const reg = flarum.reg;

const t = (key, params, text) => app.translator.trans('ernestdefoe-group-messages.forum.search.' + key, params, text);

const PAGE = 20;
const CONVERSATIONS = 5;
const MIN_LENGTH = 2;
const DELAY = 300;

/**
 * One search for the whole Messages page. It lives outside the page so that
 * opening a result, which is a route change that rebuilds the page, leaves the
 * results where they were for the next one.
 */
const search = {
  query: '',
  /** The conversation being searched, or null for all of them. */
  scope: null,
  /** Conversations with someone, or a group, whose name matches. */
  dialogs: [],
  results: [],
  loading: false,
  more: false,
  /** Bumped by every search, so an answer to an older one is dropped. */
  run: 0,
  timer: null,
  /** Set when the field is asked for before it is on screen. */
  focusNext: false,

  active() {
    return this.query.trim().length >= MIN_LENGTH;
  },

  set(query) {
    this.query = query;
    clearTimeout(this.timer);

    if (!this.active()) {
      this.run++;
      this.dialogs = [];
      this.results = [];
      this.loading = false;
      this.more = false;
      return;
    }

    this.loading = true;
    this.timer = setTimeout(() => this.load(), DELAY);
  },

  setScope(dialog) {
    this.scope = dialog;
    this.dialogs = [];
    this.results = [];
    if (this.active()) this.set(this.query);
  },

  load(offset = 0) {
    const run = ++this.run;
    const q = this.query.trim();
    const filter = { q };
    if (this.scope) filter.dialog = this.scope.id();

    this.loading = true;
    m.redraw();

    // Who a conversation is with is only asked once, with the first page of
    // messages, and not within one conversation.
    if (!offset && !this.scope) {
      app.store
        .find('dialogs', {
          filter: { q },
          page: { limit: CONVERSATIONS },
          sort: '-lastMessageAt',
          include: 'lastMessage.user,users.groups',
        })
        .then(
          (dialogs) => run === this.run && (this.dialogs = dialogs),
          () => {}
        )
        .finally(() => m.redraw());
    }

    return app.store
      .find('dialog-messages', {
        filter,
        page: { limit: PAGE, offset },
        // In one conversation the newest match comes first; across them all,
        // the closest match does.
        ...(this.scope ? { sort: '-number' } : {}),
        include: 'user,dialog.users',
      })
      .then(
        (results) => {
          if (run !== this.run) return;
          this.results = offset ? this.results.concat(results) : results;
          this.more = results.length === PAGE;
        },
        () => {
          if (run === this.run) this.more = false;
        }
      )
      .finally(() => {
        if (run !== this.run) return;
        this.loading = false;
        m.redraw();
      });
  },
};

function field() {
  const scope = search.scope;

  return (
    <div className="GroupMessagesSearch" key="groupMessagesSearch">
      <div className="GroupMessagesSearch-field">
        <Icon name="fas fa-search" />
        <input
          className="FormControl"
          type="search"
          // Not a login field: no browser autofill, and no password manager
          // offering a saved login in it.
          autocomplete="off"
          data-1p-ignore=""
          data-lpignore="true"
          data-bwignore="true"
          data-form-type="other"
          value={search.query}
          placeholder={t(scope ? 'placeholder_in' : 'placeholder', { title: scope && scope.title() }, true)}
          aria-label={t('label', {}, true)}
          oncreate={(vnode) => {
            if (search.focusNext) vnode.dom.focus();
            search.focusNext = false;
          }}
          oninput={(e) => search.set(e.target.value)}
          onkeydown={(e) => {
            if (e.key === 'Escape') search.set('');
          }}
        />
      </div>
      {scope && (
        <div className="GroupMessagesSearch-scope">
          <span>{t('in', { title: scope.title() })}</span>
          <Button
            className="Button Button--icon Button--link"
            icon="fas fa-times"
            aria-label={t('all_label', {}, true)}
            onclick={() => search.setScope(null)}
          />
        </div>
      )}
    </div>
  );
}

function results(activeDialog) {
  const MessageSearchResult = reg.get('flarum-messages', 'forum/components/MessageSearchResult');
  const DialogListItem = reg.get('flarum-messages', 'forum/components/DialogListItem');
  const query = search.query.trim().toLowerCase();
  const headed = !search.scope && search.dialogs.length;

  return (
    <div className="DialogList GroupMessagesSearch-results" key="groupMessagesSearchResults">
      {!!headed && [
        <h4 className="GroupMessagesSearch-heading">{t('conversations')}</h4>,
        <ul className="DialogList-list">
          {search.dialogs.map((dialog) => (
            <DialogListItem key={dialog.id()} dialog={dialog} active={activeDialog?.id() === dialog.id()} />
          ))}
        </ul>,
        <h4 className="GroupMessagesSearch-heading">{t('messages')}</h4>,
      ]}
      {search.results.length ? (
        <ul className="GroupMessagesSearch-list">
          {search.results.map((message) => (
            <MessageSearchResult key={message.id()} message={message} query={query} byAuthor={!!search.scope} />
          ))}
        </ul>
      ) : search.loading ? null : (
        <p className="GroupMessagesSearch-empty">{t(headed || search.scope ? 'empty' : 'empty_all')}</p>
      )}
      {search.loading ? (
        <LoadingIndicator display="block" />
      ) : (
        search.more && (
          <div className="GroupMessagesSearch-more">
            <Button className="Button" onclick={() => search.load(search.results.length)}>
              {t('more')}
            </Button>
          </div>
        )
      )}
    </div>
  );
}

/**
 * Search on the Messages page. flarum/messages finds messages from the
 * forum's header search, which is not where anyone looks for them: this puts a
 * field above the conversation list, whose results take the list's place, and
 * a "Search this conversation" item in each conversation's menu.
 */
export default function applyMessageSearch() {
  reg.onLoad('flarum-messages', 'forum/components/MessagesPage', (MessagesPage) => {
    extend(MessagesPage.prototype, 'contentItems', function (items) {
      if (!items.has('sidebar')) return;

      const sidebar = items.get('sidebar');
      const children = [];

      (sidebar.children || []).forEach((child) => {
        if (child && child.key === 'list') {
          children.push(field(), search.active() ? results(this.selectedDialog()) : child);
        } else {
          children.push(child);
        }
      });

      sidebar.children = children;
    });
  });

  // In one conversation every result is from it, so a row names who wrote it.
  reg.onLoad('flarum-messages', 'forum/components/MessageSearchResult', (MessageSearchResult) => {
    extend(MessageSearchResult.prototype, 'contentItems', function (items) {
      if (!this.attrs.byAuthor || !items.has('text')) return;

      const message = this.attrs.message;

      items.setContent(
        'text',
        <div className="MessageSearchResult-text">
          <div className="MessageSearchResult-title">
            <span className="MessageSearchResult-conversation">{username(message.user() || null)}</span>
            {humanTime(message.createdAt())}
          </div>
          <div className="MessageSearchResult-excerpt">{highlight(message.contentPlain() ?? '', this.highlightRegExp(), 175)}</div>
        </div>
      );
    });
  });

  reg.onLoad('flarum-messages', 'forum/components/DialogSection', (DialogSection) => {
    extend(DialogSection.prototype, 'controlItems', function (items) {
      const dialog = this.attrs.dialog;

      items.add(
        'searchDialog',
        <Button
          icon="fas fa-search"
          onclick={() => {
            search.setScope(dialog);

            // On a phone the conversation covers the list, field and all.
            const input = document.querySelector('.GroupMessagesSearch input');
            if (input && input.offsetParent) input.focus();
            else {
              search.focusNext = true;
              m.route.set(app.route('messages'));
            }
          }}
        >
          {t('this_conversation')}
        </Button>,
        60
      );
    });
  });
}
