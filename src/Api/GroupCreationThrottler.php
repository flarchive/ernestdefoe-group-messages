<?php

namespace Ernestdefoe\GroupMessages\Api;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Messages\Dialog;
use Flarum\Messages\DialogMessageThrottler;
use Psr\Http\Message\ServerRequestInterface;

/**
 * flarum/messages 2.0.0 caps how many conversations a member opens in an hour,
 * so a fresh account can't work through the member list. Creating a group
 * opens one too, without going through its endpoint: count it against the
 * same cap. Abstains for those allowed past the throttle; it never overrides
 * another throttler.
 */
class GroupCreationThrottler
{
    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'dialogs.group-messages.create'
            || ! class_exists(DialogMessageThrottler::class)) {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest() || $actor->can('dialog.sendMessageWithoutThrottle')) {
            return null;
        }

        $opened = Dialog::whereRelation('users', 'user_id', $actor->id)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();

        return $opened >= DialogMessageThrottler::$newDialogsPerHour ? true : null;
    }
}
