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
//
// COMPATIBILITY NOTE: flarum/messages exposes no extender for registering
// dialog types, so this appends to its static $types array directly at boot —
// the one place this extension reaches into flarum/messages internals. If a
// future release changes the validation strategy or removes the property,
// revisit here (ideally replace with a `Messages::addDialogType()` extender if
// one is added upstream). The guards below keep a half-installed state or an
// upstream API change from fataling the boot, and stop a double-registration
// if two extensions both append 'group'.
if (class_exists(Dialog::class)
    && property_exists(Dialog::class, 'types')
    && is_array(Dialog::$types)
    && ! in_array('group', Dialog::$types, true)
) {
    Dialog::$types[] = 'group';
}

// Lazily resolve a class at most once per request — the dialog-resource
// closures below only run for API requests, so this avoids resolving GroupFields
// twice (fields + title mutator) and never resolves on non-API page loads.
$resolved = [];
$once = function (string $class) use (&$resolved) {
    return $resolved[$class] ??= resolve($class);
};

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Companion relations on flarum/messages' Dialog. groupDetail holds the
    // name/icon/owner for group dialogs; moderators are the promoted managers.
    // readStates mirrors the participants but WITH the dialog_user pivot's
    // last_read_message_id, which flarum/messages' own users() relation omits —
    // eager-loading it lets seenBy() read receipts off a hydrated collection
    // instead of firing a pivot query per dialog (the old N+1).
    (new Extend\Model(Dialog::class))
        ->hasOne('groupDetail', GroupDialog::class, 'dialog_id')
        ->belongsToMany('moderators', User::class, 'group_dialog_moderators', 'dialog_id', 'user_id')
        ->relationship('readStates', fn (Dialog $dialog) => $dialog
            ->belongsToMany(User::class, 'dialog_user', 'dialog_id', 'user_id')
            ->withPivot('last_read_message_id')),

    // Reaction + reply relations on dialog messages.
    (new Extend\Model(DialogMessage::class))
        ->hasMany('reactions', DialogMessageReaction::class, 'message_id')
        ->hasOne('replyRecord', DialogMessageReply::class, 'message_id'),

    // Reaction (react/unreact) endpoints + reaction/reply fields on messages.
    // Eager-load reactions/replyRecord on list+show so a message list isn't N+1.
    (new Extend\ApiResource(DialogMessageResource::class))
        ->endpoints(fn () => MessageEndpoints::get())
        ->fields(fn () => MessageFields::added())
        ->endpoint(['index', 'show'], fn ($endpoint) => $endpoint->eagerLoad(['reactions', 'replyRecord'])),

    // Group create + management endpoints on the dialogs resource. GroupFields/
    // GroupEndpoints are resolved AT MOST ONCE per request via $once() (the
    // resource-building closures only run for API requests, and GroupFields was
    // previously resolved twice — for fields() and the title mutator). The group
    // metadata relations are eager-loaded on list+show so the field getters read
    // hydrated relations instead of re-querying per dialog.
    (new Extend\ApiResource(DialogResource::class))
        ->endpoints(fn () => $once(GroupEndpoints::class)->get())
        ->fields(fn () => $once(GroupFields::class)->added())
        ->field('title', fn ($field) => $once(GroupFields::class)->titleMutator()($field))
        ->endpoint(['index', 'show'], fn ($endpoint) => $endpoint->eagerLoad(['groupDetail', 'moderators', 'users', 'readStates'])),

    (new Extend\Policy())
        ->modelPolicy(Dialog::class, GroupDialogPolicy::class),
];
