<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Per-dialog group metadata, companion to flarum/messages' `dialogs` table.
// Only rows for dialogs of type 'group' ever exist here; 'direct' dialogs
// have no row. Keyed 1:1 by dialog_id so the group's name/icon/owner live
// outside flarum/messages' own schema (we never modify their tables).
//
// user ids reference core users.id which is INT UNSIGNED (Flarum uses
// increments(), not bigIncrements), so the FK columns are unsignedInteger.
return Migration::createTable('group_dialogs', function (Blueprint $table) {
    $table->unsignedInteger('dialog_id')->primary();
    $table->string('title', 150)->nullable();
    $table->string('icon_url')->nullable();
    $table->unsignedInteger('owner_id')->nullable();
    $table->timestamps();

    $table->foreign('dialog_id')->references('id')->on('dialogs')->cascadeOnDelete();
    $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
});
