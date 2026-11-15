<?php

namespace ErnestDefoe\QuestionOfTheDay\Tags;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered by extend.php only when flarum/tags is enabled: the daily discussion then goes in the admin's tag. */
class TagsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(DiscussionTags::class, TagsInFlarum::class);
    }
}
