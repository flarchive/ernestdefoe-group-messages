<?php

namespace Ernestdefoe\GroupMessages;

use Flarum\Database\AbstractModel;
use Flarum\Messages\Dialog;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Group-level metadata for a `dialogs` row of type 'group'. Keyed 1:1 by
 * dialog_id (no auto-increment id of its own).
 *
 * @property int $dialog_id
 * @property string|null $title
 * @property string|null $icon_url
 * @property int|null $owner_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Dialog|null $dialog
 * @property-read User|null $owner
 */
class GroupDialog extends AbstractModel
{
    protected $table = 'group_dialogs';

    protected $primaryKey = 'dialog_id';

    public $incrementing = false;

    public $timestamps = true;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'dialog_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
