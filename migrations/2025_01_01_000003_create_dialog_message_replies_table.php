<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Reply references: a message can point at an earlier message in the same
// dialog. Companion to dialog_messages (1:1, keyed by the replying message).
// If the replied-to message is deleted, the reference row is removed (the
// reply message itself stays). Quoting is a frontend-only composer action
// (markdown blockquote) and needs no storage.
return Migration::createTable('dialog_message_replies', function (Blueprint $table) {
    $table->unsignedInteger('message_id')->primary();
    $table->unsignedInteger('reply_to_id');

    $table->foreign('message_id')->references('id')->on('dialog_messages')->cascadeOnDelete();
    $table->foreign('reply_to_id')->references('id')->on('dialog_messages')->cascadeOnDelete();
});
