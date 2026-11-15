import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

const t = (key: string) => app.translator.trans(`ernestdefoe-question-of-the-day.forum.${key}`);

export class NewQuestionNotification extends Notification {
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

export class AskedNotification extends NewQuestionNotification {
  icon() {
    return 'fas fa-star';
  }

  content(): Mithril.Children {
    return t('notification.asked');
  }
}
