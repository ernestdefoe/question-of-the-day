<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/qotd/questions/{id}/ask: a moderator puts a pool question up now,
 * in place of today's, as long as nobody has answered today's yet (it goes
 * back into the pool). With nothing up today, it simply becomes today's.
 */
class AskController implements RequestHandlerInterface
{
    public function __construct(private readonly Rotator $rotator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan(Settings::MODERATE);

        $question = Question::query()->inPool()->find(Input::id($request));
        if (! $question) {
            return Errors::make(404, 'not_found');
        }

        return $this->rotator->swap($question)
            ? new JsonResponse(['id' => $question->id])
            : Errors::make(409, 'today_answered');
    }
}
