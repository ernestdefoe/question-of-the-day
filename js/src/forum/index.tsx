import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import Component from 'flarum/common/Component';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link, the two notifications and a slot on the front page. The
 * card itself, the pages and today's data come in a chunk, fetched only
 * where the card or a question page shows.
 */

declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-question-of-the-day.forum.${key}`, params);

let chunk: any = null;
const loadChunk = () =>
  import('./chunk').then((module) => {
    chunk = module;
    return module;
  });

/** A member who hid the card hides it until tomorrow (the card stores the forum's date). */
const hiddenToday = () => {
  try {
    const until = localStorage.getItem('qotd-hidden-until');
    return !!until && Date.now() < Number(until);
  } catch (e) {
    return false;
  }
};

/** The front-page card's place: a quiet placeholder until the chunk and today's question arrive. */
class Slot extends Component {
  oncreate(vnode: Mithril.VnodeDOM<any, this>) {
    super.oncreate(vnode);
    if (!chunk) loadChunk().then(() => m.redraw());
  }

  view() {
    if (chunk) return <chunk.Hero />;
    return <div className="QotdHero QotdHero--placeholder" aria-hidden="true" />;
  }
}

class NewQuestionNotification extends Notification {
  icon() {
    return 'fas fa-comment-dots';
  }

  href() {
    return app.route('question-of-the-day');
  }

  content(): Mithril.Children {
    return t('notification.new');
  }

  excerpt(): Mithril.Children {
    return (this.attrs.notification as any).content()?.body;
  }
}

class AskedNotification extends NewQuestionNotification {
  icon() {
    return 'fas fa-star';
  }

  content(): Mithril.Children {
    return t('notification.asked');
  }
}

app.initializers.add('ernestdefoe/question-of-the-day', () => {
  const page = () => loadChunk().then(async (module) => (await module.loadCore(), module));

  app.routes['question-of-the-day'] = { path: '/question', component: page };
  app.routes['question-of-the-day.question'] = { path: '/question/:id', component: page };
  app.routes['question-of-the-day.questions'] = { path: '/questions', component: page };

  app.notificationComponents.qotdNew = NewQuestionNotification;
  app.notificationComponents.qotdAsked = AskedNotification;

  // String form: IndexSidebar lives in a lazy chunk, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('qotdCanView') || !app.forum.attribute('qotdNav')) return;
    // Active on every question page, so the phone header's section menu names this one, not "All Discussions".
    const active = String(app.current.get('routeName') || '').startsWith('question-of-the-day');
    items.add(
      'question-of-the-day',
      LinkButton.component({ href: app.route('question-of-the-day'), icon: 'fas fa-comment-dots', active }, t('nav')),
      79
    );
  });

  // The card goes above the discussion list on the front page only: tag pages and searches keep theirs.
  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('qotdCanView') || !app.forum.attribute('qotdHero') || hiddenToday()) return;
    if (m.route.param('tags') || m.route.param('q')) return;
    items.add('questionOfTheDay', <Slot />, 110);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('qotdNew', { name: 'qotdNew', icon: 'fas fa-comment-dots', label: t('settings.notify_new') });
    items.add('qotdAsked', { name: 'qotdAsked', icon: 'fas fa-star', label: t('settings.notify_asked') });
  });
});
