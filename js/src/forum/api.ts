import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface Person {
  id: number;
  name: string;
  username: string;
  slug: string;
  avatar: string | null;
}

export type ReactionType = 'like' | 'love' | 'haha' | 'fire' | 'wow';

export interface AnswerRow {
  id: number;
  questionId: number;
  body: string;
  author: Person | null;
  createdAt: string;
  edited: boolean;
  reactions: Partial<Record<ReactionType, number>>;
  reactionsCount: number;
  myReaction: ReactionType | null;
  mine: boolean;
  canReact: boolean;
  canEdit: boolean;
  canDelete: boolean;
}

/** A question's card. Answers arrive only when the server says you may see them. */
export interface QuestionRow {
  id: number;
  body: string;
  askedOn: string | null;
  open: boolean;
  closedAt: string | null;
  suggestedBy: Person | null;
  discussion: { id: number; slug: string } | null;
  answersCount?: number;
  answerers?: Person[];
  mine?: { id: number; body: string; createdAt: string; editedAt: string | null } | null;
  canSeeAnswers?: boolean;
  moderating?: boolean;
  answers?: AnswerRow[];
  hasMore?: boolean;
  top?: AnswerRow | null;
}

export interface TodayData {
  question: QuestionRow | null;
  previous: (QuestionRow & { top: AnswerRow | null }) | null;
  streak: { current: number; best: number; total: number };
  subscribed: boolean;
  nextAt: string;
  today: string;
  maxLength: number;
  can: { answer: boolean; suggest: boolean; moderate: boolean };
  pool: number | null;
  pending: number | null;
}

export interface QueueRow {
  id: number;
  body: string;
  votes: number;
  voted: boolean;
  author: Person | null;
  createdAt: string;
  mine: boolean;
  scheduledFor: string | null;
}

export const REACTIONS: Record<ReactionType, string> = { like: '👍', love: '❤️', haha: '😂', fire: '🔥', wow: '😮' };

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-question-of-the-day.forum.${key}`, params);

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-question-of-the-day.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return extractText(t('errors.not_found'));
  return extractText(t('errors.generic'));
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, body?: Record<string, any>, quiet = false): Promise<T> {
  return app.request<T>({
    method,
    url: app.forum.attribute<string>('apiUrl') + '/qotd' + path,
    body,
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

const locale = () => app.translator.getLocale() || undefined;

/** "Tuesday, November 10" for a forum-local Y-m-d (read as a calendar date, not shifted by the viewer's timezone). */
export function dayLabel(ymd: string | null, withYear = false): string {
  if (!ymd) return '';
  const [y, mo, d] = ymd.split('-').map(Number);
  const options: Intl.DateTimeFormatOptions = { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' };
  if (withYear) options.year = 'numeric';
  return new Intl.DateTimeFormat(locale(), options).format(new Date(Date.UTC(y, mo - 1, d)));
}

export function shortDay(ymd: string | null): string {
  if (!ymd) return '';
  const [y, mo, d] = ymd.split('-').map(Number);
  return new Intl.DateTimeFormat(locale(), { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(
    new Date(Date.UTC(y, mo - 1, d))
  );
}

/** "5h 12m" until a moment. */
export function until(iso: string): string {
  const ms = Math.max(0, new Date(iso).getTime() - Date.now());
  const h = Math.floor(ms / 36e5);
  const min = Math.floor((ms % 36e5) / 6e4);
  return h ? extractText(t('time.hours_minutes', { hours: h, minutes: min })) : extractText(t('time.minutes', { minutes: Math.max(1, min) }));
}

export function profileUrl(person: Person): string {
  return app.route('user', { username: person.slug });
}
