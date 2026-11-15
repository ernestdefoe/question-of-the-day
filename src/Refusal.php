<?php

namespace ErnestDefoe\QuestionOfTheDay;

use RuntimeException;

/** A request we turn down, with the code the browser turns into a sentence (Api\RefusalHandler). */
class Refusal extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
