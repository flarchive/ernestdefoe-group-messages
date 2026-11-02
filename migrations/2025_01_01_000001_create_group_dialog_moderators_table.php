<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Moderators of a group dialog. The owner lives on group_dialogs.owner_id;
// this table holds the additional members the owner has promoted to manage
// the group (add/remove participants, rename). Everyone else in the dialog
// is a plain member. Composite PK prevents duplicate rows.
return Migration::createTable('group_dialog_moderators', function (Blueprint $table) {
    $table->unsignedInteger('dialog_id');
    $table->unsignedInteger('user_id');

    $table->primary(['dialog_id', 'user_id']);

    $table->foreign('dialog_id')->references('id')->on('dialogs')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
