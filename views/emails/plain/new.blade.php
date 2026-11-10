<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-question-of-the-day.email.new.intro') !!}

{!! $blueprint->question->body !!}

{!! $translator->trans('ernestdefoe-question-of-the-day.email.new.hidden') !!}

{!! $url->to('forum')->route('question-of-the-day') !!}
</x-slot:body>
</x-mail::plain.notification>
