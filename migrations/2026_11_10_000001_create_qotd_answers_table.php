<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('qotd_answers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('question_id');
            $table->unsignedInteger('user_id');
            $table->text('body');
            $table->unsignedInteger('reactions_count')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('edited_at')->nullable();

            // One answer per member per question: the database refuses a second, however fast.
            $table->unique(['question_id', 'user_id']);
            $table->index(['question_id', 'reactions_count']);
            $table->foreign('question_id')->references('id')->on('qotd_questions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('qotd_answers');
    },
];
