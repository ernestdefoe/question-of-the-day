<?php

namespace ErnestDefoe\QuestionOfTheDay;

final class Settings
{
    public const VIEW = 'question-of-the-day.view';
    public const ANSWER = 'question-of-the-day.answer';
    public const SUGGEST = 'question-of-the-day.suggest';
    public const MODERATE = 'question-of-the-day.moderate';

    public const NAV = 'question-of-the-day.nav';
    public const HERO = 'question-of-the-day.hero';
    public const TIMEZONE = 'question-of-the-day.timezone';
    public const MAX_LENGTH = 'question-of-the-day.max_length';
    public const DISCUSSION = 'question-of-the-day.discussion';
    public const TAG = 'question-of-the-day.tag';
    public const POSTER = 'question-of-the-day.poster';

    public const DEFAULT_MAX_LENGTH = 280;
    public const QUESTION_MIN = 8;
    public const QUESTION_MAX = 200;
    /** Suggestions one member may have waiting at once, and send in a day. */
    public const MAX_PENDING = 5;
    public const MAX_SUGGEST_PER_DAY = 10;

    public const REACTIONS = ['like', 'love', 'haha', 'fire', 'wow'];
}
