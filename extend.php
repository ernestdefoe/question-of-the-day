<?php

/*
 * Question of the Day: one prompt a day, answered in a line.
 *
 * The Rotator puts up the next question from the pool at local midnight (the
 * scheduler every minute, page requests too for forums without one); the
 * unique day index means a day only ever gets one. Answers to the question
 * that's up are hidden from anyone who hasn't posted their own: Access is
 * the one gate, Present the one place they are written out, and only the
 * plain /api/qotd endpoints serve them. Everything else (the pool, the
 * queue, the archive, streaks) is JSON:API resources; the only answers those
 * can reach are closed questions' (the public archive), because the
 * qotd-answers scope admits nothing else. When the next question goes up,
 * yesterday's closes with its top answer, which tomorrow's card features.
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

    // The pool, the queue, the archive and the streak boards are JSON:API resources. A question
    // resource leads to an answer only as a CLOSED question's topAnswer, through qotd-answers, whose
    // scope admits nothing else.
    new Extend\ApiResource(Api\Resource\QuestionResource::class),
    new Extend\ApiResource(Api\Resource\AnswerResource::class),
    new Extend\ApiResource(Api\Resource\StreakResource::class),

    // Today's question and its answers stay plain endpoints on purpose: they return answers only
    // after Access::canSeeAnswers() (you posted yours), a gate a JSON:API include could walk around.
    (new Extend\Routes('api'))
        ->get('/qotd', 'qotd.today', Api\TodayController::class)
        ->get('/qotd/questions/{id:\d+}', 'qotd.questions.show', Api\QuestionShowController::class)
        ->get('/qotd/questions/{id:\d+}/answers', 'qotd.answers.index', Api\QuestionShowController::class)
        ->post('/qotd/questions/{id:\d+}/answers', 'qotd.answers.create', Api\AnswerController::class)
        ->patch('/qotd/answers/{id:\d+}', 'qotd.answers.update', Api\AnswerController::class)
        ->delete('/qotd/answers/{id:\d+}', 'qotd.answers.delete', Api\AnswerController::class)
        ->post('/qotd/answers/{id:\d+}/react', 'qotd.answers.react', Api\ReactController::class),

    (new Extend\ErrorHandling())
        ->handler(\ErnestDefoe\QuestionOfTheDay\Refusal::class, Api\RefusalHandler::class),

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
