<?php

/*
 * Question of the Day: one prompt a day, answered in a line.
 *
 * The Rotator puts up the next question from the pool at local midnight (the
 * scheduler every minute, page requests too for forums without one); the
 * unique day index means a day only ever gets one. Answers to the question
 * that's up are hidden from anyone who hasn't posted their own: Access is
 * the one gate, Present the one place answers are written out, and only
 * these plain endpoints serve them (no API resource, so no include can pull
 * one in). When the next question goes up, yesterday's closes with its top
 * answer, which tomorrow's card features.
 *
 * forum.js wires routes, the front-page card and notifications; the pages
 * are a lazy chunk.
 */

use ErnestDefoe\QuestionOfTheDay\Api;
use ErnestDefoe\QuestionOfTheDay\Console\RotateCommand;
use ErnestDefoe\QuestionOfTheDay\Gdpr\Forget;
use ErnestDefoe\QuestionOfTheDay\Gdpr\QotdData;
use ErnestDefoe\QuestionOfTheDay\Http\PageContent;
use ErnestDefoe\QuestionOfTheDay\Notification\AskedBlueprint;
use ErnestDefoe\QuestionOfTheDay\Notification\NewQuestionBlueprint;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pages are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/question', 'question-of-the-day', PageContent::class)
        ->route('/question/{id:\d+}', 'question-of-the-day.question', PageContent::class)
        ->route('/questions', 'question-of-the-day.questions', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-question-of-the-day', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/qotd', 'qotd.today', Api\TodayController::class)
        ->get('/qotd/archive', 'qotd.archive', Api\ListController::class)
        ->get('/qotd/queue', 'qotd.queue', Api\ListController::class)
        ->get('/qotd/streaks', 'qotd.streaks', Api\ListController::class)
        ->post('/qotd/subscription', 'qotd.subscription', Api\SubscriptionController::class)
        ->post('/qotd/starter', 'qotd.starter', Api\QuestionController::class)
        ->post('/qotd/questions', 'qotd.questions.create', Api\QuestionController::class)
        ->get('/qotd/questions/{id:\d+}', 'qotd.questions.show', Api\QuestionShowController::class)
        ->patch('/qotd/questions/{id:\d+}', 'qotd.questions.update', Api\QuestionController::class)
        ->delete('/qotd/questions/{id:\d+}', 'qotd.questions.delete', Api\QuestionController::class)
        ->post('/qotd/questions/{id:\d+}/vote', 'qotd.questions.vote', Api\VoteController::class)
        ->post('/qotd/questions/{id:\d+}/ask', 'qotd.questions.ask', Api\AskController::class)
        ->get('/qotd/questions/{id:\d+}/answers', 'qotd.answers.index', Api\QuestionShowController::class)
        ->post('/qotd/questions/{id:\d+}/answers', 'qotd.answers.create', Api\AnswerController::class)
        ->patch('/qotd/answers/{id:\d+}', 'qotd.answers.update', Api\AnswerController::class)
        ->delete('/qotd/answers/{id:\d+}', 'qotd.answers.delete', Api\AnswerController::class)
        ->post('/qotd/answers/{id:\d+}/react', 'qotd.answers.react', Api\ReactController::class),

    (new Extend\Console())
        ->command(RotateCommand::class)
        ->schedule(RotateCommand::class, function (ScheduledEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    // The daily alert is opt-in (the reminder switch), so it can be on by default for those who opt in.
    (new Extend\Notification())
        ->type(NewQuestionBlueprint::class, ['alert'])
        ->type(AskedBlueprint::class, ['alert']),

    // The viewer's own permissions: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('qotdCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
        ]),

    // String literals, not Settings::… constants: extend.php runs at boot, and a class lookup here
    // fatals every request on a worker whose autoload map is stale mid-install. (Inside closures is fine.)
    (new Extend\Settings())
        ->default('question-of-the-day.nav', true)
        ->default('question-of-the-day.hero', true)
        ->default('question-of-the-day.max_length', 280)
        ->serializeToForum('qotdNav', 'question-of-the-day.nav', 'boolval')
        ->serializeToForum('qotdHero', 'question-of-the-day.hero', 'boolval'),

    // Before the user row goes: the foreign keys take their rows, but not the counts they added to.
    (new Extend\Event())
        ->listen(Deleting::class, fn (Deleting $event) => Forget::user($event->user->id)),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(QotdData::class),
        ]),
];
