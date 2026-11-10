<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('qotd_streaks', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('current')->default(0);
            $table->unsignedInteger('best')->default(0);
            $table->unsignedInteger('total')->default(0);
            // The asked_on of the last question they answered.
            $table->date('last_day')->nullable();

            $table->index(['last_day', 'current']);
            $table->index('best');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Members who asked for an alert when a new question goes up.
        $schema->create('qotd_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->dateTime('created_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('qotd_subscriptions');
        $schema->dropIfExists('qotd_streaks');
    },
];
