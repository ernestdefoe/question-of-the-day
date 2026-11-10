<?php

namespace ErnestDefoe\QuestionOfTheDay\Api;

use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** Reading and cleaning what the browser sends. Text only: nothing here is ever rendered as HTML. */
final class Input
{
    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    public static function id(ServerRequestInterface $request, string $key = 'id'): int
    {
        return (int) ($request->getAttribute('routeParameters')[$key] ?? 0);
    }

    /** An answer: control characters out, at most one blank line in a row, trimmed. */
    public static function answer(mixed $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", is_string($text) ? $text : '');
        $text = (string) preg_replace('/[^\P{C}\n]/u', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** A question: one line, single spaces. */
    public static function question(mixed $text): string
    {
        $text = (string) preg_replace('/[^\P{C}]/u', ' ', is_string($text) ? $text : '');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function questionOk(string $text): bool
    {
        $length = mb_strlen($text);

        return $length >= Settings::QUESTION_MIN && $length <= Settings::QUESTION_MAX;
    }

    /** A question already asked, in the pool or waiting, with the same words (letter case aside). */
    public static function duplicate(string $text, ?int $except = null): bool
    {
        return Question::query()->where('status', '!=', Question::REJECTED)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereRaw('LOWER(body) = ?', [mb_strtolower($text)])->exists();
    }

    /** A Y-m-d date, or null. */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    public static function sort(ServerRequestInterface $request): string
    {
        return ($request->getQueryParams()['sort'] ?? '') === 'new' ? 'new' : 'top';
    }

    public static function moderating(ServerRequestInterface $request, User $actor): bool
    {
        return ! empty($request->getQueryParams()['moderate']) && $actor->can(Settings::MODERATE);
    }

    public static function offset(ServerRequestInterface $request): int
    {
        return max(0, min(100000, (int) ($request->getQueryParams()['offset'] ?? 0)));
    }
}
