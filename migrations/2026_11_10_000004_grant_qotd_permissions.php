<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'question-of-the-day.view' => Group::GUEST_ID,
    'question-of-the-day.answer' => Group::MEMBER_ID,
    'question-of-the-day.suggest' => Group::MEMBER_ID,
    'question-of-the-day.moderate' => Group::MODERATOR_ID,
]);
