<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $body
 * @property string $status
 * @property int|null $user_id
 * @property int $votes_count
 * @property bool $suggested
 * @property string|null $scheduled_for Y-m-d
 * @property string|null $asked_on Y-m-d
 * @property string|null $prev_asked_on Y-m-d
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $top_answer_id
 * @property int|null $discussion_id
 * @property int $notify_cursor
 * @property int $email_cursor
 * @property Carbon|null $notified_at
 * @property Carbon|null $posted_at
 * @property int $post_attempts
 * @property Carbon|null $approved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Answer|null $topAnswer
 * @property-read Discussion|null $discussion
 */
class Question extends AbstractModel
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'qotd_questions';

    // The day columns (asked_on, prev_asked_on, scheduled_for) are plain Y-m-d strings, never
    // date casts: a cast writes "Y-m-d 00:00:00", which on SQLite no longer equals or sorts against "Y-m-d".

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'votes_count' => 'integer',
        'suggested' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'top_answer_id' => 'integer',
        'discussion_id' => 'integer',
        'notify_cursor' => 'integer',
        'email_cursor' => 'integer',
        'notified_at' => 'datetime',
        'posted_at' => 'datetime',
        'post_attempts' => 'integer',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The question being answered now: the latest one asked, until the next replaces it. */
    public static function current(): ?self
    {
        return static::query()->whereNotNull('asked_on')->orderByDesc('asked_on')->first();
    }

    /** @param Builder<self> $query */
    public function scopeInPool(Builder $query): void
    {
        $query->where('qotd_questions.status', self::APPROVED)->whereNull('qotd_questions.asked_on');
    }

    public function isAsked(): bool
    {
        return $this->asked_on !== null;
    }

    public function isOpen(): bool
    {
        return $this->asked_on !== null && $this->closed_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Answer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /** @return BelongsTo<Answer, $this> */
    public function topAnswer(): BelongsTo
    {
        return $this->belongsTo(Answer::class, 'top_answer_id');
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }
}
