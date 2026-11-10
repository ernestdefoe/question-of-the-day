<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Emails go one by one behind their own cursor, so a crash mid-chunk resumes instead of skipping them.
        $schema->table('qotd_questions', function (Blueprint $table) {
            $table->unsignedInteger('email_cursor')->default(0);
        });

        // Credit is keyed by question, and the state before the last credit is kept so a deleted answer can give it back.
        $schema->table('qotd_streaks', function (Blueprint $table) {
            $table->unsignedInteger('last_question_id')->nullable();
            $table->unsignedInteger('prev_current')->default(0);
            $table->unsignedInteger('prev_best')->default(0);
            $table->unsignedInteger('prev_total')->default(0);
            $table->date('prev_last_day')->nullable();
            $table->unsignedInteger('prev_last_question_id')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('qotd_streaks', function (Blueprint $table) {
            $table->dropColumn(['last_question_id', 'prev_current', 'prev_best', 'prev_total', 'prev_last_day', 'prev_last_question_id']);
        });
        $schema->table('qotd_questions', function (Blueprint $table) {
            $table->dropColumn('email_cursor');
        });
    },
];
