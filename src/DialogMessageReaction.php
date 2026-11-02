<?php

namespace Ernestdefoe\GroupMessages;

use Flarum\Database\AbstractModel;
use Flarum\Messages\DialogMessage;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emoji reaction by one user on one dialog message.
 *
 * @property int $id
 * @property int $message_id
 * @property int $user_id
 * @property string $reaction
 * @property \Carbon\Carbon|null $created_at
 */
class DialogMessageReaction extends AbstractModel
{
    protected $table = 'dialog_message_reactions';

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(DialogMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
