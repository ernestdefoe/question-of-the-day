<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Flarum\Database\AbstractModel;

/**
 * A reaction to an answer. One per member per answer (the primary key); the type can change.
 * Written through query builders (a composite or user-id key, no auto-increment id).
 */
class Reaction extends AbstractModel
{
    protected $table = 'qotd_reactions';

    public $incrementing = false;

    protected $primaryKey = 'answer_id';
}
