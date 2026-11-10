import app from 'flarum/forum/app';
import { call, type AnswerRow, type ReactionType, type TodayData } from './api';

declare const m: any;

/**
 * Today's question, shared by the front-page card and the /question page so
 * both show the same thing and an answer posted on one shows on the other.
 * Fetched once and kept for five minutes (or until the day turns over).
 */
class TodayStore {
  data: TodayData | null = null;
  loading = false;
  failed = false;
  sort: 'top' | 'new' = 'top';
  moderating = false;
  private fetched = 0;
  private pending: Promise<void> | null = null;

  stale(): boolean {
    return !this.data || Date.now() - this.fetched > 5 * 60e3 || (!!this.data.nextAt && Date.now() >= new Date(this.data.nextAt).getTime());
  }

  load(force = false): Promise<void> {
    if (!force && !this.stale()) return Promise.resolve();
    if (this.pending) return this.pending;
    this.loading = !this.data;
    const query = new URLSearchParams({ sort: this.sort });
    if (this.moderating) query.set('moderate', '1');

    this.pending = call<TodayData>('GET', '?' + query.toString(), undefined, true)
      .then((data) => {
        this.data = data;
        this.fetched = Date.now();
        this.failed = false;
      })
      .catch(() => {
        this.failed = true;
      })
      .finally(() => {
        this.loading = false;
        this.pending = null;
        m.redraw();
      });

    return this.pending;
  }

  answer(body: string): Promise<void> {
    const question = this.data?.question;
    if (!question) return Promise.resolve();
    return call<TodayData>('POST', `/questions/${question.id}/answers?sort=${this.sort}`, { body }).then((data) => {
      this.data = data;
      this.fetched = Date.now();
      m.redraw();
    });
  }

  edit(answerId: number, body: string): Promise<void> {
    return call<{ body: string; edited: boolean }>('PATCH', `/answers/${answerId}`, { body }).then((result) => {
      const question = this.data?.question;
      if (question?.mine) question.mine.body = result.body;
      const row = question?.answers?.find((a) => a.id === answerId);
      if (row) {
        row.body = result.body;
        row.edited = result.edited;
      }
      m.redraw();
    });
  }

  remove(answer: AnswerRow): Promise<void> {
    return call<void>('DELETE', `/answers/${answer.id}`).then(() => {
      if (answer.mine) return this.load(true);
      const question = this.data?.question;
      if (question?.answers) {
        question.answers = question.answers.filter((a) => a.id !== answer.id);
        question.answersCount = Math.max(0, (question.answersCount || 1) - 1);
      }
      m.redraw();
    });
  }

  subscribe(on: boolean): Promise<void> {
    return call<{ subscribed: boolean }>('POST', '/subscription', { on }).then((r) => {
      if (this.data) this.data.subscribed = r.subscribed;
      m.redraw();
    });
  }

  setSort(sort: 'top' | 'new') {
    this.sort = sort;
    return this.load(true);
  }

  setModerating(on: boolean) {
    this.moderating = on;
    return this.load(true);
  }
}

export const today = new TodayStore();

/**
 * Reacts to an answer and puts the server's counts back on the row. The tap
 * shows at once; a failure puts the old state back.
 */
export function react(answer: AnswerRow, type: ReactionType): Promise<void> {
  const before = { reactions: { ...answer.reactions }, reactionsCount: answer.reactionsCount, myReaction: answer.myReaction };
  const next = answer.myReaction === type ? null : type;

  if (answer.myReaction) answer.reactions[answer.myReaction] = Math.max(0, (answer.reactions[answer.myReaction] || 1) - 1);
  if (next) answer.reactions[next] = (answer.reactions[next] || 0) + 1;
  answer.reactionsCount += (next ? 1 : 0) - (answer.myReaction ? 1 : 0);
  answer.myReaction = next;
  m.redraw();

  return call<AnswerRow>('POST', `/answers/${answer.id}/react`, { type: next })
    .then((row) => Object.assign(answer, row))
    .catch(() => Object.assign(answer, before))
    .finally(() => m.redraw()) as Promise<any>;
}

export const signedIn = () => !!app.session.user;
