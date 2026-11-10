<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Streaks;
use ErnestDefoe\QuestionOfTheDay\Today;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/qotd/questions/{id}/answers: answer the question that's up (once).
 * PATCH /api/qotd/answers/{id}: edit your answer while it's up.
 * DELETE /api/qotd/answers/{id}: yours while it's up, or any as a moderator.
 */
class AnswerController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Rotator $rotator,
        private readonly Streaks $streaks,
        private readonly Today $today,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConnectionInterface $db,
        private readonly RateLimit $limits,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $this->rotator->ensureToday();

        return match ($request->getMethod()) {
            'POST' => $this->store($request),
            'PATCH' => $this->update($request),
            default => $this->delete($request),
        };
    }

    private function store(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::ANSWER);

        $question = Question::query()->find(Input::id($request));
        $current = Question::current();
        if (! $question || ! $current || $current->id !== $question->id || $current->closed_at) {
            return Errors::make(409, 'closed');
        }

        $body = Input::answer(Input::body($request)['body'] ?? '');
        if ($body === '' || mb_strlen($body) > Today::maxLength($this->settings)) {
            return Errors::make(422, 'answer_length');
        }

        if (Answer::query()->where('question_id', $question->id)->where('user_id', $actor->id)->exists()) {
            return Errors::make(409, 'already_answered');
        }

        // The unique index settles a double submit. In its own transaction (a savepoint inside an
        // outer one), because on PostgreSQL a failed statement otherwise aborts the whole transaction.
        try {
            $answer = new Answer();
            $answer->question_id = $question->id;
            $answer->user_id = $actor->id;
            $answer->body = $body;
            $answer->created_at = Carbon::now();
            $this->db->transaction(fn () => $answer->save());
        } catch (UniqueConstraintViolationException) {
            return Errors::make(409, 'already_answered');
        }

        $this->streaks->record($actor->id, $question);

        return new JsonResponse($this->today->payload($actor, Input::sort($request)), 201);
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $answer = Answer::query()->with('question')->find(Input::id($request));
        if (! $answer || $answer->user_id !== $actor->id) {
            return Errors::make(404, 'not_found');
        }
        if (! $answer->question?->isOpen()) {
            return Errors::make(409, 'closed');
        }
        if ($this->limits->exceeded($actor, 'edit', 20)) {
            return Errors::make(429, 'slow_down');
        }

        $body = Input::answer(Input::body($request)['body'] ?? '');
        if ($body === '' || mb_strlen($body) > Today::maxLength($this->settings)) {
            return Errors::make(422, 'answer_length');
        }

        if ($body !== $answer->body) {
            $answer->body = $body;
            $answer->edited_at = Carbon::now();
            $answer->save();
        }

        return new JsonResponse(['id' => $answer->id, 'body' => $answer->body, 'edited' => $answer->edited_at !== null]);
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $answer = Answer::query()->with('question')->find(Input::id($request));
        $question = $answer?->question;
        if (! $answer || ! $question) {
            return Errors::make(404, 'not_found');
        }

        $own = $answer->user_id === $actor->id && $question->isOpen();
        if (! $own && ! $actor->can(Settings::MODERATE)) {
            return Errors::make(403, 'permission_denied');
        }

        $this->db->transaction(function () use ($answer) {
            $this->db->table('qotd_reactions')->where('answer_id', $answer->id)->delete();
            $answer->delete();
        });
        // While the question is up, an answer taken away takes its streak credit with it.
        if ($question->isOpen()) {
            $this->streaks->undo($answer->user_id, $question);
        }

        if ($question->top_answer_id === $answer->id) {
            $this->rotator->refreshTop($question);
        }

        return new EmptyResponse(204);
    }
}
