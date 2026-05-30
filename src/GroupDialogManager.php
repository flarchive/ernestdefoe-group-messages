<?php

namespace Ernestdefoe\GroupMessages;

use Carbon\Carbon;
use Flarum\Messages\Dialog;
use Flarum\User\User;
use Illuminate\Support\Arr;

/**
 * All the group-dialog operations in one place, so the API endpoints stay thin
 * orchestrators. A "group" is a `dialogs` row of type 'group' with a
 * GroupDialog metadata row (title/icon/owner) and a participant set in
 * flarum/messages' `dialog_user` pivot. Moderators live in
 * group_dialog_moderators; everyone else in the dialog is a plain member.
 */
class GroupDialogManager
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_MEMBER = 'member';

    /**
     * Create a group dialog owned by $actor with the given other participants.
     * Participants are attached the same way flarum/messages attaches DM users
     * (syncWithPivotValues joined_at), so the existing message/visibility
     * pipeline treats the group identically to a direct dialog.
     *
     * @param int[] $userIds  Other participant ids (actor is added automatically).
     */
    public function create(User $actor, array $userIds, ?string $title = null, ?string $iconUrl = null): Dialog
    {
        $others = $this->cleanIds($userIds, $actor->id);

        if (count($others) < 2) {
            throw new \Flarum\Foundation\ValidationException([
                'users' => app('translator')->trans('ernestdefoe-group-messages.lib.error.min_participants'),
            ]);
        }

        return Dialog::query()->getConnection()->transaction(function () use ($actor, $others, $title, $iconUrl) {
            $dialog = new Dialog();
            $dialog->type = 'group';
            $dialog->save();

            $participants = array_values(array_unique(array_merge($others, [(int) $actor->id])));
            $dialog->users()->syncWithPivotValues($participants, ['joined_at' => Carbon::now()]);

            $detail = new GroupDialog();
            $detail->dialog_id = $dialog->id;
            $detail->title = $this->normalizeTitle($title);
            $detail->icon_url = $iconUrl ?: null;
            $detail->owner_id = (int) $actor->id;
            $detail->save();

            return $dialog->refresh();
        });
    }

    /** @param int[] $userIds */
    public function addParticipants(Dialog $dialog, array $userIds): void
    {
        $existing = $dialog->users()->pluck('users.id')->all();
        $toAdd = array_values(array_diff($this->cleanIds($userIds), array_map('intval', $existing)));
        if (! $toAdd) {
            return;
        }
        $dialog->users()->syncWithoutDetaching(
            array_fill_keys($toAdd, ['joined_at' => Carbon::now()])
        );
    }

    public function removeParticipant(Dialog $dialog, User $user): void
    {
        $dialog->users()->detach($user->id);
        $dialog->moderators()->detach($user->id);
    }

    /**
     * A participant leaves. If the owner leaves, ownership passes to a
     * moderator (or any remaining member); if no one remains, the dialog is
     * dropped.
     */
    public function leave(Dialog $dialog, User $user): void
    {
        $detail = $this->detail($dialog);
        $isOwner = $detail && (int) $detail->owner_id === (int) $user->id;

        $this->removeParticipant($dialog, $user);

        if ($isOwner && $detail) {
            $heir = $dialog->moderators()->where('users.id', '!=', $user->id)->value('users.id')
                ?? $dialog->users()->where('users.id', '!=', $user->id)->value('users.id');

            if ($heir) {
                $detail->owner_id = (int) $heir;
                $detail->save();
                $dialog->moderators()->detach($heir);
            } else {
                $dialog->delete();
            }
        }
    }

    public function rename(Dialog $dialog, ?string $title): void
    {
        $detail = $this->detail($dialog);
        if ($detail) {
            $detail->title = $this->normalizeTitle($title);
            $detail->save();
        }
    }

    public function setIcon(Dialog $dialog, ?string $iconUrl): void
    {
        $detail = $this->detail($dialog);
        if ($detail) {
            $detail->icon_url = $iconUrl ?: null;
            $detail->save();
        }
    }

    public function promoteModerator(Dialog $dialog, User $user): void
    {
        if ($this->roleOf($dialog, $user) === self::ROLE_MEMBER) {
            $dialog->moderators()->syncWithoutDetaching([$user->id]);
        }
    }

    public function demoteModerator(Dialog $dialog, User $user): void
    {
        $dialog->moderators()->detach($user->id);
    }

    /** Returns 'owner' | 'moderator' | 'member' | null (not a participant). */
    public function roleOf(Dialog $dialog, ?User $user): ?string
    {
        if (! $user || ! $user->exists) {
            return null;
        }
        $detail = $this->detail($dialog);
        if ($detail && (int) $detail->owner_id === (int) $user->id) {
            return self::ROLE_OWNER;
        }
        if ($dialog->moderators()->where('users.id', $user->id)->exists()) {
            return self::ROLE_MODERATOR;
        }
        if ($dialog->users()->where('users.id', $user->id)->exists()) {
            return self::ROLE_MEMBER;
        }
        return null;
    }

    public function isManager(Dialog $dialog, ?User $user): bool
    {
        return in_array($this->roleOf($dialog, $user), [self::ROLE_OWNER, self::ROLE_MODERATOR], true);
    }

    public function isOwner(Dialog $dialog, ?User $user): bool
    {
        return $this->roleOf($dialog, $user) === self::ROLE_OWNER;
    }

    public function detail(Dialog $dialog): ?GroupDialog
    {
        return GroupDialog::query()->find($dialog->id);
    }

    /** @param int[] $ids @return int[] */
    protected function cleanIds(array $ids, ?int $exclude = null): array
    {
        $ids = array_map('intval', Arr::wrap($ids));
        $ids = array_filter($ids, fn ($id) => $id > 0 && $id !== $exclude);
        return array_values(array_unique($ids));
    }

    protected function normalizeTitle(?string $title): ?string
    {
        $title = trim((string) $title);
        return $title === '' ? null : mb_substr($title, 0, 150);
    }
}
