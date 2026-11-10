import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { t, type AnswerRow, type QuestionRow } from '../api';
import { today } from '../store';
import QuestionCard from './QuestionCard';
import { AnswerItem, face, who } from './parts';

/**
 * The front-page card: today's question to answer, and once you have, the
 * three best answers so far. Under it, yesterday's top answer.
 */
export default class Hero extends Component {
  hidden = false;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    today.load();
  }

  view() {
    const data = today.data;
    if (this.hidden || today.failed || (!data && !today.loading)) return <div className="QotdHero QotdHero--empty" />;
    if (!data) return <div className="QotdHero QotdHero--placeholder" aria-hidden="true" />;

    const question = data.question;
    if (!question) return <div className="QotdHero QotdHero--empty" />;

    return (
      <div className="QotdHero">
        <QuestionCard data={data} question={question} variant="hero" ondismiss={() => this.dismiss()} />
        {question.mine && question.answers?.length ? this.peek(question) : null}
        {data.previous?.top ? topAnswer(data.previous, data.previous.top) : null}
      </div>
    );
  }

  peek(question: QuestionRow): Mithril.Children {
    const others = question.answers!.filter((a) => !a.mine).slice(0, 3);
    if (!others.length) return null;
    return (
      <ol className="QotdHero-peek QotdAnswers--reveal">
        {others.map((answer, i) => (
          <AnswerItem key={answer.id} answer={answer} index={i} compact />
        ))}
      </ol>
    );
  }

  /** Hidden until the forum's next question (stored as that moment). */
  dismiss() {
    this.hidden = true;
    try {
      localStorage.setItem('qotd-hidden-until', String(new Date(today.data!.nextAt).getTime()));
    } catch (e) {}
  }
}

/** "🏆 Yesterday's top answer", with yesterday's question for context. */
export function topAnswer(question: QuestionRow, answer: AnswerRow, label = 'top.yesterday'): Mithril.Children {
  return (
    <aside className="QotdTop">
      <span className="QotdTop-trophy" aria-hidden="true">
        🏆
      </span>
      <div className="QotdTop-main">
        <div className="QotdTop-label">
          {t(label)}
          <Link className="QotdTop-question" href={app.route('question-of-the-day.question', { id: question.id })}>
            {question.body}
          </Link>
        </div>
        <blockquote className="QotdTop-answer">{answer.body}</blockquote>
        <div className="QotdTop-by">
          {face(answer.author, 22)}
          {who(answer.author)}
          <span className="QotdTop-count">
            <Icon name="fas fa-heart" /> {t('top.reactions', { count: answer.reactionsCount })}
          </span>
        </div>
      </div>
    </aside>
  );
}
