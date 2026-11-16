/** Cache the service worker keeps shared pictures in — the same name in `public/sw.js`. */
export const SHARE_CACHE = 'fahoba-share'

/** The service worker sends the app here after something was shared to it. */
export function sharedFromUrl(search: string): boolean {
  return new URLSearchParams(search).has('share')
}

/** The address without the marker, so a reload does not upload again. */
export function withoutShare(pathname: string, search: string): string {
  const query = new URLSearchParams(search)
  query.delete('share')
  const rest = query.toString()

  return rest === '' ? pathname : `${pathname}?${rest}`
}

/** Cache keys come back in no promised order; the share sheet's order is the index. */
export function inShareOrder(urls: string[]): string[] {
  const index = (url: string): number => Number.parseInt(url.split('/').pop() ?? '', 10)

  return [...urls].sort((a, b) => index(a) - index(b))
}
