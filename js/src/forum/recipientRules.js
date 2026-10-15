import app from 'flarum/forum/app';

/**
 * Who the people pickers offer, as flarum/messages' own recipient picker does:
 * never the actor, and from flarum/messages 2.0, not those nobody may write to
 * (such as accounts anonymised by flarum/gdpr), with those who couldn't reply
 * shown as unavailable. The server has the final say either way.
 *
 * rc.8 has no canMessage() and its picker only takes a list of ids.
 */
export default function recipientRules() {
  const self = app.session.user;

  if (typeof self.canMessage !== 'function') return { excluded: [self.id()] };

  return {
    excluded: (user) => user === self || user.canMessage() === false,
    unavailable: (user) =>
      self.canMessageUsersWithoutPermission() || user.canSendAnyMessage() !== false
        ? null
        : app.translator.trans('ernestdefoe-group-messages.forum.compose.cannot_reply_text'),
  };
}
