import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-question-of-the-day.admin.${key}`, params, true);

/** Every IANA timezone the browser knows, the forum's current one first if it's unusual. */
function timezones(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.timezone_utc')) };
  const list: string[] = (Intl as any).supportedValuesOf ? (Intl as any).supportedValuesOf('timeZone') : [];
  const current = String(app.data.settings['question-of-the-day.timezone'] || '');
  if (current && !list.includes(current)) list.unshift(current);
  for (const zone of list) options[zone] = zone.replace(/_/g, ' ');
  return options;
}

/** Tags the daily discussion can go in, when flarum/tags is on. */
function tagOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.tag_none')) };
  for (const tag of app.store.all<any>('tags')) options[tag.id()] = tag.name();
  return options;
}

const browserZone = () => {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch (e) {
    return 'UTC';
  }
};

export default [
  new Extend.Admin()
    .setting(
      () => ({
        setting: 'question-of-the-day.timezone',
        type: 'select',
        options: timezones(),
        default: '',
        label: t('settings.timezone'),
        help: t('settings.timezone_help', { zone: browserZone() }),
      }),
      100
    )
    .setting(() => ({ setting: 'question-of-the-day.hero', type: 'boolean', label: t('settings.hero'), help: t('settings.hero_help') }), 95)
    .setting(() => ({ setting: 'question-of-the-day.nav', type: 'boolean', label: t('settings.nav') }), 90)
    .setting(
      () => ({
        setting: 'question-of-the-day.max_length',
        type: 'number',
        min: 20,
        max: 2000,
        label: t('settings.max_length'),
        help: t('settings.max_length_help'),
      }),
      85
    )
    .setting(
      () => ({ setting: 'question-of-the-day.discussion', type: 'boolean', label: t('settings.discussion'), help: t('settings.discussion_help') }),
      80
    )
    .setting(
      () =>
        'flarum-tags' in ((globalThis as any).flarum?.extensions || {})
          ? {
              setting: 'question-of-the-day.tag',
              type: 'select',
              options: tagOptions(),
              default: '',
              label: t('settings.tag'),
              help: t('settings.tag_help'),
            }
          : null,
      75
    )
    .setting(() => ({ setting: 'question-of-the-day.poster', type: 'text', label: t('settings.poster'), help: t('settings.poster_help') }), 70)
    .customSetting(
      () => (
        <div className="Form-group">
          <label>{t('settings.pool')}</label>
          <p className="helpText">
            {t('settings.pool_help')}{' '}
            <a href={app.forum.attribute('baseUrl') + '/questions?tab=queue'} target="_blank" rel="noopener">
              {t('settings.pool_link')}
            </a>
          </p>
        </div>
      ),
      60
    )
    .permission(
      () => ({ permission: 'question-of-the-day.view', icon: 'fas fa-comment-dots', label: t('permissions.view'), allowGuest: true }),
      'view',
      56
    )
    .permission(() => ({ permission: 'question-of-the-day.answer', icon: 'fas fa-reply', label: t('permissions.answer') }), 'reply', 56)
    .permission(() => ({ permission: 'question-of-the-day.suggest', icon: 'fas fa-lightbulb', label: t('permissions.suggest') }), 'start', 56)
    .permission(
      () => ({ permission: 'question-of-the-day.moderate', icon: 'fas fa-shield-halved', label: t('permissions.moderate') }),
      'moderate',
      56
    ),
];
