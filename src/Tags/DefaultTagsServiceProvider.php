<?php

namespace ErnestDefoe\QuestionOfTheDay\Tags;

use Flarum\Foundation\AbstractServiceProvider;

/** Always registered: without flarum/tags the daily discussion needs no tags (TagsServiceProvider overrides it when Tags is on). */
class DefaultTagsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bindIf(DiscussionTags::class, NoTags::class);
    }
}
