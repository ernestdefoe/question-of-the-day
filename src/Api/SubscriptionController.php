<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/qotd/subscription {on}: the daily "a new question is up" alert, on or off. */
class SubscriptionController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        $on = (bool) (Input::body($request)['on'] ?? true);
        $on
            ? $this->db->table('qotd_subscriptions')->insertOrIgnore(['user_id' => $actor->id, 'created_at' => Carbon::now()])
            : $this->db->table('qotd_subscriptions')->where('user_id', $actor->id)->delete();

        return new JsonResponse(['subscribed' => $on]);
    }
}
