<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Carbon\Carbon;
use DateTimeZone;
use Flarum\Settings\SettingsRepositoryInterface;

/** The forum's own calendar: a new question at local midnight in the admin's timezone. */
class Day
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function timezone(): string
    {
        $zone = (string) $this->settings->get(Settings::TIMEZONE);

        return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /** Today's date (Y-m-d) where the forum is. */
    public function today(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    /** When today ends where the forum is, as a UTC instant: the next question's moment. */
    public function nextMidnight(): Carbon
    {
        return Carbon::now($this->timezone())->startOfDay()->addDay()->utc();
    }
}
