<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/qotd/questions/{id}/vote {vote}: upvote a suggestion waiting in
 * the queue, or take the vote back. One each (the primary key), never your
 * own; the count only moves when a row really went in or out.
 */
class VoteController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db, private readonly RateLimit $limits)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::SUGGEST);

        $question = Question::query()->where('status', Question::PENDING)->find(Input::id($request));
        if (! $question) {
            return Errors::make(404, 'not_found');
        }
        if ($question->user_id === $actor->id) {
            return Errors::make(403, 'own_question');
        }
        if ($this->limits->exceeded($actor, 'vote', 60)) {
            return Errors::make(429, 'slow_down');
        }

        $vote = (bool) (Input::body($request)['vote'] ?? true);
        $rows = $this->db->table('qotd_votes')->where('question_id', $question->id)->where('user_id', $actor->id);

        if ($vote) {
            if ($this->db->table('qotd_votes')->insertOrIgnore(['question_id' => $question->id, 'user_id' => $actor->id, 'created_at' => Carbon::now()]) === 1) {
                Question::query()->whereKey($question->id)->increment('votes_count');
            }
        } elseif ($rows->delete() === 1) {
            Question::query()->whereKey($question->id)->where('votes_count', '>', 0)->decrement('votes_count');
        }

        return new JsonResponse(['id' => $question->id, 'votes' => (int) Question::query()->whereKey($question->id)->value('votes_count'), 'voted' => $vote]);
    }
}
