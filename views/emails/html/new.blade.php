<x-mail::html.notification>
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-question-of-the-day.email.new.intro') }}</p>
        <p style="font-size: 18px; font-weight: bold;">{{ $blueprint->question->body }}</p>
        <p>{{ $translator->trans('ernestdefoe-question-of-the-day.email.new.hidden') }}</p>
        <p><a href="{{ $url->to('forum')->route('question-of-the-day') }}">{{ $translator->trans('ernestdefoe-question-of-the-day.email.new.cta') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
