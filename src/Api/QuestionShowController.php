<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Access;
use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Present;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/qotd/questions/{id}: an asked question (today's or one in the
 * archive) with its answers, per Access. GET …/answers: the next page.
 * Questions never asked (the pool, the queue) aren't served here.
 */
class QuestionShowController implements RequestHandlerInterface
{
    public function __construct(private readonly Present $present, private readonly Rotator $rotator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        $this->rotator->ensureToday();

        $question = Question::query()->whereNotNull('asked_on')->find(Input::id($request));
        if (! $question) {
            return Errors::make(404, 'not_found');
        }

        $sort = Input::sort($request);
        $moderating = Input::moderating($request, $actor);

        if (str_ends_with($request->getUri()->getPath(), '/answers')) {
            $answered = ! $actor->isGuest() && Answer::query()->where('question_id', $question->id)->where('user_id', $actor->id)->exists();
            if (! Access::canSeeAnswers($question, $actor, $answered, $moderating)) {
                return Errors::make(403, 'answer_first');
            }

            return new JsonResponse($this->present->answerPage($question, $actor, $sort, Input::offset($request)));
        }

        return new JsonResponse(['question' => $this->present->question($question, $actor, $sort, $moderating)]);
    }
}
