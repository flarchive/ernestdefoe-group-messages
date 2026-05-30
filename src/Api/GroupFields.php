<?php

namespace Ernestdefoe\GroupMessages\Api;

use Ernestdefoe\GroupMessages\GroupDialog;
use Ernestdefoe\GroupMessages\GroupDialogManager;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Messages\Dialog;

/**
 * Group-aware fields added to flarum/messages' DialogResource. Everything is
 * gated on type==='group' so 'direct' dialogs serialize exactly as before.
 */
class GroupFields
{
    /**
     * New fields to merge into the resource (Extend\ApiResource->fields).
     *
     * @return array<int, object>
     */
    public static function added(): array
    {
        return [
            Schema\Boolean::make('isGroup')
                ->get(fn (Dialog $dialog) => $dialog->type === 'group'),

            Schema\Str::make('iconUrl')
                ->nullable()
                ->get(fn (Dialog $dialog) => static::detail($dialog)?->icon_url),

            Schema\Integer::make('ownerId')
                ->nullable()
                ->get(fn (Dialog $dialog) => static::detail($dialog)?->owner_id),

            // The requesting actor's role: owner | moderator | member | null.
            // Drives which management controls the frontend shows.
            Schema\Str::make('actorRole')
                ->nullable()
                ->get(function (Dialog $dialog, Context $context) {
                    if ($dialog->type !== 'group') {
                        return null;
                    }
                    return resolve(GroupDialogManager::class)->roleOf($dialog, $context->getActor());
                }),

            Schema\Integer::make('participantCount')
                ->get(fn (Dialog $dialog) => $dialog->type === 'group' ? $dialog->users()->count() : 0),

            // Map of participant id => role, for the management UI.
            Schema\Arr::make('roles')
                ->get(fn (Dialog $dialog) => static::roles($dialog)),

            // Read receipts: participant ids who have read up to the latest
            // message (their last_read_message_id >= the dialog's last message).
            Schema\Arr::make('lastMessageSeenByIds')
                ->get(fn (Dialog $dialog) => static::seenBy($dialog)),
        ];
    }

    /**
     * Mutator for the existing `title` field so group dialogs show their name
     * instead of flarum/messages' "Conversation with {recipient}".
     */
    public static function titleMutator(): callable
    {
        return function ($field) {
            return $field->get(function (Dialog $dialog, Context $context) {
                $translator = resolve(\Flarum\Locale\TranslatorInterface::class);

                if ($dialog->type === 'group') {
                    $title = static::detail($dialog)?->title;
                    return $title
                        ?: $translator->trans('ernestdefoe-group-messages.forum.dialog.group_fallback_title');
                }

                $recipient = $dialog->recipient($context->getActor());
                return $recipient
                    ? $translator->trans('flarum-messages.lib.dialog.title', ['{username}' => $recipient->display_name])
                    : '';
            });
        };
    }

    protected static function detail(Dialog $dialog): ?GroupDialog
    {
        if ($dialog->type !== 'group') {
            return null;
        }
        return GroupDialog::query()->find($dialog->id);
    }

    /** @return array<int, string> participant id => role */
    protected static function roles(Dialog $dialog): array
    {
        if ($dialog->type !== 'group') {
            return [];
        }
        $ownerId = (int) (static::detail($dialog)?->owner_id ?? 0);
        $moderatorIds = array_map('intval', $dialog->moderators()->pluck('users.id')->all());
        $out = [];
        foreach ($dialog->users()->pluck('users.id')->all() as $id) {
            $id = (int) $id;
            $out[$id] = $id === $ownerId
                ? GroupDialogManager::ROLE_OWNER
                : (in_array($id, $moderatorIds, true) ? GroupDialogManager::ROLE_MODERATOR : GroupDialogManager::ROLE_MEMBER);
        }
        return $out;
    }

    /** @return int[] */
    protected static function seenBy(Dialog $dialog): array
    {
        if ($dialog->type !== 'group' || ! $dialog->last_message_id) {
            return [];
        }
        return array_map('intval', $dialog->users()
            ->wherePivot('last_read_message_id', '>=', $dialog->last_message_id)
            ->pluck('users.id')
            ->all());
    }
}
