import Extend from 'flarum/common/extenders';
import { loadPage } from './load';
import { AskedNotification, NewQuestionNotification } from './notifications';

export default [
  // The pages are a lazy chunk: each route loads it on first visit.
  new Extend.Routes()
    .add('question-of-the-day', '/question', loadPage)
    .add('question-of-the-day.question', '/question/:id', loadPage)
    .add('question-of-the-day.questions', '/questions', loadPage),

  new Extend.Notification().add('qotdNew', NewQuestionNotification).add('qotdAsked', AskedNotification),
];
