<?php

namespace ErnestDefoe\QuestionOfTheDay\Api\Resource;

use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `qotd-answers` JSON:API type, there only so a closed question's
 * `topAnswer` can be included. Its scope is an allow-list: answers to
 * questions that have CLOSED (the public archive), nothing else, so no
 * include or id can ever reach an answer to the question that's up. No
 * endpoints: answers are listed, written and reacted to through /api/qotd.
 *
 * @extends AbstractDatabaseResource<Answer>
 */
class AnswerResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'qotd-answers';
    }

    public function model(): string
    {
        return Answer::class;
    }

    /** @param Builder<Answer> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $context->getActor()->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->whereIn('qotd_answers.question_id', fn ($q) => $q->select('id')->from('qotd_questions')->whereNotNull('closed_at'));
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('body'),
            Schema\Integer::make('reactionsCount')->property('reactions_count'),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Relationship\ToOne::make('user')->includable(),
        ];
    }
}
