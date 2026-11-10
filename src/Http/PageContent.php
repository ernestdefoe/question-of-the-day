<?php

namespace ErnestDefoe\QuestionOfTheDay\Http;

use ErnestDefoe\QuestionOfTheDay\Question;
use ErnestDefoe\QuestionOfTheDay\Settings;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The question pages as the server sends them: a title, a canonical URL and,
 * for a past question, its words in the description. Never an answer: those
 * need the API's checks.
 */
class PageContent
{
    public function __construct(private readonly UrlGenerator $url, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $title = (string) $this->translator->trans('ernestdefoe-question-of-the-day.forum.page.title');
        $document->title = $title;
        $document->canonicalUrl = $this->url->to('forum')->route('question-of-the-day');

        // Only for visitors who may see questions: the title would show it to anyone otherwise.
        if ($id && RequestUtil::getActor($request)->can(Settings::VIEW)) {
            $question = Question::query()->whereNotNull('asked_on')->find($id);
            if ($question) {
                $document->title = $question->body.' - '.$title;
                $document->meta['description'] = $question->body;
                $document->canonicalUrl = $this->url->to('forum')->route('question-of-the-day.question', ['id' => $id]);
            }
        } elseif (str_ends_with($request->getUri()->getPath(), '/questions')) {
            $document->canonicalUrl = $this->url->to('forum')->route('question-of-the-day.questions');
        }

        return $document;
    }
}
