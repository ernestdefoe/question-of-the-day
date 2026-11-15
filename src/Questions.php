<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Api\Input;
use ErnestDefoe\QuestionOfTheDay\Api\RateLimit;
use Flarum\User\User;

/**
 * The question pool's rules, for the qotd-questions resource: members suggest
 * (and upvote others' suggestions), moderators add, approve, reject, reword,
 * schedule, ask now and delete. Every no is a Refusal with the code the
 * browser turns into a sentence. None of this ever touches an answer's words.
 */
class Questions
{
    public function __construct(
        private readonly Rotator $rotator,
        private readonly Day $day,
        private readonly Streaks $streaks,
        private readonly RateLimit $limits,
    ) {
    }

    /**
     * A new question: a member's suggestion waits in the queue; a moderator's goes straight into the pool.
     *
     * @param array<string, mixed> $in
     */
    public function create(Question $question, array $in, User $actor): void
    {
        $actor->assertRegistered();
        $text = Input::question($in['body'] ?? '');
        if (! Input::questionOk($text)) {
            throw new Refusal('question_length');
        }
        if (Input::duplicate($text)) {
            throw new Refusal('duplicate', 409);
        }

        $question->body = $text;
        $question->user_id = $actor->id;

        if ($actor->can(Settings::MODERATE)) {
            if ($this->limits->exceeded($actor, 'add', 30)) {
                throw new Refusal('slow_down', 429);
            }
            $question->status = Question::APPROVED;
            $question->approved_at = Carbon::now();
            $date = Input::date($in['scheduledFor'] ?? null);
            $question->scheduled_for = $date !== null && $date >= $this->day->today() ? $date : null;
            return;
        }

        $actor->assertCan(Settings::SUGGEST);
        if (Question::query()->where('user_id', $actor->id)->where('status', Question::PENDING)->count() >= Settings::MAX_PENDING) {
            throw new Refusal('too_many_pending', 429);
        }
        if ($this->limits->exceeded($actor, 'suggest', 5) || $this->limits->dailyExceeded($actor, 'suggest', Settings::MAX_SUGGEST_PER_DAY)) {
            throw new Refusal('slow_down', 429);
        }
        $question->status = Question::PENDING;
        $question->suggested = true;
    }

    /**
     * Moderators approve, reject, reword or schedule; a member rewords their own suggestion while it waits and has no votes.
     *
     * @param array<string, mixed> $in
     */
    public function update(Question $question, array $in, User $actor): void
    {
        $moderator = $actor->can(Settings::MODERATE);
        if (! $moderator && ! ($question->user_id === $actor->id && $question->status === Question::PENDING)) {
            throw new Refusal('permission_denied', 403);
        }

        if (array_key_exists('body', $in)) {
            if ($question->isAsked()) {
                throw new Refusal('already_asked', 409);
            }
            $text = Input::question($in['body']);
            if (! Input::questionOk($text)) {
                throw new Refusal('question_length');
            }
            if (Input::duplicate($text, $question->id)) {
                throw new Refusal('duplicate', 409);
            }
            // Votes were for the old words: the author can't change them under the voters, and a moderator's rewording starts the count again.
            if ($text !== $question->body && $question->votes_count > 0) {
                if (! $moderator) {
                    throw new Refusal('has_votes', 409);
                }
                Vote::query()->where('question_id', $question->id)->delete();
                $question->votes_count = 0;
            }
            $question->body = $text;
        }

        if ($moderator && isset($in['status']) && ! $question->isAsked()) {
            if ($in['status'] === Question::APPROVED && $question->status !== Question::APPROVED) {
                $question->status = Question::APPROVED;
                $question->approved_at = Carbon::now();
            } elseif ($in['status'] === Question::REJECTED) {
                $question->status = Question::REJECTED;
                $question->scheduled_for = null;
            }
        }

        if ($moderator && array_key_exists('scheduledFor', $in) && $question->status === Question::APPROVED && ! $question->isAsked()) {
            $date = Input::date($in['scheduledFor']);
            if ($date !== null && $date < $this->day->today()) {
                throw new Refusal('date_past');
            }
            $question->scheduled_for = $date;
        }

    }

    /** After a question is saved: the pool may have changed, so the next request looks again. */
    public function saved(): void
    {
        $this->rotator->forget();
    }

    /** Moderators delete any question; a member withdraws their own while it waits. */
    public function delete(Question $question, User $actor): void
    {
        $own = $question->user_id === $actor->id && $question->status === Question::PENDING;
        if (! $own && ! $actor->can(Settings::MODERATE)) {
            throw new Refusal('permission_denied', 403);
        }

        // Deleting the question that's up takes its answers' streak credit back first.
        if ($question->isOpen()) {
            Answer::query()->where('question_id', $question->id)->pluck('user_id')
                ->each(fn ($userId) => $this->streaks->undo((int) $userId, $question));
        }
        $question->delete();
        $this->rotator->forget();
    }

    /** An upvote on a suggestion waiting in the queue, or taking it back. One each, never your own. */
    public function vote(Question $question, bool $vote, User $actor): Question
    {
        $actor->assertCan(Settings::SUGGEST);
        if ($question->status !== Question::PENDING) {
            throw new Refusal('not_found', 404);
        }
        if ($question->user_id === $actor->id) {
            throw new Refusal('own_question', 403);
        }
        if ($this->limits->exceeded($actor, 'vote', 60)) {
            throw new Refusal('slow_down', 429);
        }

        if ($vote) {
            if (Vote::query()->insertOrIgnore(['question_id' => $question->id, 'user_id' => $actor->id, 'created_at' => Carbon::now()]) === 1) {
                Question::query()->whereKey($question->id)->increment('votes_count');
            }
        } elseif (Vote::query()->where('question_id', $question->id)->where('user_id', $actor->id)->delete() === 1) {
            Question::query()->whereKey($question->id)->where('votes_count', '>', 0)->decrement('votes_count');
        }

        return $question->refresh();
    }

    /** A moderator puts a pool question up now, in place of today's while nobody has answered it. */
    public function askNow(Question $question, User $actor): Question
    {
        $actor->assertCan(Settings::MODERATE);
        if ($question->status !== Question::APPROVED || $question->isAsked()) {
            throw new Refusal('not_found', 404);
        }
        if (! $this->rotator->swap($question)) {
            throw new Refusal('today_answered', 409);
        }

        return $question->refresh();
    }

    /**
     * Moderators add any number at once (one per line, or the starter pack), skipping duplicates.
     *
     * @param array<int, mixed> $lines
     * @return array{added: int, skipped: int}
     */
    public function addMany(array $lines, User $actor): array
    {
        $actor->assertCan(Settings::MODERATE);
        if ($this->limits->exceeded($actor, 'add', 10)) {
            throw new Refusal('slow_down', 429);
        }

        $added = $skipped = 0;
        $seen = [];
        foreach (array_slice(array_filter($lines, 'is_string'), 0, 200) as $line) {
            $text = Input::question($line);
            if ($text === '') {
                continue;
            }
            $key = mb_strtolower($text);
            if (! Input::questionOk($text) || isset($seen[$key]) || Input::duplicate($text)) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;

            $question = new Question();
            $question->body = $text;
            $question->status = Question::APPROVED;
            $question->approved_at = Carbon::now();
            $question->user_id = $actor->id;
            $question->save();
            $added++;
        }

        if ($added) {
            $this->rotator->forget();
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** The daily "a new question is up" alert, on or off. */
    public function remind(bool $on, User $actor): bool
    {
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);
        $on
            ? Subscription::query()->insertOrIgnore(['user_id' => $actor->id, 'created_at' => Carbon::now()])
            : Subscription::query()->where('user_id', $actor->id)->delete();

        return $on;
    }
}
