// The lazy part of the extension (card, pages, today's data), loaded once and shared.
let loaded: any = null;

export const chunk = () => loaded;

export const loadChunk = () =>
  import('./chunk').then((module) => {
    loaded = module;
    return module;
  });

/** A question page: the chunk, then core's page parts it draws with. */
export const loadPage = () => loadChunk().then(async (module) => (await module.loadCore(), module));
