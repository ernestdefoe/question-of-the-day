<?php

namespace ErnestDefoe\QuestionOfTheDay\Notification;

use ErnestDefoe\QuestionOfTheDay\Question;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "Your question is today's Question of the Day!", to the member who suggested it. Subject: that member. */
class AskedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Question $question)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->question->user;
    }

    public function getData(): mixed
    {
        return ['question' => $this->question->id, 'body' => $this->question->body];
    }

    public static function getType(): string
    {
        return 'qotdAsked';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
