import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, profileUrl, REACTIONS, t, type AnswerRow, type Person, type ReactionType } from '../api';
import { react, today } from '../store';

declare const m: Mithril.Static;

export function face(person: Person | null, size = 32): Mithril.Children {
  const style = { width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.42) + 'px' };
  if (person?.avatar) return <img className="QotdFace" src={person.avatar} alt="" loading="lazy" decoding="async" style={style} />;
  const name = person?.name || '?';
  let hue = 0;
  for (const ch of name) hue = (hue * 31 + ch.charCodeAt(0)) % 360;
  return (
    <span className="QotdFace QotdFace--initial" style={{ ...style, '--qotd-face': `hsl(${hue}, 45%, 50%)` }} aria-hidden="true">
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

export function who(person: Person | null): Mithril.Children {
  if (!person) return <span className="QotdWho QotdWho--gone">{t('answer.deleted_user')}</span>;
  return (
    <Link className="QotdWho" href={profileUrl(person)}>
      {person.name}
    </Link>
  );
}

/** A row of faces: who answered (never what they said). */
export function faces(people: Person[], max = 5): Mithril.Children {
  return <span className="QotdFaces">{people.slice(0, max).map((person) => face(person, 26))}</span>;
}

export function reactions(answer: AnswerRow): Mithril.Children {
  const types = Object.keys(REACTIONS) as ReactionType[];
  const shown = answer.canReact ? types : types.filter((type) => (answer.reactions[type] || 0) > 0);
  if (!shown.length) return null;

  return (
    <div className="QotdReactions" role="group" aria-label={extractText(t('answer.reactions'))}>
      {shown.map((type) => {
        const count = answer.reactions[type] || 0;
        const active = answer.myReaction === type;
        const label = extractText(t('reaction.' + type));
        return answer.canReact ? (
          <button
            type="button"
            className={'QotdReact' + (active ? ' QotdReact--active' : '') + (count ? '' : ' QotdReact--zero')}
            aria-pressed={active ? 'true' : 'false'}
            title={label}
            aria-label={label}
            onclick={() => react(answer, type)}
          >
            <span className="QotdReact-emoji">{REACTIONS[type]}</span>
            {count ? <span className="QotdReact-count">{count}</span> : null}
          </button>
        ) : (
          <span className="QotdReact QotdReact--static" title={label}>
            <span className="QotdReact-emoji">{REACTIONS[type]}</span>
            <span className="QotdReact-count">{count}</span>
          </span>
        );
      })}
    </div>
  );
}

interface ItemAttrs {
  answer: AnswerRow;
  index?: number;
  maxLength?: number;
  compact?: boolean;
  onremove?: (answer: AnswerRow) => void;
}

/** One answer: who, when, what, and the reactions. Text only, never HTML. */
export class AnswerItem extends Component<ItemAttrs> {
  editing = false;
  draft = '';
  saving = false;

  view() {
    const { answer, index = 0, compact } = this.attrs;

    return (
      <li
        className={'QotdAnswer' + (answer.mine ? ' QotdAnswer--mine' : '') + (compact ? ' QotdAnswer--compact' : '')}
        style={{ '--i': Math.min(index, 12) }}
      >
        {face(answer.author, compact ? 28 : 36)}
        <div className="QotdAnswer-main">
          <div className="QotdAnswer-meta">
            {who(answer.author)}
            {answer.mine ? <span className="QotdAnswer-you">{t('answer.you')}</span> : null}
            <span className="QotdAnswer-time">{humanTime(answer.createdAt as any)}</span>
            {answer.edited ? <span className="QotdAnswer-edited">{t('answer.edited')}</span> : null}
          </div>
          {this.editing ? this.editor() : <p className="QotdAnswer-body">{answer.body}</p>}
          {compact ? null : (
            <div className="QotdAnswer-foot">
              {reactions(answer)}
              {this.editing ? null : this.actions()}
            </div>
          )}
        </div>
        {compact && answer.reactionsCount ? <span className="QotdAnswer-score">{answer.reactionsCount}</span> : null}
      </li>
    );
  }

  actions(): Mithril.Children {
    const { answer } = this.attrs;
    if (!answer.canEdit && !answer.canDelete) return null;

    return (
      <span className="QotdAnswer-actions">
        {answer.canEdit ? (
          <button type="button" className="QotdLinkButton" onclick={() => ((this.editing = true), (this.draft = answer.body))}>
            {t('answer.edit')}
          </button>
        ) : null}
        {answer.canDelete ? (
          <button type="button" className="QotdLinkButton QotdLinkButton--danger" onclick={() => this.remove()}>
            {t('answer.delete')}
          </button>
        ) : null}
      </span>
    );
  }

  editor(): Mithril.Children {
    const max = this.attrs.maxLength || 280;
    return (
      <div className="QotdAnswer-editor">
        <textarea
          className="FormControl"
          rows={2}
          maxlength={max}
          value={this.draft}
          aria-label={extractText(t('answer.edit'))}
          oninput={(e: InputEvent) => (this.draft = (e.target as HTMLTextAreaElement).value)}
        />
        <div className="QotdAnswer-editorButtons">
          <Button className="Button Button--primary" loading={this.saving} disabled={!this.draft.trim()} onclick={() => this.save()}>
            {t('answer.save')}
          </Button>
          <Button className="Button" onclick={() => (this.editing = false)}>
            {t('answer.cancel')}
          </Button>
        </div>
      </div>
    );
  }

  save() {
    this.saving = true;
    today
      .edit(this.attrs.answer.id, this.draft)
      .then(() => (this.editing = false))
      .catch(() => {})
      .finally(() => ((this.saving = false), m.redraw()));
  }

  remove() {
    const { answer } = this.attrs;
    if (!confirm(extractText(t(answer.mine ? 'answer.delete_mine_confirm' : 'answer.delete_confirm')))) return;
    if (this.attrs.onremove) return this.attrs.onremove(answer);
    today.remove(answer).catch(() => {});
  }
}

interface ListAttrs {
  questionId: number;
  answers: AnswerRow[];
  hasMore: boolean;
  count: number;
  sort: 'top' | 'new';
  onsort?: (sort: 'top' | 'new') => void;
  maxLength?: number;
  reveal?: boolean;
}

/** The answers, with Top / New and "More answers" (pages of 30, fetched as you go). */
export class AnswerList extends Component<ListAttrs> {
  extra: AnswerRow[] = [];
  loadingMore = false;
  more: boolean | null = null;
  key = '';

  view() {
    const { answers, sort, onsort, count, reveal } = this.attrs;
    const key = `${this.attrs.questionId}:${sort}:${answers.map((a) => a.id).join(',')}`;
    if (key !== this.key) {
      this.key = key;
      this.extra = [];
      this.more = null;
    }
    const rows = answers.concat(this.extra);
    const hasMore = this.more ?? this.attrs.hasMore;

    return (
      <section className={'QotdAnswers' + (reveal ? ' QotdAnswers--reveal' : '')}>
        <header className="QotdAnswers-head">
          <h3 className="QotdAnswers-title">{t('answers.title', { count })}</h3>
          {onsort ? (
            <div className="QotdSegmented" role="tablist">
              {(['top', 'new'] as const).map((value) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={sort === value ? 'true' : 'false'}
                  className={sort === value ? 'active' : ''}
                  onclick={() => onsort(value)}
                >
                  {t('answers.sort_' + value)}
                </button>
              ))}
            </div>
          ) : null}
        </header>
        {rows.length ? (
          <ol className="QotdAnswers-list">
            {rows.map((answer, i) => (
              <AnswerItem key={answer.id} answer={answer} index={i} maxLength={this.attrs.maxLength} />
            ))}
          </ol>
        ) : (
          <p className="QotdEmpty">{t('answers.none')}</p>
        )}
        {hasMore ? (
          <div className="QotdAnswers-more">
            <Button className="Button" loading={this.loadingMore} onclick={() => this.loadMore(rows.length)}>
              {t('answers.more')}
            </Button>
          </div>
        ) : null}
      </section>
    );
  }

  loadMore(offset: number) {
    this.loadingMore = true;
    const query = new URLSearchParams({ sort: this.attrs.sort, offset: String(offset) });
    if (today.moderating) query.set('moderate', '1');
    call<{ answers: AnswerRow[]; hasMore: boolean }>('GET', `/questions/${this.attrs.questionId}/answers?${query}`)
      .then((page) => {
        const seen = new Set(this.attrs.answers.concat(this.extra).map((a) => a.id));
        this.extra = this.extra.concat(page.answers.filter((a) => !seen.has(a.id)));
        this.more = page.hasMore;
      })
      .catch(() => {})
      .finally(() => ((this.loadingMore = false), m.redraw()));
  }
}

/** Opens the log-in modal (a lazy chunk in Flarum 2). */
export function logIn() {
  app.modal.show(() => (globalThis as any).flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'));
}
