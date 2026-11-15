<?php

namespace ErnestDefoe\QuestionOfTheDay\Tags;

use Flarum\User\User;

/** Which tags the daily discussion goes in. Bound to TagsInFlarum only when flarum/tags is enabled (extend.php). */
interface DiscussionTags
{
    /**
     * @return list<int>|null|false the tag ids; null: the forum needs no tags; false: the poster can't post it
     */
    public function forPoster(User $poster): array|null|false;
}
