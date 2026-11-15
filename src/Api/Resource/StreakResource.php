<?php

namespace ErnestDefoe\QuestionOfTheDay\Api\Resource;

use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Streak;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;
use WeakMap;

/**
 * The `qotd-streaks` JSON:API type: answers in a row, by member (the id is the user's).
 *
 * GET /api/qotd-streaks?filter[board]=going|best   the ten longest streaks going, or ever
 * GET /api/qotd-streaks/{userId}                  one member's streak
 *
 * A streak is a count. It names no question and carries no answer.
 *
 * @extends AbstractDatabaseResource<Streak>
 */
class StreakResource extends AbstractDatabaseResource
{
    public const BOARD = 10;

    /** @var WeakMap<ServerRequestInterface, Question|false> The question that's up, once per request. */
    private WeakMap $current;

    public function __construct()
    {
        $this->current = new WeakMap();
    }

    public function type(): string
    {
        return 'qotd-streaks';
    }

    public function model(): string
    {
        return Streak::class;
    }

    /** @param Builder<Streak> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $context->getActor()->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(self::BOARD, self::BOARD)
                ->defaultInclude(['user'])
                // Core reads an included user's groups for a moderator's view: load them at once, not per user.
                ->eagerLoad(['user.groups'])
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters) => $this->board($query, $pagination, $context, $filters)),
            Endpoint\Show::make(),
        ];
    }

    public function fields(): array
    {
        return [
            // Broken if they missed the last question, whatever the stored number says.
            Schema\Integer::make('current')->get(fn (Streak $streak, Context $context) => $streak->live($this->current($context->request))),
            Schema\Integer::make('best')->property('best'),
            Schema\Integer::make('total')->property('total'),
            Schema\Relationship\ToOne::make('user')->includable(),
        ];
    }

    /**
     * @param Builder<Streak> $query
     * @param array<string, mixed> $filters
     */
    private function board(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $query->whereHas('user');

        if (($filters['board'] ?? '') === 'best') {
            $query->where('qotd_streaks.best', '>', 0)->orderByDesc('qotd_streaks.best')->orderByDesc('qotd_streaks.total');
        } else {
            $current = $this->current($context->request);
            $alive = array_values(array_filter([$current?->asked_on, $current?->prev_asked_on]));
            $alive ? $query->whereIn('qotd_streaks.last_day', $alive) : $query->whereRaw('1 = 0');
            $query->where('qotd_streaks.current', '>', 0)->orderByDesc('qotd_streaks.current')->orderByDesc('qotd_streaks.total');
        }

        // A query closure owns its pagination: core applies it only on its own path.
        if ($pagination && method_exists($pagination, 'apply')) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    private function current(ServerRequestInterface $request): ?Question
    {
        if (! isset($this->current[$request])) {
            $this->current[$request] = Question::current() ?? false;
        }

        return $this->current[$request] ?: null;
    }

    /** A board shows its ten; nothing shows a total, so nothing counts one. */
    public function count(object $query, BaseContext $context): ?int
    {
        return null;
    }
}
