<?php

namespace ErnestDefoe\QuestionOfTheDay\Tags;

use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;

/** With flarum/tags: the admin's tag for the daily discussion, if the poster may start a discussion there. */
class TagsInFlarum implements DiscussionTags
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function forPoster(User $poster): array|null|false
    {
        $id = (int) $this->settings->get(Settings::TAG);
        $tag = $id ? Tag::whereVisibleTo($poster)->find($id) : null;
        if ($tag) {
            // The API's process() skips the tag's own permission check: ask it here.
            return $poster->can('startDiscussion', $tag) ? [$id] : false;
        }

        return $poster->hasPermission('bypassTagCounts') ? null : false;
    }
}
