<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Day;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Starter;
use ErnestDefoe\QuestionOfTheDay\Streaks;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The question pool.
 *
 * POST /api/qotd/questions: a member suggests one (it waits in the queue for
 * votes and a moderator); a moderator adds any number straight to the pool,
 * one per line. POST /api/qotd/starter: a moderator adds the starter pack.
 * PATCH /api/qotd/questions/{id}: moderators approve, reject, reword or
 * schedule; a member rewords their own while it waits. DELETE: moderators,
 * or the member while theirs waits.
 */
class QuestionController implements RequestHandlerInterface
{
    public function __construct(private readonly Rotator $rotator, private readonly Day $day, private readonly RateLimit $limits, private readonly Streaks $streaks)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (str_ends_with($request->getUri()->getPath(), '/starter')) {
            $actor->assertCan(Settings::MODERATE);

            return $this->add($actor, Starter::QUESTIONS);
        }

        return match ($request->getMethod()) {
            'POST' => $this->store($request, $actor),
            'PATCH' => $this->update($request, $actor),
            default => $this->delete($request, $actor),
        };
    }

    private function store(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $body = Input::body($request);

        if ($actor->can(Settings::MODERATE)) {
            $lines = is_array($body['bodies'] ?? null) ? $body['bodies'] : preg_split('/\R/u', is_string($body['body'] ?? null) ? $body['body'] : '');

            return $this->add($actor, array_slice(array_filter((array) $lines, 'is_string'), 0, 200), Input::date($body['scheduledFor'] ?? null));
        }

        $actor->assertCan(Settings::SUGGEST);
        $text = Input::question($body['body'] ?? '');
        if (! Input::questionOk($text)) {
            return Errors::make(422, 'question_length');
        }
        if (Input::duplicate($text)) {
            return Errors::make(409, 'duplicate');
        }
        if (Question::query()->where('user_id', $actor->id)->where('status', Question::PENDING)->count() >= Settings::MAX_PENDING) {
            return Errors::make(429, 'too_many_pending');
        }
        if ($this->limits->exceeded($actor, 'suggest', 5) || $this->limits->dailyExceeded($actor, 'suggest', Settings::MAX_SUGGEST_PER_DAY)) {
            return Errors::make(429, 'slow_down');
        }

        $question = new Question();
        $question->body = $text;
        $question->status = Question::PENDING;
        $question->suggested = true;
        $question->user_id = $actor->id;
        $question->save();

        return new JsonResponse(['id' => $question->id], 201);
    }

    /** @param array<int, string> $lines */
    private function add(User $actor, array $lines, ?string $scheduledFor = null): ResponseInterface
    {
        if ($this->limits->exceeded($actor, 'add', 10)) {
            return Errors::make(429, 'slow_down');
        }

        $added = $skipped = 0;
        $seen = [];
        foreach ($lines as $line) {
            $text = Input::question($line);
            if ($text === '') {
                continue;
            }
            $key = mb_strtolower($text);
            if (! Input::questionOk($text) || isset($seen[$key]) || Input::duplicate($text)) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;

            $question = new Question();
            $question->body = $text;
            $question->status = Question::APPROVED;
            $question->approved_at = Carbon::now();
            $question->user_id = $actor->id;
            $question->scheduled_for = $scheduledFor !== null && $scheduledFor >= $this->day->today() ? $scheduledFor : null;
            $question->save();
            $added++;
        }

        if ($added) {
            $this->rotator->forget();
        }

        return new JsonResponse(['added' => $added, 'skipped' => $skipped], $added ? 201 : 200);
    }

    private function update(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $question = Question::query()->find(Input::id($request));
        $moderator = $actor->can(Settings::MODERATE);
        if (! $question || (! $moderator && ! ($question->user_id === $actor->id && $question->status === Question::PENDING))) {
            return Errors::make(404, 'not_found');
        }
        $body = Input::body($request);

        if (array_key_exists('body', $body)) {
            if ($question->isAsked()) {
                return Errors::make(409, 'already_asked');
            }
            $text = Input::question($body['body']);
            if (! Input::questionOk($text)) {
                return Errors::make(422, 'question_length');
            }
            if (Input::duplicate($text, $question->id)) {
                return Errors::make(409, 'duplicate');
            }
            // Votes were for the old words: the author can't change them under the voters, and a moderator's rewording starts the count again.
            if ($text !== $question->body && $question->votes_count > 0) {
                if (! $moderator) {
                    return Errors::make(409, 'has_votes');
                }
                Question::query()->getConnection()->table('qotd_votes')->where('question_id', $question->id)->delete();
                $question->votes_count = 0;
            }
            $question->body = $text;
        }

        if ($moderator && isset($body['status']) && ! $question->isAsked()) {
            if ($body['status'] === Question::APPROVED && $question->status !== Question::APPROVED) {
                $question->status = Question::APPROVED;
                $question->approved_at = Carbon::now();
            } elseif ($body['status'] === Question::REJECTED) {
                $question->status = Question::REJECTED;
                $question->scheduled_for = null;
            }
        }

        if ($moderator && array_key_exists('scheduledFor', $body) && $question->status === Question::APPROVED && ! $question->isAsked()) {
            $date = Input::date($body['scheduledFor']);
            if ($date !== null && $date < $this->day->today()) {
                return Errors::make(422, 'date_past');
            }
            $question->scheduled_for = $date;
        }

        $question->save();
        $this->rotator->forget();

        return new JsonResponse(['id' => $question->id, 'status' => $question->status, 'body' => $question->body, 'scheduledFor' => $question->scheduled_for]);
    }

    private function delete(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $question = Question::query()->find(Input::id($request));
        $own = $question && $question->user_id === $actor->id && $question->status === Question::PENDING;
        if (! $question || (! $own && ! $actor->can(Settings::MODERATE))) {
            return Errors::make(404, 'not_found');
        }

        // Deleting the question that's up takes its answers' streak credit back first.
        if ($question->isOpen()) {
            Answer::query()->where('question_id', $question->id)->pluck('user_id')
                ->each(fn ($userId) => $this->streaks->undo((int) $userId, $question));
        }
        $question->delete();
        $this->rotator->forget();

        return new EmptyResponse(204);
    }
}
