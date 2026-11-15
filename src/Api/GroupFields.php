<?php

namespace Ernestdefoe\GroupMessages\Api;

use Ernestdefoe\GroupMessages\GroupDialog;
use Ernestdefoe\GroupMessages\GroupDialogManager;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Locale\TranslatorInterface;
use Flarum\Messages\Dialog;

/**
 * Group-aware fields added to flarum/messages' DialogResource. Roles, names
 * and icons are gated on groups this extension manages (a group_dialogs row),
 * so direct conversations, and Flarum 2.1's own groups, serialize as core
 * makes them.
 *
 * Injectable (resolved once when the resource schema is built) so the field
 * getters can hold the manager + translator instead of pulling them out of the
 * container on every serialization. The relations they read (groupDetail,
 * users, moderators) are eager-loaded on the dialog list/show endpoints (see
 * extend.php), so a page of dialogs costs a handful of queries, not 4-6 each.
 */
class GroupFields
{
    public function __construct(
        protected GroupDialogManager $manager,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * New fields to merge into the resource (Extend\ApiResource->fields).
     *
     * @return array<int, object>
     */
    public function added(): array
    {
        return [
            Schema\Boolean::make('isGroup')
                ->get(fn (Dialog $dialog) => $dialog->type === 'group'),

            // Started from Group Messages' New group form, so it has an owner,
            // moderators and Manage. Flarum 2.1's own groups are not.
            Schema\Boolean::make('isManagedGroup')
                ->get(fn (Dialog $dialog) => $this->manager->isManaged($dialog)),

            Schema\Str::make('iconUrl')
                ->nullable()
                ->get(fn (Dialog $dialog) => $this->detail($dialog)?->icon_url),

            Schema\Integer::make('ownerId')
                ->nullable()
                ->get(fn (Dialog $dialog) => $this->detail($dialog)?->owner_id),

            // The requesting actor's role: owner | moderator | member | null.
            // Drives which management controls the frontend shows.
            Schema\Str::make('actorRole')
                ->nullable()
                ->get(function (Dialog $dialog, Context $context) {
                    if (! $this->manager->isManaged($dialog)) {
                        return null;
                    }

                    return $this->manager->roleOf($dialog, $context->getActor());
                }),

            Schema\Integer::make('participantCount')
                ->get(fn (Dialog $dialog) => $dialog->type === 'group' ? $dialog->users->count() : 0),

            // Map of participant id => role, for the management UI.
            Schema\Arr::make('roles')
                ->get(fn (Dialog $dialog) => $this->roles($dialog)),

            // Read receipts: participant ids who have read up to the latest
            // message (their last_read_message_id >= the dialog's last message).
            Schema\Arr::make('lastMessageSeenByIds')
                ->get(fn (Dialog $dialog) => $this->seenBy($dialog)),
        ];
    }

    /**
     * Mutator for the existing `title` field so group dialogs show their name
     * instead of flarum/messages' "Conversation with {recipient}". Every other
     * dialog keeps flarum/messages' own title. On rc.8 that one fails when the
     * other member has deleted their account, so this names them as deleted,
     * as flarum/messages 2.0.0 does.
     */
    public function titleMutator(): callable
    {
        return function (Schema\Str $field) {
            $original = clone $field;

            return $field->get(function (Dialog $dialog, Context $context) use ($original) {
                // Before Flarum 2.1 core can't title a group at all.
                if ($this->manager->isManaged($dialog) || ($dialog->type === 'group' && ! method_exists($dialog, 'isGroup'))) {
                    return $this->detail($dialog)?->title
                        ?: $this->translator->trans('ernestdefoe-group-messages.forum.dialog.group_fallback_title');
                }

                if ($dialog->type !== 'group' && ! $dialog->recipient($context->getActor())) {
                    return $this->translator->trans('flarum-messages.lib.dialog.title', [
                        '{username}' => $this->translator->trans('core.lib.username.deleted_text'),
                    ]);
                }

                return $original->getValue($context);
            });
        };
    }

    /**
     * Mutator for flarum/messages 2.0.0's `canSendMessage`, which hides the
     * composer: in a group, the same rule as GroupDialogPolicy::sendMessage.
     */
    public function canSendMessageMutator(): callable
    {
        return function (Schema\Boolean $field) {
            $original = clone $field;

            return $field->get(fn (Dialog $dialog, Context $context) => $this->manager->isManaged($dialog)
                ? $this->manager->canSend($context->getActor(), $dialog)
                : $original->getValue($context));
        };
    }

    /**
     * Flarum 2.1's `name` (what members call a group, where the forum allows
     * it): anyone who may write in a group can set it. A group we manage is
     * named by its owner and moderators, from Manage, so core's name stays
     * read-only there. Flarum 2.0 has no such field; the mutator never runs.
     */
    public function nameMutator(): callable
    {
        return function (Schema\Str $field) {
            $original = clone $field;

            return $field->writable(fn ($dialog, Context $context) => ! ($dialog instanceof Dialog && $this->manager->isManaged($dialog))
                && $original->isWritable($context));
        };
    }

    /** Flarum 2.1's `canRename`: never for a group we manage (see nameMutator()). */
    public function canRenameMutator(): callable
    {
        return function (Schema\Boolean $field) {
            $original = clone $field;

            return $field->get(fn (Dialog $dialog, Context $context) => ! $this->manager->isManaged($dialog)
                && $original->getValue($context));
        };
    }

    protected function detail(Dialog $dialog): ?GroupDialog
    {
        if ($dialog->type !== 'group') {
            return null;
        }

        return $this->manager->detail($dialog);
    }

    /** @return array<int, string> participant id => role */
    protected function roles(Dialog $dialog): array
    {
        if (! $this->manager->isManaged($dialog)) {
            return [];
        }
        $ownerId = (int) ($this->detail($dialog)->owner_id ?? 0);
        $moderatorIds = $dialog->moderators->map(fn ($u) => (int) $u->id)->all();
        $out = [];
        foreach ($dialog->users as $user) {
            $id = (int) $user->id;
            $out[$id] = $id === $ownerId
                ? GroupDialogManager::ROLE_OWNER
                : (in_array($id, $moderatorIds, true) ? GroupDialogManager::ROLE_MODERATOR : GroupDialogManager::ROLE_MEMBER);
        }

        return $out;
    }

    /**
     * @return int[]
     *
     * Reads receipts off the eager-loaded `readStates` relation (participants
     * carrying the dialog_user pivot's last_read_message_id) so a dialog list
     * doesn't fire a pivot query per row — see the relation in extend.php.
     */
    protected function seenBy(Dialog $dialog): array
    {
        if ($dialog->type !== 'group' || ! $dialog->last_message_id) {
            return [];
        }
        $lastMessageId = (int) $dialog->last_message_id;

        return $dialog->getAttribute('readStates')
            ->filter(fn ($u) => (int) ($u->pivot->last_read_message_id ?? 0) >= $lastMessageId)
            ->map(fn ($u) => (int) $u->id)
            ->values()
            ->all();
    }
}
