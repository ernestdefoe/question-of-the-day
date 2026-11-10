<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Everything the front-page card and the /question page show, in one answer:
 * the question that's up (its answers only per Access), yesterday's question
 * with its top answer, your streak and reminder, and what you may do.
 */
class Today
{
    public function __construct(
        private readonly Rotator $rotator,
        private readonly Present $present,
        private readonly Day $day,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function payload(User $actor, string $sort = 'top', bool $moderating = false): array
    {
        $this->rotator->ensureToday();

        $current = Question::current();
        $previous = $current?->prev_asked_on
            ? Question::query()->where('asked_on', $current->prev_asked_on)->first()
            : null;

        $streak = $actor->isGuest() ? null : Streak::query()->find($actor->id);
        $moderator = $actor->can(Settings::MODERATE);

        return [
            'question' => $current ? $this->present->question($current, $actor, $sort, $moderating) : null,
            'previous' => $previous ? $this->present->brief($previous, $actor) + ['top' => $this->present->topOf($previous, $actor)] : null,
            'streak' => [
                'current' => $streak ? $streak->live($current) : 0,
                'best' => $streak ? $streak->best : 0,
                'total' => $streak ? $streak->total : 0,
            ],
            'subscribed' => ! $actor->isGuest() && Question::query()->getConnection()->table('qotd_subscriptions')->where('user_id', $actor->id)->exists(),
            'nextAt' => $this->day->nextMidnight()->toIso8601String(),
            'today' => $this->day->today(),
            'maxLength' => self::maxLength($this->settings),
            'can' => [
                'answer' => $actor->can(Settings::ANSWER),
                'suggest' => $actor->can(Settings::SUGGEST),
                'moderate' => $moderator,
            ],
            'pool' => $moderator ? Question::query()->inPool()->count() : null,
            'pending' => $moderator || $actor->can(Settings::SUGGEST) ? Question::query()->where('status', Question::PENDING)->count() : null,
        ];
    }

    public static function maxLength(SettingsRepositoryInterface $settings): int
    {
        $max = (int) $settings->get(Settings::MAX_LENGTH);

        return $max >= 20 && $max <= 2000 ? $max : Settings::DEFAULT_MAX_LENGTH;
    }
}
