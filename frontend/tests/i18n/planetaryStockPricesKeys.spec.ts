import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const SIGNALS = ['buy_jita', 'buy_hub', 'outbid', 'place_order']
const PRICE_KEYS = ['noOrder', 'loadError', ...SIGNALS.map(signal => `signal.${signal}`)]
const TODO_KEYS = ['bringBack.text', 'bringBack.effect', 'bringBack.effectUnknownVolume', 'toBuy.text', 'toBuy.effect', 'buyJita.text', 'buyJita.effect', 'outbid.text', 'outbid.effect']
const TODO_PLACEHOLDERS: Record<string, string[]> = {
  'bringBack.text': ['{type}', '{quantity}'],
  'bringBack.effect': ['{volume}'],
  'toBuy.text': ['{type}', '{quantity}'],
  'toBuy.effect': ['{volume}'],
  'buyJita.text': ['{type}', '{jitaSell}', '{paidPrice}'],
  'buyJita.effect': ['{gap}'],
  'outbid.text': ['{type}', '{myBuyOrder}', '{hubBestBuy}'],
  'outbid.effect': ['{gap}'],
}

type Locale = { pi: { stock: Record<string, unknown> } }

function leaf(locale: Locale, block: 'prices' | 'todo', path: string): unknown {
  return path.split('.').reduce<unknown>(
    (node, key) => (typeof node === 'object' && node !== null ? (node as Record<string, unknown>)[key] : undefined),
    locale.pi.stock[block],
  )
}

function isFilled(value: unknown): boolean {
  return typeof value === 'string' && value.length > 0
}

describe('planetary stock prices labels', () => {
  it('has every price grid label, signal label and to-do sentence in English and French, with the placeholders the to-do points fill', () => {
    for (const locale of [en, fr] as Locale[]) {
      expect(PRICE_KEYS.map(path => isFilled(leaf(locale, 'prices', path)))).toEqual(PRICE_KEYS.map(() => true))
      expect(TODO_KEYS.map(path => isFilled(leaf(locale, 'todo', path)))).toEqual(TODO_KEYS.map(() => true))
      for (const [path, placeholders] of Object.entries(TODO_PLACEHOLDERS)) {
        for (const placeholder of placeholders) {
          expect(String(leaf(locale, 'todo', path)), path).toContain(placeholder)
        }
      }
    }
    expect(SIGNALS.map(signal => leaf(fr as Locale, 'prices', `signal.${signal}`))).not.toEqual(SIGNALS.map(signal => leaf(en as Locale, 'prices', `signal.${signal}`)))
  })
})
