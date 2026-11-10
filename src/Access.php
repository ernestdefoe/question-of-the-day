<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Flarum\User\User;

/**
 * Who may read the answers to a question. The ONLY gate in front of
 * Present::answers(): every path that puts an answer's words in a response
 * asks it first.
 *
 * While a question is up, its answers are hidden from anyone who hasn't
 * posted their own, moderators included, unless a moderator explicitly
 * opens it to moderate. Once the next question replaces it, its answers
 * are the archive: anyone who may view questions reads them.
 */
final class Access
{
    public static function canSeeAnswers(Question $question, User $actor, bool $answered, bool $moderating = false): bool
    {
        if (! $question->isAsked() || ! $actor->can(Settings::VIEW)) {
            return false;
        }
        if ($question->closed_at !== null) {
            return true;
        }

        return $answered || ($moderating && $actor->can(Settings::MODERATE));
    }
}
