<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Present;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Streak;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/qotd/archive: past questions, newest first, each with its top answer.
 * GET /api/qotd/queue: suggestions waiting for votes and a moderator (and, for
 *   moderators, the pool in the order it will be asked).
 * GET /api/qotd/streaks: the longest streaks going, and the best ever.
 * Each is a fixed handful of queries however long the list.
 */
class ListController implements RequestHandlerInterface
{
    private const ARCHIVE = 20;
    private const QUEUE = 100;
    private const POOL = 200;
    private const BOARD = 10;

    public function __construct(private readonly Present $present, private readonly Rotator $rotator, private readonly ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        $this->rotator->ensureToday();
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/queue')) {
            return $this->queue($actor);
        }
        if (str_ends_with($path, '/streaks')) {
            return $this->streaks($actor);
        }

        return $this->archive($actor, Input::offset($request));
    }

    private function archive(User $actor, int $offset): ResponseInterface
    {
        // Closed questions only: today's answers are never in the archive.
        $questions = Question::query()->whereNotNull('asked_on')->whereNotNull('closed_at')
            ->withCount('answers')->with(['user', 'topAnswer.user'])
            ->orderByDesc('asked_on')->offset($offset)->limit(self::ARCHIVE + 1)->get();
        $more = $questions->count() > self::ARCHIVE;
        $questions = $questions->take(self::ARCHIVE);

        // Every top answer's reactions in one go, not one lookup per question.
        $tops = $questions->pluck('topAnswer')->filter()->values();
        $presented = collect($this->present->answers($tops, $actor))->keyBy('id');
        $ids = $questions->pluck('discussion_id')->filter()->all();
        $discussions = $ids ? Discussion::query()->whereVisibleTo($actor)->whereIn('id', $ids)->get()->keyBy('id') : new Collection();

        return new JsonResponse([
            'questions' => $questions->map(fn (Question $question) => $this->present->brief($question, $actor, $discussions) + [
                'answersCount' => (int) $question->getAttribute('answers_count'),
                'top' => $question->top_answer_id ? $presented->get($question->top_answer_id) : null,
            ])->values(),
            'hasMore' => $more,
        ]);
    }

    private function queue(User $actor): ResponseInterface
    {
        $moderator = $actor->can(Settings::MODERATE);
        if (! $moderator && ! $actor->can(Settings::SUGGEST)) {
            return Errors::make(403, 'permission_denied');
        }

        $pending = Question::query()->where('status', Question::PENDING)->with('user')
            ->orderByDesc('votes_count')->orderBy('id')->limit(self::QUEUE)->get();
        $voted = $actor->isGuest() ? [] : $this->db->table('qotd_votes')->where('user_id', $actor->id)
            ->whereIn('question_id', $pending->modelKeys())->pluck('question_id')->map(fn ($id) => (int) $id)->all();

        $row = fn (Question $question) => [
            'id' => $question->id,
            'body' => $question->body,
            'votes' => $question->votes_count,
            'voted' => in_array($question->id, $voted, true),
            'author' => $question->suggested ? $this->present->person($question->user) : null,
            'createdAt' => $question->created_at->toIso8601String(),
            'mine' => $question->user_id === $actor->id,
            'scheduledFor' => $question->scheduled_for,
        ];

        return new JsonResponse([
            'pending' => $pending->map($row)->values(),
            'mine' => Question::query()->where('user_id', $actor->id)->where('suggested', true)->whereNotNull('asked_on')->count(),
            // The pool is a surprise for members: only moderators see what's coming.
            'pool' => $moderator ? $this->upcoming()->map($row)->values() : null,
            'poolCount' => $moderator ? Question::query()->inPool()->count() : null,
            // "Ask this now" only replaces today's question while nobody has answered it.
            'answeredToday' => $moderator && ($current = Question::current()) && $current->isOpen()
                && Answer::query()->where('question_id', $current->id)->exists(),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Question> */
    private function upcoming(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->rotator->upcoming(self::POOL)->load('user');
    }

    private function streaks(User $actor): ResponseInterface
    {
        $current = Question::current();
        $alive = array_values(array_filter([$current?->asked_on, $current?->prev_asked_on]));

        $going = $alive ? Streak::query()->whereIn('last_day', $alive)->where('current', '>', 0)->with('user')
            ->orderByDesc('current')->orderByDesc('total')->limit(self::BOARD)->get() : new Collection();
        $best = Streak::query()->where('best', '>', 0)->with('user')->orderByDesc('best')->orderByDesc('total')->limit(self::BOARD)->get();
        $mine = $actor->isGuest() ? null : Streak::query()->find($actor->id);

        $row = fn (Streak $streak, int $days) => ['user' => $this->present->person($streak->user), 'days' => $days, 'total' => $streak->total];

        return new JsonResponse([
            'going' => $going->filter(fn (Streak $s) => $s->user !== null)->map(fn (Streak $s) => $row($s, $s->current))->values(),
            'best' => $best->filter(fn (Streak $s) => $s->user !== null)->map(fn (Streak $s) => $row($s, $s->best))->values(),
            'me' => $mine ? ['current' => $mine->live($current), 'best' => $mine->best, 'total' => $mine->total] : null,
        ]);
    }
}
