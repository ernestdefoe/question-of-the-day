<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** On the forum payload: whether the viewer may see questions (their own permissions, already loaded: no query). */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('qotdCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
        ];
    }
}
