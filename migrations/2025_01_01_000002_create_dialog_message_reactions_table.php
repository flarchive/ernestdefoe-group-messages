<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Emoji reactions on dialog messages (works for both group and direct
// dialogs). One row per (message, user, reaction) so a user can add several
// distinct reactions to a message but not the same one twice. message_id and
// user_id reference INT UNSIGNED columns (dialog_messages.id / users.id).
return Migration::createTable('dialog_message_reactions', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('message_id');
    $table->unsignedInteger('user_id');
    $table->string('reaction', 60);
    $table->dateTime('created_at')->nullable();

    $table->unique(['message_id', 'user_id', 'reaction']);
    $table->index('message_id');

    $table->foreign('message_id')->references('id')->on('dialog_messages')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
