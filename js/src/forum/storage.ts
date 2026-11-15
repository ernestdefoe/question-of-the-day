/**
 * Browser storage that may be missing: private windows and blocked site data throw on access.
 * Nothing here is essential (a draft, "hide until tomorrow"), so a blocked store simply remembers nothing.
 */
type Kind = 'localStorage' | 'sessionStorage';

const store = (kind: Kind): Storage | null => {
  try {
    return window[kind];
  } catch (error) {
    return null;
  }
};

export function readStored(kind: Kind, key: string): string | null {
  try {
    return store(kind)?.getItem(key) ?? null;
  } catch (error) {
    return null;
  }
}

export function writeStored(kind: Kind, key: string, value: string | null): boolean {
  try {
    const target = store(kind);
    if (!target) return false;
    value === null ? target.removeItem(key) : target.setItem(key, value);
    return true;
  } catch (error) {
    return false;
  }
}
