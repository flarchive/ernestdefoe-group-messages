<?php

namespace Ernestdefoe\GroupMessages\Access;

use Ernestdefoe\GroupMessages\GroupDialogManager;
use Flarum\Messages\Dialog;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Authorisation for managing group dialogs. Layered on flarum/messages'
 * DialogPolicy (which already gates view/sendMessage). These abilities only
 * ever allow for type='group' dialogs — they abstain (null) for 'direct' so a
 * caller can't drive the group endpoints against a normal DM.
 */
class GroupDialogPolicy extends AbstractPolicy
{
    public function __construct(protected GroupDialogManager $groups)
    {
    }

    protected function isGroup(Dialog $dialog): bool
    {
        return $dialog->type === 'group';
    }

    /** Rename, set icon, add/remove members — owner or moderator. */
    public function editGroup(User $actor, Dialog $dialog)
    {
        return $this->isGroup($dialog) && $this->groups->isManager($dialog, $actor)
            ? $this->allow()
            : null;
    }

    /** Promote/demote moderators — owner only. */
    public function manageModerators(User $actor, Dialog $dialog)
    {
        return $this->isGroup($dialog) && $this->groups->isOwner($dialog, $actor)
            ? $this->allow()
            : null;
    }

    /** Leave the group — any current participant. */
    public function leaveGroup(User $actor, Dialog $dialog)
    {
        return $this->isGroup($dialog) && $this->groups->roleOf($dialog, $actor) !== null
            ? $this->allow()
            : null;
    }
}
