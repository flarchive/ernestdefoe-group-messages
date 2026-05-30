<?php

namespace Ernestdefoe\GroupMessages\Api;

use Ernestdefoe\GroupMessages\DialogMessageReply;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Messages\DialogMessage;

/**
 * Reaction + reply fields added to flarum/messages' DialogMessageResource.
 */
class MessageFields
{
    /** @return array<int, object> */
    public static function added(): array
    {
        return [
            // Aggregated reactions: [{ reaction, count, mine }]. Uses the
            // eager-loaded `reactions` relation (see the endpoint mutation in
            // extend.php) so a message list isn't N+1.
            Schema\Arr::make('reactions')
                ->get(function (DialogMessage $message, Context $context) {
                    $actorId = (int) $context->getActor()->id;
                    $grouped = [];
                    foreach ($message->reactions as $reaction) {
                        $key = $reaction->reaction;
                        $grouped[$key] ??= ['reaction' => $key, 'count' => 0, 'mine' => false];
                        $grouped[$key]['count']++;
                        if ((int) $reaction->user_id === $actorId) {
                            $grouped[$key]['mine'] = true;
                        }
                    }
                    return array_values($grouped);
                }),

            // The message this one replies to (same dialog). Writable only at
            // create time; the frontend renders the referenced message inline.
            Schema\Integer::make('replyToId')
                ->nullable()
                ->writableOnCreate()
                ->get(fn (DialogMessage $message) => $message->replyRecord?->reply_to_id)
                ->set(function (DialogMessage $message, ?int $value, Context $context) {
                    $replyToId = (int) $value;
                    if ($replyToId <= 0) {
                        return;
                    }
                    // Defer until the message has an id, and only link if the
                    // target is a real message in the SAME dialog.
                    $message->afterSave(function (DialogMessage $message) use ($replyToId) {
                        $valid = DialogMessage::query()
                            ->where('id', $replyToId)
                            ->where('dialog_id', $message->dialog_id)
                            ->exists();
                        if (! $valid) {
                            return;
                        }
                        $reply = new DialogMessageReply();
                        $reply->message_id = (int) $message->id;
                        $reply->reply_to_id = $replyToId;
                        $reply->save();
                    });
                }),
        ];
    }
}
