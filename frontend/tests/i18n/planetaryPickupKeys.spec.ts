import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const PICKUP_KEYS = ['title', 'help', 'needed', 'amount', 'remaining', 'sources', 'unknownSource', 'unknownNote', 'incomplete', 'loading', 'errors.load']
const BRING_BACK_KEYS = ['title', 'planet', 'product', 'quantity', 'volume', 'destination', 'to', 'unplaced', 'empty', 'unknown', 'age']

type Locale = { pi: { supply: object } }

function leaf(locale: Locale, block: 'pickup' | 'bringBack', path: string): unknown {
  return path.split('.').reduce<unknown>(
    (node, key) => (typeof node === 'object' && node !== null ? (node as Record<string, unknown>)[key] : undefined),
    (locale.pi.supply as Record<string, unknown>)[block],
  )
}

describe('planetary pending stock labels', () => {
  it('has every pickup and bring-back label in English and French and never calls the unplaced destination a home', () => {
    for (const locale of [en, fr]) {
      const pickup = PICKUP_KEYS.map(path => typeof leaf(locale, 'pickup', path) === 'string' && (leaf(locale, 'pickup', path) as string).length > 0)
      const bringBack = BRING_BACK_KEYS.map(path => typeof leaf(locale, 'bringBack', path) === 'string' && (leaf(locale, 'bringBack', path) as string).length > 0)

      expect(pickup).toEqual(PICKUP_KEYS.map(() => true))
      expect(bringBack).toEqual(BRING_BACK_KEYS.map(() => true))
    }
    expect(String(leaf(en, 'bringBack', 'unplaced'))).not.toMatch(/home/i)
    expect(String(leaf(fr, 'bringBack', 'unplaced'))).not.toMatch(/maison/i)
  })
})
