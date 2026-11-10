<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Questions and answers as the API sends them. Answers' words only ever come
 * out of answers(), and only after Access::canSeeAnswers() said yes. Every
 * list loads its people and reactions in one query each, never per row.
 */
class Present
{
    public const PAGE = 30;

    public function __construct(
        private readonly SlugManager $slugs,
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * One asked question with everything its card shows: your answer, who
     * answered, and (if you may see them) a page of answers.
     *
     * @return array<string, mixed>
     */
    public function question(Question $question, User $actor, string $sort = 'top', bool $moderating = false): array
    {
        $mine = $actor->isGuest() ? null : Answer::query()->where('question_id', $question->id)->where('user_id', $actor->id)->first();
        $count = Answer::query()->where('question_id', $question->id)->count();
        $canSee = Access::canSeeAnswers($question, $actor, $mine !== null, $moderating);
        $moderating = $moderating && $canSee && $mine === null && $question->closed_at === null;

        // Faces, not words: who answered is no secret, what they said is.
        $answerers = Answer::query()->where('question_id', $question->id)->orderByDesc('id')->limit(5)->with('user')->get()
            ->map(fn (Answer $answer) => $this->person($answer->user))->filter()->values();

        $row = $this->brief($question, $actor) + [
            'answersCount' => $count,
            'answerers' => $answerers,
            'mine' => $mine ? ['id' => $mine->id, 'body' => $mine->body, 'createdAt' => $mine->created_at->toIso8601String(), 'editedAt' => $mine->edited_at?->toIso8601String()] : null,
            'canSeeAnswers' => $canSee,
            'moderating' => $moderating,
            'answers' => [],
            'hasMore' => false,
            'top' => null,
        ];

        if ($canSee) {
            $page = $this->answerPage($question, $actor, $sort, 0);
            $row['answers'] = $page['answers'];
            $row['hasMore'] = $page['hasMore'];
            $row['top'] = $question->closed_at && $question->top_answer_id ? $this->topOf($question, $actor) : null;
        }

        return $row;
    }

    /**
     * A page of answers. The caller has asked Access::canSeeAnswers().
     *
     * @return array{answers: list<array<string, mixed>>, hasMore: bool}
     */
    public function answerPage(Question $question, User $actor, string $sort, int $offset): array
    {
        $query = Answer::query()->where('question_id', $question->id)->with('user');
        $sort === 'new' ? $query->orderByDesc('id') : $query->orderByDesc('reactions_count')->orderBy('id');
        $answers = $query->offset($offset)->limit(self::PAGE + 1)->get();

        return [
            'answers' => $this->answers($answers->take(self::PAGE), $actor, $question),
            'hasMore' => $answers->count() > self::PAGE,
        ];
    }

    /**
     * @param Collection<int, Answer> $answers
     * @return list<array<string, mixed>>
     */
    public function answers(Collection $answers, User $actor, ?Question $question = null): array
    {
        $ids = $answers->map(fn (Answer $answer) => $answer->id)->values()->all();
        if (! $ids) {
            return [];
        }

        $counts = [];
        foreach ($this->db->table('qotd_reactions')->whereIn('answer_id', $ids)->groupBy('answer_id', 'type')
            ->select('answer_id', 'type')->selectRaw('count(*) as n')->get() as $row) {
            $counts[(int) $row->answer_id][$row->type] = (int) $row->n;
        }
        $mine = $actor->isGuest() ? [] : $this->db->table('qotd_reactions')->whereIn('answer_id', $ids)->where('user_id', $actor->id)
            ->pluck('type', 'answer_id')->all();

        return $answers->map(function (Answer $answer) use ($counts, $mine, $actor, $question) {
            $isMine = ! $actor->isGuest() && $answer->user_id === $actor->id;
            $open = $question ? $question->isOpen() : false;

            return [
                'id' => $answer->id,
                'questionId' => $answer->question_id,
                'body' => $answer->body,
                'author' => $this->person($answer->user),
                'createdAt' => $answer->created_at->toIso8601String(),
                'edited' => $answer->edited_at !== null,
                'reactions' => (object) ($counts[$answer->id] ?? []),
                'reactionsCount' => $answer->reactions_count,
                'myReaction' => $mine[$answer->id] ?? null,
                'mine' => $isMine,
                'canReact' => $open && ! $isMine && $actor->can(Settings::ANSWER),
                'canEdit' => $open && $isMine,
                'canDelete' => ($open && $isMine) || $actor->can(Settings::MODERATE),
            ];
        })->values()->all();
    }

    /**
     * A question's card without its answers: safe for any viewer of questions.
     *
     * @param Collection<int, Discussion>|null $discussions the list's discussions, loaded at once (keyed by id)
     * @return array<string, mixed>
     */
    public function brief(Question $question, User $actor, ?Collection $discussions = null): array
    {
        $discussion = null;
        if ($question->discussion_id) {
            $found = $discussions ? $discussions->get($question->discussion_id) : Discussion::query()->whereVisibleTo($actor)->find($question->discussion_id);
            $discussion = $found ? ['id' => $found->id, 'slug' => $found->slug] : null;
        }

        return [
            'id' => $question->id,
            'body' => $question->body,
            'askedOn' => $question->asked_on,
            'open' => $question->isOpen(),
            'closedAt' => $question->closed_at?->toIso8601String(),
            'suggestedBy' => $question->suggested ? $this->person($question->user) : null,
            'discussion' => $discussion,
        ];
    }

    /**
     * A closed question's top answer, the featured one. Only ever for closed questions.
     *
     * @return array<string, mixed>|null
     */
    public function topOf(Question $question, User $actor): ?array
    {
        if (! $question->closed_at || ! $question->top_answer_id || ! $actor->can(Settings::VIEW)) {
            return null;
        }
        $answer = $question->relationLoaded('topAnswer') ? $question->topAnswer : Answer::query()->with('user')->find($question->top_answer_id);

        return $answer ? ($this->answers(new Collection([$answer]), $actor, $question)[0] ?? null) : null;
    }

    /** @return array<string, mixed>|null */
    public function person(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->display_name,
            'username' => $user->username,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatar' => $user->avatar_url,
        ];
    }
}
