<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Flarum\Database\AbstractModel;

/**
 * A member who asked for the daily "a new question is up" alert.
 * Written through query builders (a composite or user-id key, no auto-increment id).
 */
class Subscription extends AbstractModel
{
    protected $table = 'qotd_subscriptions';

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $guarded = [];
}
