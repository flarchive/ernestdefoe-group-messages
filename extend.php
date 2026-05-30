<?php

namespace Ernestdefoe\GroupMessages;

use Ernestdefoe\GroupMessages\Access\GroupDialogPolicy;
use Ernestdefoe\GroupMessages\Api\GroupEndpoints;
use Ernestdefoe\GroupMessages\Api\GroupFields;
use Ernestdefoe\GroupMessages\Api\MessageEndpoints;
use Ernestdefoe\GroupMessages\Api\MessageFields;
use Flarum\Extend;
use Flarum\Messages\Api\Resource\DialogMessageResource;
use Flarum\Messages\Api\Resource\DialogResource;
use Flarum\Messages\Dialog;
use Flarum\Messages\DialogMessage;
use Flarum\User\User;

// Register the 'group' dialog type alongside flarum/messages' built-in
// 'direct'. DialogResource validates the type against Dialog::$types
// (->in(Dialog::$types)), so without this the API rejects type=group.
// flarum/messages is a hard dependency, but guard anyway so a half-installed
// state can't fatal the boot.
if (class_exists(Dialog::class) && ! in_array('group', Dialog::$types, true)) {
    Dialog::$types[] = 'group';
}

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Companion relations on flarum/messages' Dialog. groupDetail holds the
    // name/icon/owner for group dialogs; moderators are the promoted managers.
    (new Extend\Model(Dialog::class))
        ->hasOne('groupDetail', GroupDialog::class, 'dialog_id')
        ->belongsToMany('moderators', User::class, 'group_dialog_moderators', 'dialog_id', 'user_id'),

    // Reaction + reply relations on dialog messages.
    (new Extend\Model(DialogMessage::class))
        ->hasMany('reactions', DialogMessageReaction::class, 'message_id')
        ->hasOne('replyRecord', DialogMessageReply::class, 'message_id'),

    // Reaction (react/unreact) endpoints + reaction/reply fields on messages.
    (new Extend\ApiResource(DialogMessageResource::class))
        ->endpoints(fn () => MessageEndpoints::get())
        ->fields(fn () => MessageFields::added()),

    // Group create + management endpoints on the dialogs resource.
    (new Extend\ApiResource(DialogResource::class))
        ->endpoints(fn () => GroupEndpoints::get())
        ->fields(fn () => GroupFields::added())
        ->field('title', GroupFields::titleMutator()),

    (new Extend\Policy())
        ->modelPolicy(Dialog::class, GroupDialogPolicy::class),
];
