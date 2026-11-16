import { describe, expect, it } from 'vitest'
import { inShareOrder, sharedFromUrl, withoutShare } from '../app/utils/share'

describe('share', () => {
  it('notices the marker the service worker sets', () => {
    expect(sharedFromUrl('?share=3')).toBe(true)
    expect(sharedFromUrl('?area=photos')).toBe(false)
  })

  it('strips only the marker from the address', () => {
    expect(withoutShare('/', '?share=2')).toBe('/')
    expect(withoutShare('/', '?share=2&household=4')).toBe('/?household=4')
  })

  it('keeps the order of the share sheet', () => {
    expect(inShareOrder(['https://x/share/10', 'https://x/share/2', 'https://x/share/0']))
      .toEqual(['https://x/share/0', 'https://x/share/2', 'https://x/share/10'])
  })
})
