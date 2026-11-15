<?php

namespace ErnestDefoe\QuestionOfTheDay;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A database transaction under a named cache lock. lockForUpdate() does nothing on SQLite, so
 * the writes that read-then-write (the day's rotation, a member's streak, one reaction) also
 * take a cache lock where the forum's cache store has them (file, Redis, database: Flarum's own
 * stores all do). Waits up to five seconds for the lock, then gives up with a Refusal.
 */
class Locks
{
    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function run(string $key, Closure $work): mixed
    {
        $transaction = fn () => (new Question())->getConnection()->transaction($work);
        $store = $this->cache instanceof Repository ? $this->cache->getStore() : null;
        if (! $store instanceof LockProvider) {
            return $transaction();
        }

        try {
            return $store->lock('ernestdefoe-qotd.lock.'.$key, 15)->block(5, $transaction);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new Refusal('slow_down', 429);
        }
    }
}
