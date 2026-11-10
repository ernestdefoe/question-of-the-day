<?php

namespace ErnestDefoe\QuestionOfTheDay\Job;

use ErnestDefoe\QuestionOfTheDay\Delivery;
use Flarum\Queue\AbstractJob;

/**
 * Queued when a question goes up. On a forum without a queue worker this runs
 * inside the page request that asked the question, so it alerts one small
 * chunk of subscribers; the scheduler's run every minute finishes the rest.
 */
class Deliver extends AbstractJob
{
    public function handle(Delivery $delivery): void
    {
        $delivery->run(1, Delivery::REQUEST_CHUNK);
    }
}
