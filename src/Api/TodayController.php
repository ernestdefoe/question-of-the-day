<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Today;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/qotd: the front-page card and the /question page. */
class TodayController implements RequestHandlerInterface
{
    public function __construct(private readonly Today $today)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        return new JsonResponse($this->today->payload($actor, Input::sort($request), Input::moderating($request, $actor)));
    }
}
