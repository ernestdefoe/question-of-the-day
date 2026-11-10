<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('qotd_questions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('body', 255);
            // pending (a member's suggestion), approved (in the pool or asked), rejected.
            $table->string('status', 10)->default('pending');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('votes_count')->default(0);
            // A member's suggestion (shown as "suggested by", and they hear when it's asked), not a moderator's own.
            $table->boolean('suggested')->default(false);
            $table->date('scheduled_for')->nullable();
            // The forum-local day it was the question. One question a day: the unique index is the rotation's lock.
            $table->date('asked_on')->nullable();
            $table->date('prev_asked_on')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('top_answer_id')->nullable();
            $table->unsignedInteger('discussion_id')->nullable();
            $table->unsignedInteger('notify_cursor')->default(0);
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedTinyInteger('post_attempts')->default(0);
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('asked_on');
            $table->index(['status', 'asked_on', 'scheduled_for']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('qotd_questions');
    },
];
