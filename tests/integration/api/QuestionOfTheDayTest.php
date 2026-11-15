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
        $request = $this->request($method, str_starts_with($path, '/api/') ? $path : '/api/qotd'.$path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** The qotd-questions resource: $path after /api/qotd-questions. */
    private function res(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        return $this->api($method, '/api/qotd-questions'.$path, $as, $body, $query);
    }

    /** A JSON:API document for the qotd-questions resource. */
    private function doc(array $attributes, ?int $id = null): array
    {
        return ['data' => array_filter(['type' => 'qotd-questions', 'id' => $id ? (string) $id : null, 'attributes' => $attributes])];
    }

    /** @return list<int> */
    private function ids(array $document): array
    {
        return array_map(fn ($row) => (int) $row['id'], $document['data'] ?? []);
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
        $this->assertSame(422, $this->res('PATCH', '/1', 5, $this->doc(['scheduledFor' => '2026-11-09'], 1))[0]);
        $this->assertSame(200, $this->res('PATCH', '/1', 5, $this->doc(['scheduledFor' => '2026-11-11'], 1))[0]);
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
            foreach ([['', []], ['/questions/2', []], ['/api/qotd-questions', ['filter' => ['list' => 'archive'], 'include' => 'topAnswer,topAnswer.user']], ['/api/qotd-questions/2', ['include' => 'topAnswer,user']], ['/api/qotd-streaks', ['include' => 'user']]] as [$path, $query]) {
                [, $body, $raw] = $this->api('GET', $path, $viewer, [], $query);
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
        [$status] = $this->res('POST', '', 2, $this->doc(['body' => '  What is   your favorite   board game?  ']));
        $this->assertSame(201, $status);
        $this->assertSame('What is your favorite board game?', Question::query()->latest('id')->value('body'));
        $this->assertSame(409, $this->res('POST', '', 3, $this->doc(['body' => 'what is your favorite board game?']))[0], 'duplicate');
        $this->assertSame(422, $this->res('POST', '', 3, $this->doc(['body' => 'Hi?']))[0]);
        $this->assertSame('pending', Question::query()->latest('id')->value('status'));

        // Votes: once each, never your own.
        $this->assertSame(403, $this->res('POST', '/4/vote', 3, ['vote' => true])[0]);
        $this->res('POST', '/4/vote', 2, ['vote' => true]);
        [$status, $voted] = $this->res('POST', '/4/vote', 2, ['vote' => true]);
        $this->assertSame(200, $status);
        $this->assertSame([1, true], [$voted['data']['attributes']['votesCount'], $voted['data']['attributes']['voted']]);
        $this->res('POST', '/4/vote', 2, ['vote' => false]);
        $this->assertSame(0, Question::query()->find(4)->votes_count);
        $this->assertSame(404, $this->res('POST', '/1/vote', 2, ['vote' => true])[0], 'the pool is not even visible to members');

        // The queue is members' suggestions; the pool is only for moderators.
        $this->assertCount(2, $this->ids($this->res('GET', '', 2, [], ['filter' => ['list' => 'queue']])[1]));
        $this->assertSame([], $this->ids($this->res('GET', '', 2, [], ['filter' => ['list' => 'pool']])[1]));
        $this->assertSame([], $this->ids($this->res('GET', '', null, [], ['filter' => ['list' => 'queue']])[1]), 'guests don\'t see the queue');
        $this->assertSame(404, $this->res('GET', '/1', 2)[0], 'a pool question by id, as a member');

        // Members can't approve, not even their own.
        $this->assertSame(403, $this->res('PATCH', '/4', 2, $this->doc(['status' => 'approved'], 4))[0]);
        $this->res('PATCH', '/4', 3, $this->doc(['status' => 'approved'], 4));
        $this->assertSame('pending', Question::query()->find(4)->status);

        $this->assertSame(200, $this->res('PATCH', '/4', 5, $this->doc(['status' => 'approved'], 4))[0]);
        $this->assertSame([2, 1, 3, 4], $this->ids($this->res('GET', '', 5, [], ['filter' => ['list' => 'pool']])[1]), 'in the order they will be asked: most votes, then oldest approved (4 lost its vote)');

        // Moderators add a batch straight to the pool, skipping duplicates.
        [$status, $added] = $this->res('POST', '/add', 5, ['bodies' => ['Sweet or savory breakfast?', 'Mountains or beach?', 'x']]);
        $this->assertSame(['added' => 1, 'skipped' => 2], $added);
        $this->assertSame(403, $this->res('POST', '/starter', 2)[0]);
        $this->assertSame(403, $this->res('POST', '/add', 2, ['bodies' => ['A member adding straight to the pool?']])[0]);
    }

    #[Test]
    public function ask_now_swaps_todays_question_only_before_anyone_answers(): void
    {
        $this->api('GET', '', 2);
        $this->assertSame(200, $this->res('POST', '/3/ask', 5)[0]);
        $this->assertSame(3, $this->api('GET', '', 2)[1]['question']['id']);
        $this->assertNull(Question::query()->find(2)->asked_on, 'back in the pool');

        $this->api('POST', '/questions/3/answers', 2, ['body' => 'Beach']);
        $this->assertSame(409, $this->res('POST', '/1/ask', 5)[0]);
        $this->assertSame(404, $this->res('POST', '/1/ask', 2)[0], 'members can\'t see the pool, let alone ask from it');
    }

    #[Test]
    public function delivery_alerts_subscribers_once_and_posts_one_discussion(): void
    {
        $this->setting(Settings::DISCUSSION, '1');
        $this->assertSame(200, $this->res('POST', '/reminder', 3, ['on' => true])[0]);
        $this->res('POST', '/reminder', 4, ['on' => true]);
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
        $this->res('POST', '/4/vote', 2, ['vote' => true]);

        $response = $this->send($this->request('DELETE', '/api/users/2', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $this->assertSame(0, $theirs->refresh()->reactions_count);
        $this->assertSame(0, Question::query()->find(4)->votes_count);
        $this->assertSame(0, Answer::query()->where('user_id', 2)->count());
    }

    #[Test]
    public function without_the_view_permission_nothing_shows_not_even_the_page_title(): void
    {
        $this->database()->table('group_permission')->where('permission', Settings::VIEW)->delete();
        $this->assertSame(403, $this->api('GET', '', null)[0]);
        $this->assertSame([], $this->ids($this->res('GET', '', 2)[1]), 'an empty archive');

        $this->app()->getContainer()->make(Rotator::class)->rotate('2026-11-10');
        $page = (string) $this->send($this->request('GET', '/question/2'))->getBody();
        $this->assertStringNotContainsString('Best road trip', $page);
    }

    #[Test]
    public function ask_now_takes_back_what_delivery_already_did(): void
    {
        $this->setting(Settings::DISCUSSION, '1');
        $this->res('POST', '/reminder', 3, ['on' => true]);
        $this->api('GET', '', 2);
        $delivery = $this->app()->getContainer()->make(Delivery::class);
        $delivery->run();
        $posted = Question::query()->find(2)->discussion_id;
        $this->assertNotNull($posted);
        $this->assertSame(1, Notification::query()->where('type', 'qotdAsked')->count());

        $this->assertSame(200, $this->res('POST', '/3/ask', 5)[0]);

        $this->assertNotNull(Discussion::query()->find($posted)->hidden_at, 'the old daily discussion is hidden');
        $about = fn (int $id) => Notification::query()->whereIn('type', ['qotdNew', 'qotdAsked'])->get()
            ->filter(fn (Notification $n) => ((is_array($n->data) ? $n->data : json_decode((string) $n->data, true))['question'] ?? null) === $id)->count();
        $this->assertSame(0, $about(2), 'its alerts are taken back');
        $this->assertNull(Question::query()->find(2)->discussion_id);

        // The swap queued the new question's delivery (sync here); another run changes nothing.
        $delivery->run();
        $this->assertSame(1, Discussion::query()->whereNull('hidden_at')->count(), 'one visible daily discussion: the new question\'s');
        $this->assertSame(1, $about(3));
        $this->assertSame(1, Notification::query()->where('type', 'qotdNew')->where('user_id', 3)->count());
    }

    #[Test]
    public function a_page_request_delivers_one_small_chunk_and_the_scheduler_the_rest(): void
    {
        $this->app();
        $db = $this->database();
        for ($id = 10; $id < 80; $id++) {
            $db->table('users')->insert(['id' => $id, 'username' => "sub$id", 'email' => "sub$id@machine.local", 'is_email_confirmed' => 1, 'password' => 'x', 'joined_at' => Carbon::now()]);
            $db->table('group_user')->insert(['user_id' => $id, 'group_id' => Group::MEMBER_ID]);
            $db->table('qotd_subscriptions')->insert(['user_id' => $id, 'created_at' => Carbon::now()]);
        }

        // The visitor whose request puts the question up runs the queued job (the test queue is sync).
        $this->api('GET', '', null);
        $this->assertSame(Delivery::REQUEST_CHUNK, Notification::query()->where('type', 'qotdNew')->count());
        $this->assertNull(Question::query()->find(2)->notified_at);

        $this->app()->getContainer()->make(Delivery::class)->run();
        $this->assertSame(70, Notification::query()->where('type', 'qotdNew')->count());
        $this->assertNotNull(Question::query()->find(2)->notified_at);
        $this->assertSame(79, Question::query()->find(2)->email_cursor);
    }

    #[Test]
    public function ask_now_racing_midnight_never_leaves_a_question_open_beside_the_new_days(): void
    {
        $this->api('GET', '', 2);
        $rotator = $this->app()->getContainer()->make(Rotator::class);

        // After midnight with nothing rotated yet: the swap reads the new day itself and simply asks for it.
        $this->moveTo('2026-11-11 06:30:00');
        $this->assertTrue($rotator->swap(Question::query()->find(3)));
        $this->assertSame('2026-11-11', Question::query()->find(3)->asked_on);
        $this->assertSame(1, Question::query()->whereNotNull('asked_on')->whereNull('closed_at')->count());
        $this->assertSame('2026-11-10', Question::query()->find(2)->asked_on, 'yesterday\'s stays in the archive');

        // Today's question already closed under us (a rotation won the race): no swap.
        Question::query()->whereKey(3)->update(['closed_at' => Carbon::now()]);
        $this->assertFalse($rotator->swap(Question::query()->find(1)));
        $this->assertNull(Question::query()->find(1)->asked_on);
    }

    #[Test]
    public function deleting_an_answer_to_the_open_question_gives_its_streak_credit_back(): void
    {
        $this->api('GET', '', 2);
        $this->api('POST', '/questions/2/answers', 2, ['body' => 'Day one']);
        $this->moveTo('2026-11-11 07:00:00');
        $id = $this->api('GET', '', 2)[1]['question']['id'];
        $this->api('POST', "/questions/$id/answers", 2, ['body' => 'Day two']);
        $this->assertSame(2, Streak::query()->find(2)->current);

        $answer = Answer::query()->where('question_id', $id)->where('user_id', 2)->first();
        $this->assertSame(204, $this->api('DELETE', '/answers/'.$answer->id, 2)[0]);
        $streak = Streak::query()->find(2);
        $this->assertSame([1, 1, '2026-11-10'], [$streak->current, $streak->total, $streak->last_day]);

        // Answer again: credited once, not twice.
        $this->api('POST', "/questions/$id/answers", 2, ['body' => 'Day two, again']);
        $this->assertSame([2, 2], [Streak::query()->find(2)->current, Streak::query()->find(2)->total]);

        // A moderator deletes the day's question: its answers' credit goes, and its replacement counts.
        $this->assertSame(204, $this->res('DELETE', "/$id", 5)[0]);
        $this->assertSame(1, Streak::query()->find(2)->current);
        $replacement = $this->api('GET', '', 2)[1]['question']['id'];
        $this->assertNotSame($id, $replacement);
        $this->assertSame(201, $this->api('POST', "/questions/$replacement/answers", 2, ['body' => 'The new one'])[0]);
        $this->assertSame(2, Streak::query()->find(2)->current);
    }

    #[Test]
    public function a_suggestions_words_cant_change_under_its_votes(): void
    {
        $this->res('POST', '/4/vote', 2, ['vote' => true]);
        $this->assertSame(409, $this->res('PATCH', '/4', 3, $this->doc(['body' => 'Which snack would you take to the moon instead?'], 4))[0]);
        $this->assertSame('Which snack would you take to a desert island?', Question::query()->find(4)->body);

        // A moderator may reword it; the count starts again.
        $this->assertSame(200, $this->res('PATCH', '/4', 5, $this->doc(['body' => 'Which snack would you take on a desert island?'], 4))[0]);
        $this->assertSame(0, Question::query()->find(4)->votes_count);
        $this->assertSame(0, $this->database()->table('qotd_votes')->where('question_id', 4)->count());
    }

    #[Test]
    public function the_resources_serve_the_archive_the_queue_and_the_streak_boards(): void
    {
        $this->api('GET', '', 2);
        $this->api('POST', '/questions/2/answers', 2, ['body' => 'The coast road at sunrise']);
        $this->api('POST', '/questions/2/answers', 3, ['body' => 'Route 66, both ways']);
        $theirs = Answer::query()->where('user_id', 3)->first();
        $this->api('POST', "/answers/{$theirs->id}/react", 2, ['type' => 'fire']);
        $this->moveTo('2026-11-11 07:00:00');
        $this->api('GET', '', 2);

        [$status, $archive] = $this->res('GET', '', null, [], ['filter' => ['list' => 'archive'], 'include' => 'topAnswer,topAnswer.user,user']);
        $this->assertSame(200, $status);
        $this->assertSame([2], $this->ids($archive));
        $row = $archive['data'][0];
        $this->assertSame([2, '2026-11-10', false], [$row['attributes']['answersCount'], $row['attributes']['askedOn'], $row['attributes']['open']]);
        $this->assertSame((string) $theirs->id, $row['relationships']['topAnswer']['data']['id']);
        $included = collect($archive['included'] ?? [])->keyBy(fn ($i) => $i['type'].':'.$i['id']);
        $this->assertSame('Route 66, both ways', $included['qotd-answers:'.$theirs->id]['attributes']['body']);
        $this->assertTrue($included->has('users:3') && $included->has('users:4'), 'the winner, and Sam who suggested it');

        // Today's question is not in the archive, and by id it carries no answers.
        [, $today] = $this->res('GET', '/1', null, [], ['include' => 'topAnswer']);
        $this->assertTrue($today['data']['attributes']['open']);
        $this->assertArrayNotHasKey('topAnswer', $today['data']['relationships'] ?? []);

        // The queue shows whether the viewer voted, per question, in one query.
        $this->res('POST', '/4/vote', 2, ['vote' => true]);
        [, $queue] = $this->res('GET', '', 2, [], ['filter' => ['list' => 'queue']]);
        $this->assertSame([[4, true, 1]], array_map(fn ($r) => [(int) $r['id'], $r['attributes']['voted'], $r['attributes']['votesCount']], $queue['data']));

        // Streak boards: who is on a roll (Lee answered the question before this one) and the best ever.
        [$status, $going] = $this->api('GET', '/api/qotd-streaks', null, [], ['filter' => ['board' => 'going'], 'include' => 'user']);
        $this->assertSame(200, $status);
        $this->assertEqualsCanonicalizing(['2', '3'], array_column($going['data'], 'id'));
        $this->assertSame(1, $going['data'][0]['attributes']['current']);
        [, $mine] = $this->api('GET', '/api/qotd-streaks/2', 2);
        $this->assertSame([1, 1, 1], [$mine['data']['attributes']['current'], $mine['data']['attributes']['best'], $mine['data']['attributes']['total']]);

        // A board is ten long however many streaks there are, and the archive pages by twenty.
        for ($id = 30; $id < 45; $id++) {
            $this->database()->table('users')->insert(['id' => $id, 'username' => "streak$id", 'email' => "streak$id@machine.local", 'is_email_confirmed' => 1, 'password' => 'x', 'joined_at' => Carbon::now()]);
            $this->database()->table('qotd_streaks')->insert(['user_id' => $id, 'current' => 0, 'best' => $id, 'total' => $id, 'last_day' => '2026-01-01']);
        }
        [, $best] = $this->api('GET', '/api/qotd-streaks', null, [], ['filter' => ['board' => 'best']]);
        $this->assertCount(10, $best['data']);
        $this->assertSame('44', $best['data'][0]['id']);
        for ($i = 0; $i < 22; $i++) {
            Question::query()->forceCreate(['body' => "Old question number $i?", 'status' => 'approved', 'asked_on' => Carbon::parse('2026-01-01')->addDays($i)->toDateString(), 'closed_at' => Carbon::now()]);
        }
        [, $page] = $this->res('GET', '', null, [], ['filter' => ['list' => 'archive']]);
        $this->assertCount(20, $page['data']);
        $this->assertNotEmpty($page['links']['next'] ?? null);
    }

    #[Test]
    public function no_resource_or_include_ever_reaches_an_answer_to_the_question_thats_up(): void
    {
        // Yesterday: question 2, answered and closed (the public archive).
        $this->api('GET', '', 2);
        $this->api('POST', '/questions/2/answers', 3, ['body' => 'PUBLIC-ARCHIVE answer']);
        $this->moveTo('2026-11-11 07:00:00');
        $open = $this->api('GET', '', 2)[1]['question']['id'];
        // Today: Lee answers; nobody else has.
        $this->assertSame(201, $this->api('POST', "/questions/$open/answers", 3, ['body' => 'TODAYS-SECRET answer'])[0]);
        $secret = Answer::query()->where('question_id', $open)->first();

        // A bad row must not open the gate either: point the closed question's winner at today's answer.
        Question::query()->whereKey(2)->update(['top_answer_id' => $secret->id]);

        $urls = [
            ['/api/qotd-questions', ['filter' => ['list' => 'archive'], 'include' => 'topAnswer,topAnswer.user,user']],
            ['/api/qotd-questions', ['filter' => ['list' => 'queue'], 'include' => 'topAnswer,user']],
            ['/api/qotd-questions', ['filter' => ['list' => 'pool'], 'include' => 'topAnswer,user']],
            ['/api/qotd-questions/2', ['include' => 'topAnswer,topAnswer.user,user']],
            ["/api/qotd-questions/$open", ['include' => 'topAnswer,topAnswer.user,user']],
            ["/api/qotd-answers/{$secret->id}", []],
            ['/api/qotd-answers', []],
            ['/api/qotd-streaks', ['include' => 'user']],
            ['/api/qotd-streaks', ['filter' => ['board' => 'best'], 'include' => 'user']],
            ['/api/qotd-streaks/3', ['include' => 'user']],
            ['/api/users/3', []],
            ['/api/discussions', []],
            ['/api', []],
            ['', []],
            ["/questions/$open", []],
            ["/questions/$open/answers", []],
            ['/questions/2', []],
        ];

        foreach ([null => 'guest', 2 => 'a member who hasn\'t answered', 5 => 'a moderator who hasn\'t answered'] as $viewer => $who) {
            foreach ($urls as [$url, $query]) {
                [, , $raw] = $this->api('GET', $url, $viewer ?: null, [], $query);
                $this->assertStringNotContainsString('TODAYS-SECRET', $raw, "$url ".json_encode($query)." as $who");
            }
            // The archive still works: yesterday's question with its answers, for everyone who may view it.
            $this->assertStringContainsString('PUBLIC-ARCHIVE', $this->api('GET', '/questions/2', $viewer ?: null)[2], $who);
        }

        // Once you've answered, the plain endpoint shows today's answers (and still no resource does).
        $this->api('POST', "/questions/$open/answers", 2, ['body' => 'Mine']);
        $this->assertStringContainsString('TODAYS-SECRET', $this->api('GET', '', 2)[2]);
        $this->assertStringNotContainsString('TODAYS-SECRET', $this->res('GET', '/2', 2, [], ['include' => 'topAnswer'])[2]);
    }

    #[Test]
    public function an_answer_waits_for_the_members_streak_lock_and_writes_nothing_if_it_cant_get_it(): void
    {
        // Real time throughout: a lock's wait is measured with now(), which the other tests freeze.
        Carbon::setTestNow();
        $id = $this->api('GET', '', 2)[1]['question']['id'];
        $store = $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->getStore();
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\LockProvider::class, $store, 'the test forum\'s cache store takes locks');

        // Another request holds Dana's streak lock (as a racing answer or deletion would).
        $held = $store->lock('ernestdefoe-qotd.lock.'.\ErnestDefoe\QuestionOfTheDay\Streaks::lock(3), 30);
        $this->assertTrue($held->get());
        [$status, $body] = $this->api('POST', "/questions/$id/answers", 3, ['body' => 'Blocked']);
        $this->assertSame(429, $status);
        $this->assertSame('slow_down', $body['errors'][0]['code']);
        $this->assertSame(0, Answer::query()->where('user_id', 3)->count(), 'neither the answer…');
        $this->assertNull(Streak::query()->find(3), '…nor its credit');

        // Released: the answer and its credit go in together.
        $held->release();
        $this->assertSame(201, $this->api('POST', "/questions/$id/answers", 3, ['body' => 'Through'])[0]);
        $this->assertSame(1, Streak::query()->find(3)->current);
    }
}
