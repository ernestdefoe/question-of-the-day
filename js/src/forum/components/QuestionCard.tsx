import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { dayLabel, t, until, type QuestionRow, type TodayData } from '../api';
import { today } from '../store';
import { face, faces, who, logIn } from './parts';

declare const m: Mithril.Static;

interface Attrs {
  data: TodayData;
  question: QuestionRow;
  variant: 'hero' | 'page' | 'past';
  onanswered?: () => void;
  ondismiss?: () => void;
}

const DRAFT = 'qotd-draft';

/**
 * The question, big: the date, who suggested it, and then one of
 *  - a box to answer it, with "N members answered" and their faces (answers locked),
 *  - your answer (once posted), or
 *  - for a past question, how many answered.
 */
export default class QuestionCard extends Component<Attrs> {
  draft = '';
  posting = false;
  timer = 0;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    try {
      const saved = JSON.parse(sessionStorage.getItem(DRAFT) || 'null');
      if (saved && saved.id === this.attrs.question.id) this.draft = saved.text || '';
    } catch (e) {}
  }

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    // The "next question in…" countdown, once a minute.
    this.timer = window.setInterval(() => m.redraw(), 60e3);
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  view() {
    const { question, variant, data } = this.attrs;
    const past = variant === 'past' || !question.open;

    return (
      <article className={`QotdCard QotdCard--${variant}` + (past ? ' QotdCard--closed' : '')} aria-labelledby={`qotd-q-${question.id}`}>
        <div className="QotdCard-glow" aria-hidden="true" />
        <header className="QotdCard-top">
          <span className="QotdCard-badge">
            <Icon name="fas fa-comment-dots" />
            {t('card.badge')}
          </span>
          <span className="QotdCard-date">{dayLabel(question.askedOn, past)}</span>
          <span className="QotdCard-tools">{this.tools()}</span>
        </header>

        <h2 className="QotdCard-question" id={`qotd-q-${question.id}`}>
          {variant === 'hero' ? (
            <Link href={app.route('question-of-the-day')} className="QotdCard-questionLink">
              {question.body}
            </Link>
          ) : (
            question.body
          )}
        </h2>

        {question.suggestedBy ? (
          <p className="QotdCard-by">
            {face(question.suggestedBy, 20)}
            {t('card.suggested_by', { name: who(question.suggestedBy) })}
          </p>
        ) : null}

        {past ? this.pastFoot() : question.mine ? this.answered() : this.form()}
      </article>
    );
  }

  tools(): Mithril.Children {
    const { data, variant, question } = this.attrs;
    const items: Mithril.Children[] = [];

    if (data.streak.current > 0 && question.open) {
      items.push(
        <span className="QotdChip QotdChip--streak" title={extractText(t('streak.title', { count: data.streak.current }))}>
          🔥 {data.streak.current}
        </span>
      );
    }
    if (question.open && variant !== 'past') {
      items.push(
        <span className="QotdChip QotdChip--next" title={extractText(t('card.next_title'))}>
          <Icon name="far fa-clock" /> {until(data.nextAt)}
        </span>
      );
    }
    if (variant === 'hero' && this.attrs.ondismiss) {
      items.push(
        <button
          type="button"
          className="QotdCard-dismiss"
          title={extractText(t('card.hide'))}
          aria-label={extractText(t('card.hide'))}
          onclick={() => this.attrs.ondismiss!()}
        >
          <Icon name="fas fa-times" />
        </button>
      );
    }

    return items;
  }

  form(): Mithril.Children {
    const { data, question } = this.attrs;
    const count = question.answersCount || 0;
    const max = data.maxLength;
    const left = max - this.draft.length;

    if (!app.session.user) {
      return (
        <div className="QotdCard-guest">
          {this.lock(count)}
          <Button className="Button Button--primary QotdCard-cta" onclick={() => logIn()}>
            {t('card.log_in')}
          </Button>
        </div>
      );
    }
    if (!data.can.answer) {
      return (
        <div className="QotdCard-guest">
          {this.lock(count)}
          <p className="QotdCard-note">{t('card.no_permission')}</p>
        </div>
      );
    }

    return (
      <form className="QotdCard-form" onsubmit={(e: SubmitEvent) => this.submit(e)}>
        <label className="sr-only" for={`qotd-a-${question.id}`}>
          {t('card.your_answer')}
        </label>
        <textarea
          id={`qotd-a-${question.id}`}
          className="FormControl QotdCard-input"
          rows={2}
          maxlength={max}
          placeholder={extractText(t('card.placeholder'))}
          value={this.draft}
          oninput={(e: InputEvent) => {
            this.draft = (e.target as HTMLTextAreaElement).value;
            this.keepDraft();
          }}
          onkeydown={(e: KeyboardEvent) => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) this.submit(e);
          }}
        />
        <div className="QotdCard-formRow">
          {this.lock(count)}
          <span className={'QotdCard-left' + (left < 20 ? ' QotdCard-left--low' : '')} aria-live="polite">
            {left < 60 ? left : ''}
          </span>
          <Button type="submit" className="Button Button--primary QotdCard-cta" loading={this.posting} disabled={!this.draft.trim()}>
            {t('card.post')}
          </Button>
        </div>
      </form>
    );
  }

  /** "🔒 12 answers, hidden until you post yours", with the answerers' faces. */
  lock(count: number): Mithril.Children {
    const { question } = this.attrs;
    return (
      <span className="QotdCard-lock">
        {question.answerers?.length ? faces(question.answerers) : null}
        <span className="QotdCard-lockText">
          <Icon name="fas fa-lock" /> {count ? t('card.locked', { count }) : t('card.be_first')}
        </span>
      </span>
    );
  }

  answered(): Mithril.Children {
    const { question, variant } = this.attrs;
    const others = Math.max(0, (question.answersCount || 1) - 1);

    return (
      <div className="QotdCard-answered">
        <div className="QotdCard-mine">
          <span className="QotdCard-check" aria-hidden="true">
            <Icon name="fas fa-check" />
          </span>
          <div>
            <span className="QotdCard-mineLabel">{t('card.your_answer')}</span>
            <p className="QotdCard-mineText">{question.mine!.body}</p>
          </div>
        </div>
        {variant === 'hero' ? (
          <div className="QotdCard-formRow">
            <span className="QotdCard-lock">
              {question.answerers?.length ? faces(question.answerers) : null}
              <span className="QotdCard-lockText">{others ? t('card.others', { count: others }) : t('card.first')}</span>
            </span>
            <Link className="Button Button--primary QotdCard-cta" href={app.route('question-of-the-day')}>
              {t('card.see_answers')} <Icon name="fas fa-arrow-right" />
            </Link>
          </div>
        ) : null}
      </div>
    );
  }

  pastFoot(): Mithril.Children {
    const { question } = this.attrs;
    return (
      <p className="QotdCard-note">
        <Icon name="fas fa-comments" /> {t('card.closed', { count: question.answersCount || 0 })}
        {question.discussion ? (
          <>
            {' · '}
            <Link href={app.route('discussion', { id: `${question.discussion.id}-${question.discussion.slug}` })}>{t('card.discussion')}</Link>
          </>
        ) : null}
      </p>
    );
  }

  keepDraft() {
    try {
      sessionStorage.setItem(DRAFT, JSON.stringify({ id: this.attrs.question.id, text: this.draft }));
    } catch (e) {}
  }

  submit(e: Event) {
    e.preventDefault();
    if (this.posting || !this.draft.trim()) return;
    this.posting = true;
    today
      .answer(this.draft)
      .then(() => {
        this.draft = '';
        try {
          sessionStorage.removeItem(DRAFT);
        } catch (err) {}
        this.attrs.onanswered?.();
      })
      .catch(() => {
        // Closed while they typed (midnight): bring in the new question, keep their words.
        today.load(true);
      })
      .finally(() => {
        this.posting = false;
        m.redraw();
      });
  }
}
