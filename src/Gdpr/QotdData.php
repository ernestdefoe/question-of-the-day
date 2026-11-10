<?php

namespace ErnestDefoe\QuestionOfTheDay\Gdpr;

use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Streak;
use Flarum\Gdpr\Data\Type;

/**
 * The member's answers, suggestions and streak in a data export; on erasure
 * their answers, reactions, votes, streak and reminder go, and their
 * suggestions stay in the pool without their name. Registered only when
 * flarum/gdpr is enabled.
 */
class QotdData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-question-of-the-day.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-question-of-the-day.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $answers = Answer::query()->where('user_id', $this->user->id)->with('question')->orderBy('id')->get()
            ->map(fn (Answer $answer) => [
                'question' => $answer->question?->body,
                'asked_on' => $answer->question?->asked_on,
                'answer' => $answer->body,
                'reactions' => $answer->reactions_count,
                'created_at' => $answer->created_at->toIso8601String(),
            ])->all();
        $suggested = Question::query()->where('user_id', $this->user->id)->where('suggested', true)->orderBy('id')->get()
            ->map(fn (Question $question) => $question->only(['body', 'status', 'votes_count', 'asked_on', 'created_at']))->all();
        $streak = Streak::query()->find($this->user->id);

        return [
            ['question-of-the-day/answers.json' => $this->encodeForExport($answers)],
            ['question-of-the-day/suggestions.json' => $this->encodeForExport($suggested)],
            ['question-of-the-day/streak.json' => $this->encodeForExport($streak ? $streak->only(['current', 'best', 'total', 'last_day']) : [])],
        ];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Forget::user($this->user->id);
    }
}
