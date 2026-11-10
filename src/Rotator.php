<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Job\Deliver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Puts up the day's question at local midnight.
 *
 * The unique index on asked_on is the lock: however many schedulers and page
 * requests rotate at once, one question gets the day and every other attempt
 * changes nothing. Asking is a conditional UPDATE on a question still in the
 * pool, and closing yesterday's (with its top answer) happens in the same
 * transaction, so a day never has two questions and a question is never
 * asked twice.
 *
 * Page requests call ensureToday() too (forums without a scheduler), behind a
 * cached "already checked today" so most calls cost no query at all.
 */
class Rotator
{
    private const CHECKED = 'ernestdefoe-qotd.checked';

    public function __construct(
        private readonly Day $day,
        private readonly Cache $cache,
        private readonly ConnectionInterface $db,
        private readonly Queue $queue,
    ) {
    }

    public function ensureToday(): void
    {
        $today = $this->day->today();
        if ($this->cache->get(self::CHECKED) === $today) {
            return;
        }

        $this->rotate($today);
        // Re-checked every ten minutes: a question added to an empty pool later today still goes up.
        $this->cache->put(self::CHECKED, $today, 600);
    }

    /** After the pool changes (a question approved, added or scheduled): look again on the next request. */
    public function forget(): void
    {
        $this->cache->forget(self::CHECKED);
    }

    /** Asks the next question for $today if the day has none yet. Returns it when this call asked it. */
    public function rotate(string $today, ?Question $pick = null): ?Question
    {
        if (Question::query()->where('asked_on', $today)->exists()) {
            return null;
        }

        $current = Question::current();
        // A clock or timezone moved backwards: never ask a question "before" the one that's up.
        if ($current && $current->asked_on && $current->asked_on > $today) {
            return null;
        }

        $next = $pick ?? $this->next($today);
        if (! $next) {
            return null;
        }

        try {
            $won = $this->db->transaction(function () use ($next, $current, $today) {
                $won = Question::query()->whereKey($next->id)->inPool()->update([
                    'asked_on' => $today,
                    'prev_asked_on' => $current?->asked_on,
                    'opened_at' => Carbon::now(),
                    'scheduled_for' => null,
                ]) === 1;

                if ($won && $current) {
                    $this->close($current);
                }

                return $won;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if (! $won) {
            return null;
        }

        $this->cache->put(self::CHECKED, $today, 600);
        $this->queue->push(new Deliver());

        return Question::query()->find($next->id);
    }

    /**
     * Swaps today's question for another before anyone has answered it (a
     * moderator's "Ask this now"). False if today's already has answers or has
     * closed. What delivery already did for the old one is taken back: its
     * daily discussion is hidden and its alerts deleted (emails already sent
     * can't be), so it can go up again later as if new.
     */
    public function swap(Question $pick): bool
    {
        $swapped = $this->db->transaction(function () use ($pick) {
            // The day and today's question are read here, under the lock: a swap racing midnight
            // must not put yesterday's question back or leave one open beside the new day's.
            $today = $this->day->today();
            $todays = Question::query()->where('asked_on', $today)->lockForUpdate()->first();
            if (! $todays) {
                return null;
            }
            if ($todays->closed_at !== null || Question::query()->where('asked_on', '>', $today)->exists()
                || Answer::query()->where('question_id', $todays->id)->exists()) {
                return false;
            }

            // Back into the pool, as if never asked.
            Question::query()->whereKey($todays->id)->update([
                'asked_on' => null, 'prev_asked_on' => null, 'opened_at' => null, 'closed_at' => null,
                'notify_cursor' => 0, 'email_cursor' => 0, 'notified_at' => null, 'posted_at' => null, 'post_attempts' => 0, 'discussion_id' => null,
            ]);

            $won = Question::query()->whereKey($pick->id)->inPool()->update([
                'asked_on' => $today,
                'prev_asked_on' => $todays->prev_asked_on,
                'opened_at' => Carbon::now(),
                'scheduled_for' => null,
            ]) === 1;

            return $won ? $todays : false;
        });

        if ($swapped === null) {
            // Nothing up today yet: this simply becomes today's question.
            return $this->rotate($this->day->today(), $pick) !== null;
        }
        if (! $swapped) {
            return false;
        }

        resolve(Delivery::class)->retract($swapped);
        $this->queue->push(new Deliver());

        return true;
    }

    /** The question that goes up next: one scheduled for today (or a missed date) first, then the most upvoted, then the oldest. */
    public function next(string $today): ?Question
    {
        return Question::query()->inPool()->whereNotNull('scheduled_for')->where('scheduled_for', '<=', $today)
            ->orderBy('scheduled_for')->orderBy('id')->first()
            ?? $this->unscheduled()->first();
    }

    /**
     * The pool in the order it will be asked, for moderators: scheduled ones by date, then the rest.
     *
     * @return EloquentCollection<int, Question>
     */
    public function upcoming(int $limit): EloquentCollection
    {
        $scheduled = Question::query()->inPool()->whereNotNull('scheduled_for')->orderBy('scheduled_for')->orderBy('id')->limit($limit)->get();

        return new EloquentCollection($scheduled->concat($this->unscheduled()->limit($limit)->get())->take($limit)->values()->all());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Question> */
    private function unscheduled(): \Illuminate\Database\Eloquent\Builder
    {
        return Question::query()->inPool()->whereNull('scheduled_for')
            ->orderByDesc('votes_count')->orderBy('approved_at')->orderBy('id');
    }

    /** Closes a question and keeps its best answer: most reactions, the earlier one on a tie, none without a reaction. */
    private function close(Question $question): void
    {
        Question::query()->whereKey($question->id)->whereNull('closed_at')
            ->update(['closed_at' => Carbon::now(), 'top_answer_id' => $this->topAnswerId($question)]);
    }

    /** Picks the top answer again (a moderator deleted the old one). */
    public function refreshTop(Question $question): void
    {
        if ($question->closed_at) {
            Question::query()->whereKey($question->id)->update(['top_answer_id' => $this->topAnswerId($question)]);
        }
    }

    private function topAnswerId(Question $question): ?int
    {
        $id = Answer::query()->where('question_id', $question->id)->where('reactions_count', '>', 0)
            ->orderByDesc('reactions_count')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
