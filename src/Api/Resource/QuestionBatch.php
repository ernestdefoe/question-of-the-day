<?php

namespace ErnestDefoe\QuestionOfTheDay\Api\Resource;

use ErnestDefoe\QuestionOfTheDay\Answer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Per-question values a list needs, in one query each however long the list:
 * whether the viewer voted, and how many answered. A field getter queues its
 * question and returns a closure; Flarum resolves closures after every other
 * getter, so the first one loads them all. Kept per request (WeakMap), never
 * on a model or across viewers.
 */
class QuestionBatch
{
    /** @var WeakMap<ServerRequestInterface, array{ids: array<int, true>, voted: array<int, true>|null, answers: array<int, int>|null}> */
    private WeakMap $state;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->state = new WeakMap();
    }

    public function voted(ServerRequestInterface $request, User $actor, int $id): \Closure
    {
        $this->queue($request, $id);

        return function () use ($request, $actor, $id) {
            $state = $this->state[$request];
            if ($state['voted'] === null) {
                $state['voted'] = $actor->isGuest() ? [] : array_fill_keys(
                    $this->db->table('qotd_votes')->where('user_id', $actor->id)->whereIn('question_id', array_keys($state['ids']))
                        ->pluck('question_id')->map(fn ($v) => (int) $v)->all(),
                    true
                );
                $this->state[$request] = $state;
            }

            return isset($state['voted'][$id]);
        };
    }

    public function answers(ServerRequestInterface $request, int $id): \Closure
    {
        $this->queue($request, $id);

        return function () use ($request, $id) {
            $state = $this->state[$request];
            if ($state['answers'] === null) {
                $counts = [];
                foreach (Answer::query()->whereIn('question_id', array_keys($state['ids']))->groupBy('question_id')
                    ->selectRaw('question_id, count(*) as n')->toBase()->get() as $row) {
                    $counts[(int) $row->question_id] = (int) $row->n;
                }
                $state['answers'] = $counts;
                $this->state[$request] = $state;
            }

            return $state['answers'][$id] ?? 0;
        };
    }

    private function queue(ServerRequestInterface $request, int $id): void
    {
        $state = $this->state[$request] ?? ['ids' => [], 'voted' => null, 'answers' => null];
        $state['ids'][$id] = true;
        $this->state[$request] = $state;
    }
}
