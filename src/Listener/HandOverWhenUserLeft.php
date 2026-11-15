<?php

namespace Ernestdefoe\GroupMessages\Listener;

use Ernestdefoe\GroupMessages\GroupDialogManager;
use Flarum\Messages\Dialog\Event\UserLeft;

/**
 * Flarum 2.1 runs leaving a group itself (and Group Messages' own leave route
 * stands down, see GroupEndpoints). For a group we manage, the member who left
 * stops being a moderator, and an owner hands the group on. Never fired on
 * Flarum 2.0, which has no such event.
 */
class HandOverWhenUserLeft
{
    public function __construct(protected GroupDialogManager $groups)
    {
    }

    public function handle(UserLeft $event): void
    {
        if ($event->dialog->exists) {
            $this->groups->afterLeft($event->dialog, $event->user);
        }
    }
}
