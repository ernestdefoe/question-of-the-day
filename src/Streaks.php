<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Illuminate\Database\ConnectionInterface;

/** Keeps each member's answer streak: consecutive questions answered. */
class Streaks
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /** Counts an answer to $question. Runs once per member per question: the answer's unique index sees to that. */
    public function record(int $userId, Question $question): Streak
    {
        return $this->db->transaction(function () use ($userId, $question) {
            /** @var Streak $streak */
            $streak = Streak::query()->lockForUpdate()->find($userId) ?? new Streak();
            $day = $question->asked_on;
            $last = $streak->last_day;

            if ($last === $day) {
                return $streak;
            }

            $continues = $last !== null && $last === $question->prev_asked_on;
            $streak->user_id = $userId;
            $streak->current = $continues ? $streak->current + 1 : 1;
            $streak->best = max((int) $streak->best, $streak->current);
            $streak->total = (int) $streak->total + 1;
            $streak->last_day = $day;
            $streak->save();

            return $streak;
        });
    }
}
