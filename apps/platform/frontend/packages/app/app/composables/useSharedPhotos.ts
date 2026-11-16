import { SHARE_CACHE, inShareOrder } from '~/utils/share'

/*
 * Pictures shared to the app from the gallery or a messenger. The service
 * worker keeps them in a cache (`public/sw.js`); taking them empties it, so
 * they are uploaded once.
 */
export function useSharedPhotos() {
  async function take(): Promise<File[]> {
    if (!('caches' in window)) {
      return []
    }

    const cache = await caches.open(SHARE_CACHE)
    const files: File[] = []

    for (const url of inShareOrder((await cache.keys()).map(request => request.url))) {
      const response = await cache.match(url)

      if (response) {
        const blob = await response.blob()
        files.push(new File([blob], decodeURIComponent(response.headers.get('X-Name') ?? 'photo'), { type: blob.type }))
      }
    }

    await drop()

    return files
  }

  async function drop(): Promise<void> {
    if ('caches' in window) {
      await caches.delete(SHARE_CACHE)
    }
  }

  return { take, drop }
}
