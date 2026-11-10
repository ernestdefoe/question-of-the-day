import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, errorText, t, type QuestionRow, type TodayData } from '../api';
import { today } from '../store';
import QuestionCard from './QuestionCard';
import { topAnswer } from './Hero';
import { AnswerList, logIn } from './parts';
import { Archive, Queue, Streaks } from './Lists';

/**
 * /question              today's question: answer it, then everyone's answers
 * /question/:id          a past question with its answers (the archive)
 * /questions?tab=…       archive (default), suggest (the queue), streaks
 *
 * Built on core's page structure with the index sidebar, like any forum page.
 */

declare const flarum: any;
declare const m: Mithril.Static;

export let PageStructure: any;
export let IndexSidebar: any;

/** Core page parts, loaded through the registry before the page draws. */
export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

type Tab = 'today' | 'archive' | 'queue' | 'streaks';

export default class QuestionPage extends Page {
  tab: Tab = 'today';
  id = 0;
  past: QuestionRow | null = null;
  pastLoading = false;
  failed: string | null = null;
  pastSort: 'top' | 'new' = 'top';
  justAnswered = false;
  tabShown = false;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    const route = this.attrs.routeName as string;
    this.id = Number(m.route.param('id')) || 0;
    const tab = m.route.param('tab');
    this.tab = route === 'question-of-the-day.questions' ? (['queue', 'streaks'].includes(tab) ? tab : 'archive') : 'today';
    this.bodyClass = 'App--qotd';
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);

    if (!app.forum.attribute('qotdCanView')) return;
    today.load();
    if (this.id) this.loadPast();
  }

  loadPast() {
    this.pastLoading = true;
    call<{ question: QuestionRow }>('GET', `/questions/${this.id}?sort=${this.pastSort}`, undefined, true)
      .then((r) => {
        this.past = r.question;
        app.setTitle(r.question.body);
      })
      .catch((error) => {
        this.failed = errorText(error);
      })
      .finally(() => {
        this.pastLoading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="QotdPage" sidebar={() => <IndexSidebar />}>
        {!app.forum.attribute('qotdCanView') ? (
          <p className="QotdEmpty">{app.session.user ? t('page.no_permission') : t('page.guest')}</p>
        ) : (
          <div className="QotdPage-body">
            {this.tabs()}
            {this.content()}
          </div>
        )}
      </PageStructure>
    );
  }

  tabs(): Mithril.Children {
    const data = today.data;
    const tabs: [Tab, string, string, string][] = [
      ['today', 'fas fa-comment-dots', 'tabs.today', app.route('question-of-the-day')],
      ['archive', 'fas fa-box-archive', 'tabs.archive', app.route('question-of-the-day.questions')],
      ['queue', 'fas fa-lightbulb', 'tabs.queue', app.route('question-of-the-day.questions', { tab: 'queue' })],
      ['streaks', 'fas fa-fire', 'tabs.streaks', app.route('question-of-the-day.questions', { tab: 'streaks' })],
    ];
    const active = this.id ? 'archive' : this.tab;

    return (
      <nav
        className="QotdTabs"
        aria-label={extractText(t('page.title'))}
        oncreate={(vnode: Mithril.VnodeDOM) => this.showActiveTab(vnode.dom)}
        onupdate={(vnode: Mithril.VnodeDOM) => this.showActiveTab(vnode.dom)}
      >
        {tabs.map(([key, icon, label, href]) =>
          key === 'queue' && data && !data.can.suggest && !data.can.moderate ? null : (
            <Link href={href} className={'QotdTabs-tab' + (active === key ? ' active' : '')} aria-current={active === key ? 'page' : undefined}>
              <Icon name={icon} />
              <span>{t(label)}</span>
              {key === 'queue' && data?.pending ? <span className="QotdTabs-count">{data.pending}</span> : null}
            </Link>
          )
        )}
      </nav>
    );
  }

  /** On a phone the tab row scrolls sideways: bring the current tab into it (sideways only, never the page). */
  showActiveTab(nav: Element) {
    const active = nav.querySelector<HTMLElement>('.active');
    // Until today's numbers arrive (the queue's count badge widens the row); after that the row is the member's to scroll.
    if (!active || this.tabShown) return;
    if (today.data) this.tabShown = true;
    const overflow = active.getBoundingClientRect().right - nav.getBoundingClientRect().right;
    if (overflow > 0) nav.scrollLeft += overflow;
  }

  content(): Mithril.Children {
    if (this.tab === 'archive' && !this.id) return <Archive />;
    if (this.tab === 'queue') return <Queue />;
    if (this.tab === 'streaks') return <Streaks />;
    if (this.id) return this.pastView();
    return this.todayView();
  }

  todayView(): Mithril.Children {
    const data = today.data;
    if (!data) return today.failed ? <p className="QotdEmpty">{t('errors.generic')}</p> : <LoadingIndicator />;
    const question = data.question;

    if (!question) {
      return (
        <div className="QotdBlank">
          <span className="QotdBlank-icon" aria-hidden="true">
            💬
          </span>
          <h2>{t('page.none_title')}</h2>
          <p>{t('page.none_text')}</p>
          {data.can.suggest || data.can.moderate ? (
            <Link className="Button Button--primary" href={app.route('question-of-the-day.questions', { tab: 'queue' })}>
              {t(data.can.moderate ? 'page.add_some' : 'page.suggest_one')}
            </Link>
          ) : null}
        </div>
      );
    }

    return (
      <div className="QotdToday">
        <QuestionCard data={data} question={question} variant="page" onanswered={() => (this.justAnswered = true)} />
        {this.stats(data)}
        {question.canSeeAnswers ? (
          <AnswerList
            questionId={question.id}
            answers={question.answers || []}
            hasMore={!!question.hasMore}
            count={question.answersCount || 0}
            sort={today.sort}
            onsort={(sort: 'top' | 'new') => {
              today.setSort(sort);
            }}
            maxLength={data.maxLength}
            reveal={this.justAnswered}
          />
        ) : (
          this.locked(question, data)
        )}
        {data.previous?.top ? topAnswer(data.previous, data.previous.top) : null}
      </div>
    );
  }

  /** Answers you can't see yet: blurred shapes (no words) and why. */
  locked(question: QuestionRow, data: TodayData): Mithril.Children {
    const count = question.answersCount || 0;
    return (
      <section className="QotdLocked">
        <div className="QotdLocked-ghosts" aria-hidden="true">
          {[0.9, 0.6, 0.75].map((w) => (
            <div className="QotdLocked-ghost">
              <span className="QotdLocked-dot" />
              <span className="QotdLocked-bar" style={{ width: w * 100 + '%' }} />
            </div>
          ))}
        </div>
        <div className="QotdLocked-text">
          <Icon name="fas fa-lock" />
          <p>{count ? t('page.locked', { count }) : t('page.locked_none')}</p>
          {!app.session.user ? (
            <Button className="Button Button--primary" onclick={() => logIn()}>
              {t('card.log_in')}
            </Button>
          ) : null}
          {data.can.moderate && count ? (
            <Button
              className="Button QotdLocked-moderate"
              icon="fas fa-shield-halved"
              onclick={() => {
                today.setModerating(true);
              }}
            >
              {t('page.moderate')}
            </Button>
          ) : null}
        </div>
      </section>
    );
  }

  stats(data: TodayData): Mithril.Children {
    if (!app.session.user) return null;
    return (
      <div className="QotdStats">
        <div className="QotdStat">
          <span className="QotdStat-value">🔥 {data.streak.current}</span>
          <span className="QotdStat-label">{t('stats.streak')}</span>
        </div>
        <div className="QotdStat">
          <span className="QotdStat-value">{data.streak.best}</span>
          <span className="QotdStat-label">{t('stats.best')}</span>
        </div>
        <div className="QotdStat">
          <span className="QotdStat-value">{data.streak.total}</span>
          <span className="QotdStat-label">{t('stats.total')}</span>
        </div>
        <button
          type="button"
          className={'QotdStat QotdStat--toggle' + (data.subscribed ? ' QotdStat--on' : '')}
          aria-pressed={data.subscribed ? 'true' : 'false'}
          onclick={() => {
            today.subscribe(!data.subscribed);
          }}
        >
          <span className="QotdStat-value">
            <Icon name={data.subscribed ? 'fas fa-bell' : 'far fa-bell'} />
          </span>
          <span className="QotdStat-label">{t(data.subscribed ? 'stats.reminder_on' : 'stats.reminder_off')}</span>
        </button>
        {data.can.moderate && today.moderating ? (
          <button
            type="button"
            className="QotdStat QotdStat--toggle QotdStat--on"
            onclick={() => {
              today.setModerating(false);
            }}
          >
            <span className="QotdStat-value">
              <Icon name="fas fa-shield-halved" />
            </span>
            <span className="QotdStat-label">{t('stats.moderating')}</span>
          </button>
        ) : null}
      </div>
    );
  }

  pastView(): Mithril.Children {
    if (this.failed) {
      return (
        <div className="QotdBlank">
          <p>{this.failed}</p>
          <Link className="Button" href={app.route('question-of-the-day.questions')}>
            {t('page.back')}
          </Link>
        </div>
      );
    }
    const question = this.past;
    const data = today.data;
    if (!question || !data) return <LoadingIndicator />;
    if (question.open) return this.todayView();

    return (
      <div className="QotdToday">
        <QuestionCard data={data} question={question} variant="past" />
        {question.top ? topAnswer(question, question.top, 'top.winner') : null}
        <AnswerList
          questionId={question.id}
          answers={question.answers || []}
          hasMore={!!question.hasMore}
          count={question.answersCount || 0}
          sort={this.pastSort}
          onsort={(sort: 'top' | 'new') => {
            this.pastSort = sort;
            this.loadPast();
          }}
        />
      </div>
    );
  }
}
