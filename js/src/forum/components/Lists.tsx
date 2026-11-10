import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, errorText, shortDay, t, type AnswerRow, type Person, type QueueRow, type QuestionRow } from '../api';
import { today } from '../store';
import { face, who, logIn } from './parts';

declare const m: Mithril.Static;

type ArchiveRow = QuestionRow & { answersCount: number; top: AnswerRow | null };

/** Past questions, newest first, each with its winning answer. Twenty at a time. */
export class Archive extends Component {
  rows: ArchiveRow[] = [];
  more = false;
  loading = true;
  failed = false;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.load(0);
  }

  load(offset: number) {
    this.loading = true;
    call<{ questions: ArchiveRow[]; hasMore: boolean }>('GET', `/archive?offset=${offset}`, undefined, true)
      .then((r) => {
        this.rows = this.rows.concat(r.questions);
        this.more = r.hasMore;
      })
      .catch(() => {
        this.failed = true;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.failed) return <p className="QotdEmpty">{t('errors.generic')}</p>;
    if (!this.rows.length) return this.loading ? <LoadingIndicator /> : <p className="QotdEmpty">{t('archive.empty')}</p>;

    return (
      <div className="QotdArchive">
        <ol className="QotdArchive-list">
          {this.rows.map((row) => (
            <li className="QotdArchive-item">
              <Link className="QotdArchive-link" href={app.route('question-of-the-day.question', { id: row.id })}>
                <span className="QotdArchive-date">{shortDay(row.askedOn)}</span>
                <span className="QotdArchive-question">{row.body}</span>
                <span className="QotdArchive-count">
                  <Icon name="far fa-comment" /> {t('archive.answers', { count: row.answersCount })}
                </span>
              </Link>
              {row.top ? (
                <div className="QotdArchive-top">
                  <span aria-hidden="true">🏆</span>
                  {face(row.top.author, 20)}
                  <span className="QotdArchive-topText">
                    {who(row.top.author)}
                    {': '}
                    {row.top.body}
                  </span>
                </div>
              ) : null}
            </li>
          ))}
        </ol>
        {this.more ? (
          <div className="QotdAnswers-more">
            <Button className="Button" loading={this.loading} onclick={() => this.load(this.rows.length)}>
              {t('archive.more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }
}

interface QueueData {
  pending: QueueRow[];
  mine: number;
  pool: QueueRow[] | null;
  poolCount: number | null;
  answeredToday: boolean;
}

/**
 * Suggest a question and upvote the ones waiting; moderators approve or
 * reject them, and manage the pool (add, schedule, ask now, delete).
 */
export class Queue extends Component {
  data: QueueData | null = null;
  draft = '';
  sending = false;
  bulk = '';
  adding = false;
  busy: Record<number, boolean> = {};

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    return call<QueueData>('GET', '/queue', undefined, true)
      .then((r) => {
        this.data = r;
      })
      .catch(() => {})
      .finally(() => m.redraw());
  }

  view() {
    const can = today.data?.can;
    if (!app.session.user) {
      return (
        <div className="QotdBlank">
          <span className="QotdBlank-icon" aria-hidden="true">
            💡
          </span>
          <p>{t('queue.guest')}</p>
          <Button className="Button Button--primary" onclick={() => logIn()}>
            {t('card.log_in')}
          </Button>
        </div>
      );
    }
    if (!this.data) return <LoadingIndicator />;
    const moderator = !!can?.moderate;

    return (
      <div className="QotdQueue">
        {moderator ? this.addForm() : this.suggestForm()}
        <section className="QotdSection">
          <h3 className="QotdSection-title">
            {t('queue.pending_title')} <span className="QotdSection-count">{this.data.pending.length}</span>
          </h3>
          <p className="QotdSection-help">{t(moderator ? 'queue.pending_help_mod' : 'queue.pending_help')}</p>
          {this.data.pending.length ? (
            <ol className="QotdQueue-list">{this.data.pending.map((row) => this.pendingRow(row, moderator))}</ol>
          ) : (
            <p className="QotdEmpty">{t('queue.pending_empty')}</p>
          )}
        </section>
        {moderator && this.data.pool ? this.pool() : null}
      </div>
    );
  }

  suggestForm(): Mithril.Children {
    if (!today.data?.can.suggest) return null;
    const left = 200 - this.draft.length;
    return (
      <form
        className="QotdSuggest"
        onsubmit={(e: SubmitEvent) => {
          e.preventDefault();
          this.suggest();
        }}
      >
        <label className="QotdSuggest-label" for="qotd-suggest">
          <span aria-hidden="true">💡</span> {t('queue.suggest_label')}
        </label>
        <div className="QotdSuggest-row">
          <input
            id="qotd-suggest"
            className="FormControl"
            maxlength={200}
            placeholder={extractText(t('queue.suggest_placeholder'))}
            value={this.draft}
            oninput={(e: InputEvent) => {
              this.draft = (e.target as HTMLInputElement).value;
            }}
          />
          <Button type="submit" className="Button Button--primary" loading={this.sending} disabled={this.draft.trim().length < 8}>
            {t('queue.suggest_button')}
          </Button>
        </div>
        <p className="QotdSection-help">
          {t('queue.suggest_help')} {left < 40 ? <span className="QotdCard-left">{left}</span> : null}
        </p>
      </form>
    );
  }

  suggest() {
    this.sending = true;
    call('POST', '/questions', { body: this.draft })
      .then(() => {
        this.draft = '';
        app.alerts.show({ type: 'success' }, t('queue.suggested'));
        return this.load();
      })
      .catch(() => {})
      .finally(() => {
        this.sending = false;
        m.redraw();
      });
  }

  pendingRow(row: QueueRow, moderator: boolean): Mithril.Children {
    return (
      <li className="QotdQueue-item" key={row.id}>
        <button
          type="button"
          className={'QotdVote' + (row.voted ? ' QotdVote--on' : '')}
          disabled={row.mine || this.busy[row.id]}
          aria-pressed={row.voted ? 'true' : 'false'}
          title={extractText(t(row.mine ? 'queue.own' : row.voted ? 'queue.unvote' : 'queue.vote'))}
          onclick={() => {
            this.vote(row);
          }}
        >
          <Icon name="fas fa-caret-up" />
          <span>{row.votes}</span>
        </button>
        <div className="QotdQueue-main">
          <p className="QotdQueue-body">{row.body}</p>
          <p className="QotdQueue-meta">
            {row.author ? (
              <>
                {face(row.author, 18)} {who(row.author)}
                {' · '}
              </>
            ) : null}
            {humanTime(row.createdAt as any)}
            {row.mine ? <span className="QotdAnswer-you">{t('queue.yours')}</span> : null}
          </p>
        </div>
        <div className="QotdQueue-actions">
          {moderator ? (
            <>
              <Button
                className="Button Button--primary Button--icon"
                icon="fas fa-check"
                aria-label={extractText(t('queue.approve'))}
                title={extractText(t('queue.approve'))}
                onclick={() => this.update(row, { status: 'approved' })}
              />
              <Button
                className="Button Button--icon"
                icon="fas fa-times"
                aria-label={extractText(t('queue.reject'))}
                title={extractText(t('queue.reject'))}
                onclick={() => this.update(row, { status: 'rejected' })}
              />
            </>
          ) : row.mine ? (
            <Button
              className="Button Button--icon Button--flat"
              icon="far fa-trash-can"
              aria-label={extractText(t('queue.withdraw'))}
              title={extractText(t('queue.withdraw'))}
              onclick={() => this.remove(row)}
            />
          ) : null}
        </div>
      </li>
    );
  }

  vote(row: QueueRow) {
    this.busy[row.id] = true;
    const want = !row.voted;
    row.voted = want;
    row.votes += want ? 1 : -1;
    call<{ votes: number; voted: boolean }>('POST', `/questions/${row.id}/vote`, { vote: want })
      .then((r) => {
        row.votes = r.votes;
        row.voted = r.voted;
      })
      .catch(() => {
        row.voted = !want;
        row.votes += want ? -1 : 1;
      })
      .finally(() => {
        this.busy[row.id] = false;
        m.redraw();
      });
  }

  update(row: QueueRow, body: Record<string, any>) {
    this.busy[row.id] = true;
    call('PATCH', `/questions/${row.id}`, body)
      .then(() => this.load())
      .then(() => today.load(true))
      .catch(() => {})
      .finally(() => {
        this.busy[row.id] = false;
        m.redraw();
      });
  }

  remove(row: QueueRow) {
    if (!confirm(extractText(t('queue.delete_confirm')))) return;
    call('DELETE', `/questions/${row.id}`)
      .then(() => this.load())
      .catch(() => {});
  }

  addForm(): Mithril.Children {
    return (
      <form
        className="QotdSuggest"
        onsubmit={(e: SubmitEvent) => {
          e.preventDefault();
          this.add('/questions', { body: this.bulk });
        }}
      >
        <label className="QotdSuggest-label" for="qotd-add">
          <span aria-hidden="true">➕</span> {t('pool.add_label')}
        </label>
        <textarea
          id="qotd-add"
          className="FormControl"
          rows={3}
          placeholder={extractText(t('pool.add_placeholder'))}
          value={this.bulk}
          oninput={(e: InputEvent) => {
            this.bulk = (e.target as HTMLTextAreaElement).value;
          }}
        />
        <div className="QotdSuggest-row QotdSuggest-row--end">
          <Button className="Button" icon="fas fa-box-open" loading={this.adding} onclick={() => this.add('/starter', {})}>
            {t('pool.starter')}
          </Button>
          <Button type="submit" className="Button Button--primary" loading={this.adding} disabled={!this.bulk.trim()}>
            {t('pool.add_button')}
          </Button>
        </div>
      </form>
    );
  }

  add(path: string, body: Record<string, any>) {
    this.adding = true;
    call<{ added: number; skipped: number }>('POST', path, body)
      .then((r) => {
        if (path !== '/starter') this.bulk = '';
        app.alerts.show({ type: 'success' }, t('pool.added', { added: r.added, skipped: r.skipped }));
        return this.load().then(() => today.load(true));
      })
      .catch(() => {})
      .finally(() => {
        this.adding = false;
        m.redraw();
      });
  }

  pool(): Mithril.Children {
    const pool = this.data!.pool!;
    const min = today.data?.today || '';
    return (
      <section className="QotdSection">
        <h3 className="QotdSection-title">
          {t('pool.title')} <span className="QotdSection-count">{this.data!.poolCount}</span>
        </h3>
        <p className="QotdSection-help">{t('pool.help')}</p>
        {(this.data!.poolCount || 0) < 3 ? <p className="QotdWarn">{t('pool.low')}</p> : null}
        {pool.length ? (
          <ol className="QotdQueue-list QotdPool">
            {pool.map((row, i) => (
              <li className="QotdQueue-item" key={row.id}>
                <span className={'QotdPool-pos' + (i === 0 ? ' QotdPool-pos--next' : '')}>{i === 0 ? t('pool.next') : i + 1}</span>
                <div className="QotdQueue-main">
                  <p className="QotdQueue-body">{row.body}</p>
                  <p className="QotdQueue-meta">
                    {row.author ? (
                      <>
                        {t('pool.suggested_by')} {who(row.author as Person)}
                        {' · '}
                      </>
                    ) : null}
                    {row.votes ? t('pool.votes', { count: row.votes }) : null}
                  </p>
                </div>
                <div className="QotdQueue-actions QotdPool-actions">
                  <input
                    type="date"
                    className="FormControl QotdPool-date"
                    min={min}
                    value={row.scheduledFor || ''}
                    aria-label={extractText(t('pool.schedule'))}
                    title={extractText(t('pool.schedule'))}
                    onchange={(e: Event) => this.update(row, { scheduledFor: (e.target as HTMLInputElement).value || null })}
                  />
                  <Button
                    className="Button Button--icon"
                    icon="fas fa-bolt"
                    disabled={this.data!.answeredToday}
                    aria-label={extractText(t('pool.ask_now'))}
                    title={extractText(t(this.data!.answeredToday ? 'pool.ask_now_locked' : 'pool.ask_now'))}
                    onclick={() => this.askNow(row)}
                  />
                  <Button
                    className="Button Button--icon Button--flat"
                    icon="far fa-trash-can"
                    aria-label={extractText(t('pool.delete'))}
                    title={extractText(t('pool.delete'))}
                    onclick={() => this.remove(row)}
                  />
                </div>
              </li>
            ))}
          </ol>
        ) : (
          <p className="QotdEmpty">{t('pool.empty')}</p>
        )}
      </section>
    );
  }

  askNow(row: QueueRow) {
    if (!confirm(extractText(t('pool.ask_now_confirm')))) return;
    call('POST', `/questions/${row.id}/ask`)
      .then(() => today.load(true))
      .then(() => m.route.set(app.route('question-of-the-day')))
      .catch((error) => app.alerts.show({ type: 'error' }, errorText(error)));
  }
}

interface StreakRow {
  user: Person;
  days: number;
  total: number;
}

/** Who's on a roll right now, and the longest runs ever. */
export class Streaks extends Component {
  data: { going: StreakRow[]; best: StreakRow[]; me: { current: number; best: number; total: number } | null } | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    call<any>('GET', '/streaks', undefined, true)
      .then((r) => {
        this.data = r;
      })
      .catch(() => {})
      .finally(() => m.redraw());
  }

  view() {
    if (!this.data) return <LoadingIndicator />;
    const { going, best, me } = this.data;

    return (
      <div className="QotdStreaks">
        {me ? <p className="QotdStreaks-me">{t('streaks.me', { current: me.current, best: me.best, total: me.total })}</p> : null}
        <div className="QotdBoards">
          {this.board('streaks.going', '🔥', going)}
          {this.board('streaks.best', '🏅', best)}
        </div>
      </div>
    );
  }

  board(title: string, emoji: string, rows: StreakRow[]): Mithril.Children {
    return (
      <section className="QotdBoard">
        <h3 className="QotdSection-title">
          <span aria-hidden="true">{emoji}</span> {t(title)}
        </h3>
        {rows.length ? (
          <ol className="QotdBoard-list">
            {rows.map((row, i) => (
              <li className={'QotdBoard-row' + (i < 3 ? ' QotdBoard-row--medal' : '')}>
                <span className="QotdBoard-rank">{i < 3 ? ['🥇', '🥈', '🥉'][i] : i + 1}</span>
                {face(row.user, 28)}
                {who(row.user)}
                <span className="QotdBoard-days">{t('streaks.days', { count: row.days })}</span>
              </li>
            ))}
          </ol>
        ) : (
          <p className="QotdEmpty">{t('streaks.empty')}</p>
        )}
      </section>
    );
  }
}
