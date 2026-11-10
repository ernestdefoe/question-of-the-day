<?php

namespace ErnestDefoe\QuestionOfTheDay\Console;

use ErnestDefoe\QuestionOfTheDay\Day;
use ErnestDefoe\QuestionOfTheDay\Delivery;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use Flarum\Console\AbstractCommand;

/** Scheduled every minute (extend.php): puts up the day's question at local midnight and finishes its delivery. Safe to run twice at once. */
class RotateCommand extends AbstractCommand
{
    public function __construct(private readonly Rotator $rotator, private readonly Delivery $delivery, private readonly Day $day)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('question-of-the-day:rotate')->setDescription("Put up today's Question of the Day if it isn't up yet.");
    }

    protected function fire(): int
    {
        if ($question = $this->rotator->rotate($this->day->today())) {
            $this->info('Asked: '.$question->body);
        }
        $this->delivery->run();

        return 0;
    }
}
