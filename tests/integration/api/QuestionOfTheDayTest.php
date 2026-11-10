<?php

namespace ErnestDefoe\QuestionOfTheDay\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\QuestionOfTheDay\Answer;
use ErnestDefoe\QuestionOfTheDay\Delivery;
use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Rotator;
use ErnestDefoe\QuestionOfTheDay\Settings;
use ErnestDefoe\QuestionOfTheDay\Streak;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Notification\Notification;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class QuestionOfTheDayTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'Nachos with a suspicious cheese';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 15:00:00', 'UTC'));

        $this->extension('ernestdefoe-question-of-the-day');
        $this->setting(Settings::TIMEZONE, 'America/Chicago');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'sam', 'email' => 'sam@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
            Question::class => [
                ['id' => 1, 'body' => 'Worst stadium food you have ever had?', 'status' => 'approved', 'approved_at' => Carbon::now()->subDays(3), 'votes_count' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 2, 'body' => 'Best road trip you have taken?', 'status' => 'approved', 'approved_at' => Carbon::now()->subDays(2), 'votes_count' => 4, 'suggested' => true, 'user_id' => 4, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 3, 'body' => 'Mountains or beach?', 'status' => 'approved', 'approved_at' => Carbon::now()->subDays(1), 'votes_count' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 4, 'body' => 'Which snack would you take to a desert island?', 'status' => 'pending', 'suggested' => true, 'user_id' => 3, 'votes_count' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{int, mixed, string} */
    private function api(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, '/api/qotd'.$path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    private function moveTo(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, 'UTC'));
    }

    #[Test]
    public function the_most_upvoted_question_goes_up_once_a_day_in_the_forums_timezone(): void
    {
        [$status, $today] = $this->api('GET', '', 2);
        $this->assertSame(200, $status);
        $this->assertSame(2, $today['question']['id'], 'most votes first');
        $this->assertSame('2026-11-10', $today['question']['askedOn']);

        // Same day, many more calls: nothing changes.
        $this->assertNull($this->app()->getContainer()->make(Rotator::class)->rotate('2026-11-10'));
        $this->assertSame(1, Question::query()->whereNotNull('asked_on')->count());

        // 23:30 in Chicago is 05:30 UTC the next day: still the 10th there.
        $this->moveTo('2026-11-11 05:30:00');
        $this->assertSame(2, $this->api('GET', '', 2)[1]['question']['id']);

        // 00:30 in Chicago: the 11th.
        $this->moveTo('2026-11-11 06:30:00');
        $this->assertSame(1, $this->api('GET', '', 2)[1]['question']['id'], 'then the oldest');
        $this->assertNotNull(Question::query()->find(2)->closed_at);
    }

    #[Test]
    public function a_scheduled_question_goes_first_on_its_day(): void
    {
        $this->app();
        Question::query()->whereKey(3)->update(['scheduled_for' => '2026-11-10']);
        $this->assertSame(3, $this->api('GET', '', 2)[1]['question']['id']);

        // Scheduled through the API (as a moderator does), for tomorrow: not before.
        $this->assertSame(422, $this->api('PATCH', '/questions/1', 5, ['scheduledFor' => '2026-11-09'])[0]);
        $this->assertSame(200, $this->api('PATCH', '/questions/1', 5, ['scheduledFor' => '2026-11-11'])[0]);
        $this->moveTo('2026-11-11 07:00:00');
        $this->assertSame(1, $this->api('GET', '', 2)[1]['question']['id'], 'ahead of the more upvoted one');
    }

    #[Test]
    public function answers_stay_hidden_until_you_post_yours(): void
    {
        $this->api('GET', '', 2);
        [$status] = $this->api('POST', '/questions/2/answers', 3, ['body' => self::SECRET]);
        $this->assertSame(201, $status);

        // Nobody else sees it anywhere: not a member, not a guest, not a moderator who hasn't answered.
        foreach ([2, 4, 5, null] as $viewer) {
            foreach (['', '/questions/2', '/archive', '/streaks'] as $path) {
                [, $body, $raw] = $this->api('GET', $path, $viewer);
                $this->assertStringNotContainsString('suspicious cheese', $raw, "$path as ".var_export($viewer, true));
            }
            $this->assertSame($viewer ? 403 : 403, $this->api('GET', '/questions/2/answers', $viewer)[0]);
        }
        [, $today] = $this->api('GET', '', 2);
        $this->assertSame(1, $today['question']['answersCount']);
        $this->assertFalse($today['question']['canSeeAnswers']);
        $this->assertSame('dana', $today['question']['answerers'][0]['username'], 'faces, not words');

        // A moderator can open it to moderate, on purpose; a member can't.
        $this->assertStringContainsString('suspicious cheese', $this->api('GET', '', 5, [], ['moderate' => '1'])[2]);
        $this->assertStringNotContainsString('suspicious cheese', $this->api('GET', '', 2, [], ['moderate' => '1'])[2]);

        // Post yours, and there they are.
        [$status, $after] = $this->api('POST', '/questions/2/answers', 2, ['body' => 'A hot dog that was mostly bun']);
        $this->assertSame(201, $status);
        $this->assertTrue($after['question']['canSeeAnswers']);
        $this->assertContains(self::SECRET, array_column($after['question']['answers'], 'body'));

        // Once the next question is up, yesterday's answers are the archive: everyone reads them.
        $this->moveTo('2026-11-11 07:00:00');
        $this->assertStringContainsString('suspicious cheese', $this->api('GET', '/questions/2', null)[2]);
    }

    #[Test]
    public function one_answer_each_and_only_to_the_question_thats_up(): void
    {
        $this->api('GET', '', 2);
        $this->assertSame(201, $this->api('POST', '/questions/2/answers', 2, ['body' => 'First'])[0]);
        $this->assertSame(409, $this->api('POST', '/questions/2/answers', 2, ['body' => 'Second'])[0]);
        $this->assertSame(409, $this->api('POST', '/questions/1/answers', 2, ['body' => 'Not up'])[0]);
        $this->assertSame(422, $this->api('POST', '/questions/2/answers', 3, ['body' => str_repeat('x', 281)])[0]);
        $this->assertSame(201, $this->api('POST', '/questions/2/answers', 3, ['body' => str_repeat('x', 280)])[0]);
        $this->assertContains($this->api('POST', '/questions/2/answers', null, ['body' => 'Guest'])[0], [400, 401]);
        $this->assertSame(2, Answer::query()->count());

        $this->moveTo('2026-11-11 07:00:00');
        $this->api('GET', '', 2);
        $this->assertSame(409, $this->api('POST', '/questions/2/answers', 3, ['body' => 'Too late'])[0]);
        $mine = Answer::query()->where('user_id', 2)->first();
        $this->assertSame(409, $this->api('PATCH', '/answers/'.$mine->id, 2, ['body' => 'Edited late'])[0]);
    }

    #[Test]
    public function streaks_count_questions_in_a_row(): void
    {
        $this->api('GET', '', 2);
        $this->api('POST', '/questions/2/answers', 2, ['body' => 'Day one']);
        $this->moveTo('2026-11-11 07:00:00');
        [, $today] = $this->api('GET', '', 2);
        $this->assertSame(1, $today['streak']['current'], 'still alive: they can answer today to keep it');
        $this->api('POST', '/questions/'.$today['question']['id'].'/answers', 2, ['body' => 'Day two']);
        $this->assertSame(2, Streak::query()->find(2)->current);

        // A day with no question (nothing asked on the 12th) breaks nothing: the 13th follows the 11th.
        $this->moveTo('2026-11-13 07:00:00');
        [, $today] = $this->api('GET', '', 2);
        $this->assertSame(2, $today['streak']['current']);
        $this->api('POST', '/questions/'.$today['question']['id'].'/answers', 2, ['body' => 'Day three']);
        $this->assertSame(3, Streak::query()->find(2)->current);

        // A question missed does.
        Question::query()->forceCreate(['body' => 'One more for the pool?', 'status' => 'approved', 'approved_at' => Carbon::now()]);
        $this->moveTo('2026-11-14 07:00:00');
        $this->api('GET', '', 3);
        Question::query()->forceCreate(['body' => 'And one more for the pool?', 'status' => 'approved', 'approved_at' => Carbon::now()]);
        $this->moveTo('2026-11-15 07:00:00');
        [, $today] = $this->api('GET', '', 2);
        $this->assertSame(0, $today['streak']['current']);
        $this->assertSame(3, $today['streak']['best']);
        $this->api('POST', '/questions/'.$today['question']['id'].'/answers', 2, ['body' => 'Fresh start']);
        $this->assertSame(1, Streak::query()->find(2)->current);
        $this->assertSame(3, Streak::query()->find(2)->best);
    }

    #[Test]
    public function reactions_need_your_own_answer_and_pick_tomorrows_top_answer(): void
    {
        $this->api('GET', '', 2);
        foreach ([2 => 'Pretzel', 3 => 'Cold fries', 4 => 'Mystery meat'] as $user => $text) {
            $this->api('POST', '/questions/2/answers', $user, ['body' => $text]);
        }
        $fries = Answer::query()->where('user_id', 3)->first();
        $meat = Answer::query()->where('user_id', 4)->first();

        $this->assertSame(403, $this->api('POST', "/answers/{$fries->id}/react", 5, ['type' => 'haha'])[0], 'the moderator has not answered');
        $this->assertSame(403, $this->api('POST', "/answers/{$fries->id}/react", 3, ['type' => 'haha'])[0], 'not your own');
        $this->assertSame(422, $this->api('POST', "/answers/{$fries->id}/react", 2, ['type' => 'nope'])[0]);

        $this->api('POST', "/answers/{$meat->id}/react", 2, ['type' => 'haha']);
        $this->api('POST', "/answers/{$meat->id}/react", 2, ['type' => 'haha']);
        [$status, $row, $raw] = $this->api('POST', "/answers/{$meat->id}/react", 2, ['type' => 'fire']);
        $this->assertSame(200, $status, $raw);
        $this->assertSame(1, $row['reactionsCount'], 'one each, the type replaced');
        $this->assertSame(['fire' => 1], $row['reactions']);
        $this->api('POST', "/answers/{$meat->id}/react", 3, ['type' => 'love']);
        $this->api('POST', "/answers/{$fries->id}/react", 4, ['type' => 'like']);
        [, $row] = $this->api('POST', "/answers/{$fries->id}/react", 4, ['type' => null]);
        $this->assertSame(0, $row['reactionsCount']);

        $this->moveTo('2026-11-11 07:00:00');
        [, $today] = $this->api('GET', '', null);
        $this->assertSame(2, $today['previous']['id']);
        $this->assertSame('Mystery meat', $today['previous']['top']['body']);
        $this->assertSame(409, $this->api('POST', "/answers/{$fries->id}/react", 2, ['type' => 'like'])[0], 'reactions close with the question');

        // A moderator removes the top answer: the next best takes its place.
        $this->assertSame(204, $this->api('DELETE', "/answers/{$meat->id}", 5)[0]);
        $this->assertNull(Question::query()->find(2)->top_answer_id, 'no other answer had a reaction');
    }

    #[Test]
    public function members_suggest_and_vote_moderators_approve_and_the_pool_stays_a_surprise(): void
    {
        [$status] = $this->api('POST', '/questions', 2, ['body' => '  What is   your favorite   board game?  ']);
        $this->assertSame(201, $status);
        $this->assertSame('What is your favorite board game?', Question::query()->latest('id')->value('body'));
        $this->assertSame(409, $this->api('POST', '/questions', 3, ['body' => 'what is your favorite board game?'])[0], 'duplicate');
        $this->assertSame(422, $this->api('POST', '/questions', 3, ['body' => 'Hi?'])[0]);

        // Votes: once each, never your own.
        $this->assertSame(403, $this->api('POST', '/questions/4/vote', 3, ['vote' => true])[0]);
        $this->api('POST', '/questions/4/vote', 2, ['vote' => true]);
        $this->api('POST', '/questions/4/vote', 2, ['vote' => true]);
        $this->assertSame(1, Question::query()->find(4)->votes_count);
        $this->api('POST', '/questions/4/vote', 2, ['vote' => false]);
        $this->assertSame(0, Question::query()->find(4)->votes_count);
        $this->assertSame(404, $this->api('POST', '/questions/1/vote', 2, ['vote' => true])[0], 'only suggestions waiting in the queue');

        [, $queue] = $this->api('GET', '/queue', 2);
        $this->assertNull($queue['pool']);
        $this->assertCount(2, $queue['pending']);
        $this->assertSame(403, $this->api('GET', '/queue', null)[0]);

        // Members can't approve, not even their own.
        $this->assertSame(404, $this->api('PATCH', '/questions/4', 2, ['status' => 'approved'])[0]);
        $this->api('PATCH', '/questions/4', 3, ['status' => 'approved']);
        $this->assertSame('pending', Question::query()->find(4)->status);

        $this->api('PATCH', '/questions/4', 5, ['status' => 'approved']);
        [, $queue] = $this->api('GET', '/queue', 5);
        $this->assertSame(3, $queue['poolCount'], '1, 3 and 4: 2 went up today');

        // Moderators add a batch straight to the pool, skipping duplicates.
        [$status, $added] = $this->api('POST', '/questions', 5, ['body' => "Sweet or savory breakfast?\nMountains or beach?\nx"]);
        $this->assertSame(['added' => 1, 'skipped' => 2], $added);
        $this->assertSame(403, $this->api('POST', '/starter', 2)[0]);
    }

    #[Test]
    public function ask_now_swaps_todays_question_only_before_anyone_answers(): void
    {
        $this->api('GET', '', 2);
        $this->assertSame(200, $this->api('POST', '/questions/3/ask', 5)[0]);
        $this->assertSame(3, $this->api('GET', '', 2)[1]['question']['id']);
        $this->assertNull(Question::query()->find(2)->asked_on, 'back in the pool');

        $this->api('POST', '/questions/3/answers', 2, ['body' => 'Beach']);
        $this->assertSame(409, $this->api('POST', '/questions/1/ask', 5)[0]);
        $this->assertSame(403, $this->api('POST', '/questions/1/ask', 2)[0]);
    }

    #[Test]
    public function delivery_alerts_subscribers_once_and_posts_one_discussion(): void
    {
        $this->setting(Settings::DISCUSSION, '1');
        $this->api('POST', '/subscription', 3, ['on' => true]);
        $this->api('POST', '/subscription', 4, ['on' => true]);
        $this->api('GET', '', 2);

        $delivery = $this->app()->getContainer()->make(Delivery::class);
        $delivery->run();
        $delivery->run();

        $this->assertSame(1, Notification::query()->where('type', 'qotdNew')->where('user_id', 3)->count());
        $this->assertSame(0, Notification::query()->where('type', 'qotdNew')->where('user_id', 4)->count(), 'Sam suggested it: Sam gets the other alert');
        $this->assertSame(1, Notification::query()->where('type', 'qotdAsked')->where('user_id', 4)->count());
        $this->assertSame(1, Discussion::query()->count());
        $this->assertNotNull(Question::query()->find(2)->discussion_id);

        // The alert list renders (a subject without an API resource would 500 it).
        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 3]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('qotdNew', (string) $response->getBody());
    }

    #[Test]
    public function deleting_a_member_takes_back_their_reactions_and_votes(): void
    {
        $this->api('GET', '', 2);
        $this->api('POST', '/questions/2/answers', 2, ['body' => 'Mine']);
        $this->api('POST', '/questions/2/answers', 3, ['body' => 'Theirs']);
        $theirs = Answer::query()->where('user_id', 3)->first();
        $this->api('POST', "/answers/{$theirs->id}/react", 2, ['type' => 'love']);
        $this->api('POST', '/questions/4/vote', 2, ['vote' => true]);

        $response = $this->send($this->request('DELETE', '/api/users/2', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $this->assertSame(0, $theirs->refresh()->reactions_count);
        $this->assertSame(0, Question::query()->find(4)->votes_count);
        $this->assertSame(0, Answer::query()->where('user_id', 2)->count());
    }
}
