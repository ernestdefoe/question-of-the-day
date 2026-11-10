<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Notification\AskedBlueprint;
use ErnestDefoe\QuestionOfTheDay\Notification\NewQuestionBlueprint;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What follows a new question going up: the member who suggested it hears
 * it was picked, subscribers get their alert, and (if the admin wants it) the
 * question gets its own discussion. Every step is idempotent, so the queue,
 * the scheduler and a crash halfway can all run it again safely:
 *
 *  - the submitter's alert is one blueprint per question (a second sync is a no-op);
 *  - subscribers go in chunks by user id behind notify_cursor, skipping
 *    anyone who already has it; notified_at marks the end;
 *  - the discussion is claimed with posted_at IS NULL and released for a
 *    retry (three tries) if posting throws.
 *
 * Only the question that's up gets delivered: a day that's over is over.
 */
class Delivery
{
    private const CHUNK = 500;
    private const CHUNKS_PER_RUN = 20;
    private const MAX_POST_ATTEMPTS = 3;

    public function __construct(
        private readonly NotificationSyncer $notifications,
        private readonly JsonApi $api,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $log,
    ) {
    }

    /** @param int $chunks how many chunks of subscribers this run may alert (a page request's queue may be sync). */
    public function run(int $chunks = self::CHUNKS_PER_RUN): void
    {
        $question = Question::current();
        if (! $question || $question->closed_at) {
            return;
        }

        if ($question->notified_at === null) {
            $this->guard($question, fn () => $this->notify($question, $chunks));
        }

        if ($question->posted_at === null && $question->post_attempts < self::MAX_POST_ATTEMPTS && $this->settings->get(Settings::DISCUSSION)) {
            $this->guard($question, fn () => $this->post($question));
        }
    }

    private function notify(Question $question, int $chunks): void
    {
        if ($question->notify_cursor === 0 && $question->suggested && $question->user) {
            $this->notifications->sync(new AskedBlueprint($question), [$question->user]);
        }

        $subject = $question->user ?? User::query()->whereHas('groups', fn ($q) => $q->whereKey(Group::ADMINISTRATOR_ID))->orderBy('id')->first();
        if (! $subject) {
            $question->notified_at = Carbon::now();
            $question->save();

            return;
        }
        $blueprint = new NewQuestionBlueprint($question, $subject);

        // Only members who may still see the question. Asked once per run, not per member.
        $groups = Permission::query()->where('permission', Settings::VIEW)->pluck('group_id')->all();
        $everyone = (bool) array_intersect($groups, [Group::GUEST_ID, Group::MEMBER_ID]);
        $groups[] = Group::ADMINISTRATOR_ID;
        // Never the member who suggested it: they got their own alert.
        $skip = $question->suggested ? (int) $question->user_id : 0;

        for ($i = 0; $i < $chunks; $i++) {
            $chunk = User::query()
                ->whereIn('id', fn ($q) => $q->select('user_id')->from('qotd_subscriptions'))
                ->where('id', '>', $question->notify_cursor)
                ->when(! $everyone, fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $groups)))
                ->orderBy('id')->limit(self::CHUNK)->get();

            if ($chunk->isEmpty()) {
                $question->notified_at = Carbon::now();
                $question->save();

                return;
            }

            $users = $chunk->filter(fn (User $user) => $user->id !== $skip);
            $have = Notification::query()->matchingBlueprint($blueprint)->whereIn('user_id', $users->modelKeys())->pluck('user_id')->all();
            $new = $users->reject(fn (User $user) => in_array($user->id, $have))->values()->all();
            if ($new) {
                foreach (NotificationSyncer::getNotificationDrivers() as $driver) {
                    $driver->send($blueprint, $new);
                }
            }

            $question->notify_cursor = (int) $chunk->last()->id;
            $question->save();
        }
    }

    private function post(Question $question): void
    {
        $poster = $this->poster();
        $tags = $poster ? $this->tagsFor($poster) : false;
        if (! $poster || $tags === false || ! $poster->can('startDiscussion')) {
            // Nobody can post it: no discussion today, and nobody tries again.
            Question::query()->whereKey($question->id)->whereNull('posted_at')->update(['posted_at' => Carbon::now()]);
            $this->log->warning('[question-of-the-day] no member can start the daily discussion: check the poster and tag settings.');

            return;
        }

        if (Question::query()->whereKey($question->id)->whereNull('posted_at')->update(['posted_at' => Carbon::now()]) !== 1) {
            return;
        }

        $t = fn (string $key, array $params = []) => (string) $this->translator->trans('ernestdefoe-question-of-the-day.lib.discussion.'.$key, $params);
        $title = $t('title', ['question' => $question->body]);
        if (mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 79).'…';
        }
        $content = implode("\n\n", array_filter([
            $question->body,
            $question->suggested && $question->user ? $t('suggested', ['name' => $question->user->display_name]) : null,
            $t('answer', ['url' => $this->url->to('forum')->route('question-of-the-day')]),
        ]));

        $data = ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => $content]];
        if ($tags) {
            $data['relationships']['tags']['data'] = array_map(fn (int $id) => ['type' => 'tags', 'id' => (string) $id], $tags);
        }

        try {
            /** @var Discussion $discussion */
            $discussion = $this->api->forResource(DiscussionResource::class)->forEndpoint('create')
                ->process(['data' => $data], [], ['actor' => $poster]);
            Question::query()->whereKey($question->id)->update(['discussion_id' => $discussion->id]);
        } catch (Throwable $e) {
            $this->log->warning('[question-of-the-day] question '.$question->id.' could not be posted: '.$e->getMessage());
            Question::query()->whereKey($question->id)->update(['posted_at' => null, 'post_attempts' => $question->post_attempts + 1]);
        }
    }

    /** The member the daily discussion is posted as: the admin's choice, or the first administrator. */
    private function poster(): ?User
    {
        $name = trim((string) $this->settings->get(Settings::POSTER));
        $user = $name !== '' ? User::query()->where('username', $name)->first() : null;

        return $user ?? User::query()->whereHas('groups', fn ($q) => $q->whereKey(Group::ADMINISTRATOR_ID))->orderBy('id')->first();
    }

    /**
     * The admin's tag for the daily discussion, if the poster may start one there.
     *
     * @return list<int>|null|false null: no tags needed; false: can't post.
     */
    private function tagsFor(User $poster): array|null|false
    {
        if (! $this->extensions->isEnabled('flarum-tags') || ! class_exists(\Flarum\Tags\Tag::class)) {
            return null;
        }

        $id = (int) $this->settings->get(Settings::TAG);
        $tag = $id ? \Flarum\Tags\Tag::whereVisibleTo($poster)->find($id) : null;
        if ($tag) {
            // The API's process() skips the tag's own permission check: ask it here.
            return $poster->can('startDiscussion', $tag) ? [$id] : false;
        }

        return $poster->hasPermission('bypassTagCounts') ? null : false;
    }

    private function guard(Question $question, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $this->log->error('[question-of-the-day] question '.$question->id.': '.$e->getMessage());
        }
    }
}
