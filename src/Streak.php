<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Answers in a row, one row per member. "In a row" means consecutive
 * questions, not calendar days: a day the pool ran dry breaks nobody's streak.
 *
 * @property int $user_id
 * @property int $current
 * @property int $best
 * @property int $total
 * @property string|null $last_day Y-m-d
 * @property-read User|null $user
 */
class Streak extends AbstractModel
{
    protected $table = 'qotd_streaks';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $casts = [
        'user_id' => 'integer',
        'current' => 'integer',
        'best' => 'integer',
        'total' => 'integer',
    ];

    /** The streak as it stands for the question being answered now: broken if they missed the last one. */
    public function live(?Question $current): int
    {
        if (! $current || ! $this->last_day) {
            return 0;
        }
        $last = $this->last_day;

        return $last === $current->asked_on || $last === $current->prev_asked_on ? $this->current : 0;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
