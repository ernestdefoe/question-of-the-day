<?php

namespace ErnestDefoe\QuestionOfTheDay\Tags;

use Flarum\User\User;

/** Without flarum/tags a discussion needs no tags. */
class NoTags implements DiscussionTags
{
    public function forPoster(User $poster): array|null|false
    {
        return null;
    }
}
