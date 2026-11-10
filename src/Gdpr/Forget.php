<?php

namespace ErnestDefoe\QuestionOfTheDay\Gdpr;

use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use Illuminate\Database\ConnectionInterface;

/**
 * Removes a member's part in Question of the Day (account deleted or erased).
 * The foreign keys would drop their rows, but not fix the counts their
 * reactions and votes added to other people's answers and suggestions:
 * each member gives at most one per answer or question, so one decrement each.
 */
final class Forget
{
    public static function user(int $userId): void
    {
        /** @var ConnectionInterface $db */
        $db = resolve(ConnectionInterface::class);

        $db->transaction(function () use ($db, $userId) {
            $reacted = $db->table('qotd_reactions')->where('user_id', $userId)->pluck('answer_id')->all();
            foreach (array_chunk($reacted, 500) as $ids) {
                Answer::query()->whereIn('id', $ids)->where('reactions_count', '>', 0)->decrement('reactions_count');
            }
            $db->table('qotd_reactions')->where('user_id', $userId)->delete();

            $voted = $db->table('qotd_votes')->where('user_id', $userId)->pluck('question_id')->all();
            foreach (array_chunk($voted, 500) as $ids) {
                Question::query()->whereIn('id', $ids)->where('votes_count', '>', 0)->decrement('votes_count');
            }
            $db->table('qotd_votes')->where('user_id', $userId)->delete();

            $theirs = fn () => Answer::query()->select('id')->where('user_id', $userId);
            $db->table('qotd_reactions')->whereIn('answer_id', $theirs())->delete();
            // A closed question whose top answer was theirs gets its next best.
            $tops = Question::query()->whereIn('top_answer_id', $theirs())->get();
            Answer::query()->where('user_id', $userId)->delete();
            $tops->each(fn (Question $question) => resolve(Rotator::class)->refreshTop($question));

            $db->table('qotd_streaks')->where('user_id', $userId)->delete();
            $db->table('qotd_subscriptions')->where('user_id', $userId)->delete();
            Question::query()->where('user_id', $userId)->update(['user_id' => null]);
        });
    }
}
