<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Refusal;
use Flarum\Foundation\ErrorHandling\HandledError;

/** A Refusal becomes a JSON:API error with its code, which the browser turns into a sentence. */
class RefusalHandler
{
    public function handle(Refusal $refusal): HandledError
    {
        return new HandledError($refusal, $refusal->reason, $refusal->status);
    }
}
