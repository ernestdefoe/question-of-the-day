<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/** Things one member may do so many times a minute (or a day). */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(User $actor, string $kind, int $perMinute): bool
    {
        return $this->hit($kind.':'.$actor->id.':'.intdiv(time(), 60), 120) > $perMinute;
    }

    /** Things a member may do so many times a day. */
    public function dailyExceeded(User $actor, string $kind, int $perDay): bool
    {
        return $this->hit($kind.':d:'.$actor->id.':'.intdiv(time(), 86400), 90000) > $perDay;
    }

    private function hit(string $key, int $ttl): int
    {
        $key = 'ernestdefoe-qotd.rate.'.$key;
        $this->cache->add($key, 0, $ttl);

        return (int) $this->cache->increment($key);
    }
}
