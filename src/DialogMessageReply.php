<?php

namespace Ernestdefoe\GroupMessages;

use Flarum\Database\AbstractModel;
use Flarum\Messages\DialogMessage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reply reference companion: this message replies to reply_to_id. Keyed 1:1 by
 * the replying message.
 *
 * @property int $message_id
 * @property int $reply_to_id
 */
class DialogMessageReply extends AbstractModel
{
    protected $table = 'dialog_message_replies';

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    public $timestamps = false;

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(DialogMessage::class, 'reply_to_id');
    }
}
