<?php

namespace Ernestdefoe\GroupMessages\Api;

use Carbon\Carbon;
use Ernestdefoe\GroupMessages\DialogMessageReaction;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Messages\DialogMessage;
use Illuminate\Support\Arr;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * React / unreact endpoints on flarum/messages' DialogMessageResource. The
 * {id} segment resolves the message through the resource's visibility scope
 * (whereVisibleTo), so only a participant of the message's dialog can react.
 */
class MessageEndpoints
{
    /** @return list<Endpoint\Endpoint> */
    public static function get(): array
    {
        return [
            Endpoint\Endpoint::make('group-messages.react')
                ->route('POST', '/{id}/react')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var DialogMessage $message */
                    $message = $context->model;
                    $actorId = (int) $context->getActor()->id;
                    $reaction = static::reaction($context);

                    $exists = DialogMessageReaction::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actorId)
                        ->where('reaction', $reaction)
                        ->exists();

                    if (! $exists) {
                        $row = new DialogMessageReaction();
                        $row->message_id = (int) $message->id;
                        $row->user_id = $actorId;
                        $row->reaction = $reaction;
                        $row->created_at = Carbon::now();
                        $row->save();
                    }

                    return $message->refresh();
                }),

            Endpoint\Endpoint::make('group-messages.unreact')
                ->route('POST', '/{id}/unreact')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var DialogMessage $message */
                    $message = $context->model;
                    DialogMessageReaction::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $context->getActor()->id)
                        ->where('reaction', static::reaction($context))
                        ->delete();

                    return $message->refresh();
                }),
        ];
    }

    protected static function reaction(Context $context): string
    {
        $reaction = trim((string) Arr::get($context->body(), 'data.attributes.reaction', ''));
        if ($reaction === '' || mb_strlen($reaction) > 60) {
            throw new BadRequestException('A reaction (1–60 chars) is required.');
        }

        return $reaction;
    }
}
