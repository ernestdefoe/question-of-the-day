<?php

namespace ErnestDefoe\QuestionOfTheDay;

/**
 * Keeps each member's answer streak: consecutive questions answered.
 *
 * Credit is keyed by the question's id (a question that replaced a deleted
 * one the same day still counts), and the state before the last credit is
 * kept, so deleting the answer to the open question gives the credit back.
 * Every change runs under the member's streak lock (Locks: a cache lock plus a
 * transaction, since lockForUpdate does nothing on SQLite); AnswerController
 * takes the same lock to write an answer and its credit together.
 */
class Streaks
{
    public function __construct(private readonly Locks $locks)
    {
    }

    /** The member's streak lock. */
    public static function lock(int $userId): string
    {
        return 'streak.'.$userId;
    }

    /** Counts an answer to $question, taking the member's streak lock. */
    public function record(int $userId, Question $question): Streak
    {
        return $this->locks->run(self::lock($userId), fn () => $this->credit($userId, $question));
    }

    /** Takes back the credit an answer to $question gave, taking the member's streak lock. */
    public function undo(int $userId, Question $question): void
    {
        $this->locks->run(self::lock($userId), fn () => $this->takeBack($userId, $question));
    }

    /**
     * Counts an answer to $question; the caller holds self::lock(). Runs once per member per
     * question: the answer's unique index sees to that.
     */
    public function credit(int $userId, Question $question): Streak
    {
        $streak = Streak::query()->lockForUpdate()->find($userId) ?? new Streak();
        if ($streak->exists && $streak->last_question_id === $question->id) {
            return $streak;
        }

        $streak->user_id = $userId;
        $streak->prev_current = (int) $streak->current;
        $streak->prev_best = (int) $streak->best;
        $streak->prev_total = (int) $streak->total;
        $streak->prev_last_day = $streak->last_day;
        $streak->prev_last_question_id = $streak->last_question_id;

        // Already credited today for another question (the day's question was replaced): the day counts once.
        if ($streak->last_day !== $question->asked_on) {
            $continues = $streak->last_day !== null && $streak->last_day === $question->prev_asked_on;
            $streak->current = $continues ? $streak->current + 1 : 1;
            $streak->best = max((int) $streak->best, $streak->current);
            $streak->total = (int) $streak->total + 1;
        }
        $streak->last_day = $question->asked_on;
        $streak->last_question_id = $question->id;
        $streak->save();

        return $streak;
    }

    /** Takes back the credit of an answer to $question (deleted while the question was up); the caller holds self::lock(). */
    public function takeBack(int $userId, Question $question): void
    {
        $streak = Streak::query()->lockForUpdate()->find($userId);
        if (! $streak || $streak->last_question_id !== $question->id) {
            return;
        }

        $streak->current = $streak->prev_current;
        $streak->best = $streak->prev_best;
        $streak->total = $streak->prev_total;
        $streak->last_day = $streak->prev_last_day;
        $streak->last_question_id = $streak->prev_last_question_id;
        $streak->save();
    }
}
