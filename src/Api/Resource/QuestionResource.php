<?php

namespace ErnestDefoe\QuestionOfTheDay\Api\Resource;

use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Questions;
use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Starter;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `qotd-questions` JSON:API type: the archive, the suggestion queue and the pool.
 *
 * GET    /api/qotd-questions?filter[list]=archive|queue|pool   past questions (newest first),
 *        suggestions waiting for votes, or (moderators) the pool in the order it will be asked
 * GET    /api/qotd-questions/{id}
 * POST   /api/qotd-questions            a member's suggestion, or a moderator's question
 * PATCH  /api/qotd-questions/{id}       approve, reject, reword, schedule
 * DELETE /api/qotd-questions/{id}
 * POST   /api/qotd-questions/{id}/vote  {vote}; /{id}/ask (a moderator's "Ask now")
 * POST   /api/qotd-questions/add        {bodies} (moderators, one per line); /starter; /reminder {on}
 *
 * A question carries its words, never its answers. The only answer it can
 * lead to is `topAnswer`, of a question that has CLOSED, through the
 * `qotd-answers` type, whose scope admits nothing else. Today's answers are
 * served only by the plain endpoints under /api/qotd (see extend.php).
 *
 * @extends AbstractDatabaseResource<Question>
 */
class QuestionResource extends AbstractDatabaseResource
{
    /**
     * Resource constructors run on every API request, so the pool's rules (and what they need)
     * are only built when a question is actually written: the container hands them over on first use.
     */
    public function __construct(private readonly QuestionBatch $batch, private readonly Container $container)
    {
    }

    private function questions(): Questions
    {
        return $this->container->make(Questions::class);
    }

    public function type(): string
    {
        return 'qotd-questions';
    }

    public function model(): string
    {
        return Question::class;
    }

    /**
     * Asked questions for anyone who may view them; suggestions waiting in the
     * queue for members who suggest and vote; the pool and rejected ones for
     * moderators only (the pool is a surprise).
     *
     * @param Builder<Question> $query
     */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        if (! $actor->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');

            return;
        }
        $moderator = $actor->can(Settings::MODERATE);
        $suggest = $moderator || $actor->can(Settings::SUGGEST);

        $query->where(function (Builder $q) use ($moderator, $suggest) {
            $q->whereNotNull('qotd_questions.asked_on');
            if ($suggest) {
                $q->orWhere('qotd_questions.status', Question::PENDING);
            }
            if ($moderator) {
                $q->orWhere('qotd_questions.status', '!=', Question::PENDING);
            }
        });
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(20, 200)
                ->defaultInclude(['user', 'topAnswer', 'topAnswer.user'])
                // Core reads an included user's groups for a moderator's view: load them at once, not per user.
                ->eagerLoad(['user.groups', 'topAnswer.user.groups'])
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters) => $this->listing($query, $pagination, $context, $filters)),
            Endpoint\Show::make()->defaultInclude(['user']),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
            Endpoint\Endpoint::make('qotd-questions.vote')
                ->route('POST', '/{id}/vote')
                ->authenticated()
                ->action(fn (Context $context) => $this->questions()->vote($context->model, (bool) ($context->body()['vote'] ?? true), $context->getActor())),
            Endpoint\Endpoint::make('qotd-questions.ask')
                ->route('POST', '/{id}/ask')
                ->authenticated()
                ->action(fn (Context $context) => $this->questions()->askNow($context->model, $context->getActor())),
            Endpoint\Endpoint::make('qotd-questions.add')
                ->route('POST', '/add')
                ->authenticated()
                ->action(fn (Context $context) => $this->questions()->addMany((array) ($context->body()['bodies'] ?? []), $context->getActor()))
                ->response(fn (Context $context, array $result) => new JsonResponse($result, $result['added'] ? 201 : 200)),
            Endpoint\Endpoint::make('qotd-questions.starter')
                ->route('POST', '/starter')
                ->authenticated()
                ->action(fn (Context $context) => $this->questions()->addMany(Starter::QUESTIONS, $context->getActor()))
                ->response(fn (Context $context, array $result) => new JsonResponse($result, $result['added'] ? 201 : 200)),
            Endpoint\Endpoint::make('qotd-questions.reminder')
                ->route('POST', '/reminder')
                ->authenticated()
                ->action(fn (Context $context) => $this->questions()->remind((bool) ($context->body()['on'] ?? true), $context->getActor()))
                ->response(fn (Context $context, bool $on) => new JsonResponse(['subscribed' => $on])),
        ];
    }

    public function fields(): array
    {
        $moderator = fn ($question, Context $context) => $context->getActor()->can(Settings::MODERATE);
        // The fields only declare what may be sent; Questions applies them together (creating/updating).
        $input = fn ($field) => $field->writable()->set(fn () => null);

        return [
            $input(Schema\Str::make('body')),
            $input(Schema\Str::make('status')),
            $input(Schema\Str::make('scheduledFor')->nullable()->visible($moderator)->get(fn (Question $q) => $q->scheduled_for)),
            Schema\Str::make('askedOn')->nullable()->get(fn (Question $q) => $q->asked_on),
            Schema\Boolean::make('open')->get(fn (Question $q) => $q->isOpen()),
            Schema\DateTime::make('closedAt')->nullable()->property('closed_at'),
            Schema\Boolean::make('suggested')->property('suggested'),
            Schema\Integer::make('votesCount')->property('votes_count'),
            Schema\Boolean::make('voted')
                ->visible(fn (Question $q) => $q->status === Question::PENDING)
                ->get(fn (Question $q, Context $context) => $this->batch->voted($context->request, $context->getActor(), $q->id)),
            Schema\Integer::make('answersCount')
                ->visible(fn (Question $q) => $q->isAsked())
                ->get(fn (Question $q, Context $context) => $this->batch->answers($context->request, $q->id)),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Boolean::make('mine')->get(fn (Question $q, Context $context) => ! $context->getActor()->isGuest() && $q->user_id === $context->getActor()->id),

            // Only a member's suggestion names who suggested it.
            Schema\Relationship\ToOne::make('user')
                ->includable()
                ->visible(fn (Question $q) => $q->suggested),
            // A closed question's winner. The qotd-answers scope admits closed questions' answers only.
            Schema\Relationship\ToOne::make('topAnswer')
                ->type('qotd-answers')
                ->includable()
                ->visible(fn (Question $q) => $q->closed_at !== null),
        ];
    }

    /**
     * @param Builder<Question> $query
     * @param array<string, mixed> $filters
     */
    private function listing(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $actor = $context->getActor();
        $list = in_array($filters['list'] ?? '', ['queue', 'pool'], true) ? $filters['list'] : 'archive';

        if ($list === 'queue') {
            $query->where('qotd_questions.status', Question::PENDING)
                ->orderByDesc('qotd_questions.votes_count')->orderBy('qotd_questions.id');
        } elseif ($list === 'pool') {
            if (! $actor->can(Settings::MODERATE)) {
                $query->whereRaw('1 = 0');
            }
            // The order Rotator asks them in: scheduled by date first, then most upvoted, then oldest.
            $scheduled = $query->getQuery()->getGrammar()->wrap('qotd_questions.scheduled_for');
            $query->inPool()
                ->orderByRaw("case when $scheduled is null then 1 else 0 end")
                ->orderBy('qotd_questions.scheduled_for')
                ->orderByDesc('qotd_questions.votes_count')->orderBy('qotd_questions.approved_at')->orderBy('qotd_questions.id');
        } else {
            // Closed questions only: today's is never in the archive.
            $query->whereNotNull('qotd_questions.asked_on')->whereNotNull('qotd_questions.closed_at')
                ->orderByDesc('qotd_questions.asked_on');
        }

        // A query closure owns its pagination: core applies it only on its own path.
        if ($pagination && method_exists($pagination, 'apply')) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $this->questions()->create($model, $this->attributes($context), $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function updating(object $model, BaseContext $context): ?object
    {
        $this->questions()->update($model, $this->attributes($context), $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function saved(object $model, BaseContext $context): ?object
    {
        $this->questions()->saved();

        return $model;
    }

    /** @param Context $context */
    public function delete(object $model, BaseContext $context): void
    {
        $this->questions()->delete($model, $context->getActor());
    }

    /**
     * The attributes as sent.
     *
     * @return array<string, mixed>
     */
    private function attributes(BaseContext $context): array
    {
        return (array) ($context->body()['data']['attributes'] ?? []);
    }
}
