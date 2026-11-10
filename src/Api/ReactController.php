<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Present;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/qotd/answers/{id}/react {type}: react to an answer to the
 * question that's up (one reaction each; a new type replaces yours, null takes
 * it back). Only members who answered may: they're the only ones who can see
 * the answers. Not your own. Reactions close with the question, so the top
 * answer it was given stays true.
 */
class ReactController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Present $present, private readonly RateLimit $limits)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::ANSWER);

        $answer = Answer::query()->with('question')->find(Input::id($request));
        $question = $answer?->question;
        if (! $answer || ! $question) {
            return Errors::make(404, 'not_found');
        }
        if (! $question->isOpen()) {
            return Errors::make(409, 'closed');
        }
        if (! Answer::query()->where('question_id', $question->id)->where('user_id', $actor->id)->exists()) {
            return Errors::make(403, 'answer_first');
        }
        if ($answer->user_id === $actor->id) {
            return Errors::make(403, 'own_answer');
        }
        if ($this->limits->exceeded($actor, 'react', 120)) {
            return Errors::make(429, 'slow_down');
        }

        $type = Input::body($request)['type'] ?? null;
        if ($type !== null && ! in_array($type, Settings::REACTIONS, true)) {
            return Errors::make(422, 'reaction');
        }

        try {
            $this->react($answer->id, $actor->id, $type);
        } catch (UniqueConstraintViolationException) {
            // A double tap raced us to the first reaction: it's there now, so make it this type.
            $this->react($answer->id, $actor->id, $type);
        }

        $answer->refresh()->load('user');

        return new JsonResponse($this->present->answers(new Collection([$answer]), $actor, $question)[0]);
    }

    private function react(int $answerId, int $userId, ?string $type): void
    {
        $this->db->transaction(function () use ($answerId, $userId, $type) {
            $rows = $this->db->table('qotd_reactions')->where('answer_id', $answerId)->where('user_id', $userId);
            $existing = (clone $rows)->lockForUpdate()->value('type');

            if ($type === null) {
                if ($existing !== null && $rows->delete() === 1) {
                    Answer::query()->whereKey($answerId)->where('reactions_count', '>', 0)->decrement('reactions_count');
                }
            } elseif ($existing === null) {
                $this->db->table('qotd_reactions')->insert(['answer_id' => $answerId, 'user_id' => $userId, 'type' => $type, 'created_at' => Carbon::now()]);
                Answer::query()->whereKey($answerId)->increment('reactions_count');
            } elseif ($existing !== $type) {
                $rows->update(['type' => $type]);
            }
        });
    }
}
