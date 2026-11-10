<?php

namespace ErnestDefoe\QuestionOfTheDay\Notification;

use ErnestDefoe\QuestionOfTheDay\Question;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "Today's question: …", to members who turned on the daily reminder. The
 * subject is a User (a subject without an API resource 500s every
 * notification list): whoever wrote the question, or the first admin. The
 * question rides in the data, which also makes the blueprint one per question.
 */
class NewQuestionBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Question $question, public User $subject)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->subject;
    }

    public function getData(): mixed
    {
        return ['question' => $this->question->id, 'body' => $this->question->body];
    }

    public static function getType(): string
    {
        return 'qotdNew';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-question-of-the-day::emails.plain.new',
            'html' => 'ernestdefoe-question-of-the-day::emails.html.new',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-question-of-the-day.email.new.subject');
    }
}
