<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $question_id
 * @property int $user_id
 * @property string $body
 * @property int $reactions_count
 * @property Carbon $created_at
 * @property Carbon|null $edited_at
 * @property-read User|null $user
 * @property-read Question|null $question
 */
class Answer extends AbstractModel
{
    protected $table = 'qotd_answers';

    protected $casts = [
        'id' => 'integer',
        'question_id' => 'integer',
        'user_id' => 'integer',
        'reactions_count' => 'integer',
        'created_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
