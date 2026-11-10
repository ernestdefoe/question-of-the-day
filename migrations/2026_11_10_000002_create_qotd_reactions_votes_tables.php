<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One reaction per member per answer (they can change which).
        $schema->create('qotd_reactions', function (Blueprint $table) {
            $table->unsignedInteger('answer_id');
            $table->unsignedInteger('user_id');
            $table->string('type', 10);
            $table->dateTime('created_at');

            $table->primary(['answer_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('answer_id')->references('id')->on('qotd_answers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Upvotes on suggested questions.
        $schema->create('qotd_votes', function (Blueprint $table) {
            $table->unsignedInteger('question_id');
            $table->unsignedInteger('user_id');
            $table->dateTime('created_at');

            $table->primary(['question_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('question_id')->references('id')->on('qotd_questions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('qotd_votes');
        $schema->dropIfExists('qotd_reactions');
    },
];
