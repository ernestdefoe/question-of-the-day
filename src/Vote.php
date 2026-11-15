<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Flarum\Database\AbstractModel;

/**
 * An upvote on a suggestion waiting in the queue. One per member per question (the primary key).
 * Written through query builders (a composite or user-id key, no auto-increment id).
 */
class Vote extends AbstractModel
{
    protected $table = 'qotd_votes';

    public $incrementing = false;

    protected $primaryKey = 'question_id';
}
