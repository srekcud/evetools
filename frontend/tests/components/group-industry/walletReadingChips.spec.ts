import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import WalletReadingChips from '@/components/group-industry/WalletReadingChips.vue'
import { dateFormat } from '@/composables/useFormatters'
import type { WalletReading } from '@/stores/group-industry/types'

const CHIP = '[data-testid="wallet-reading"]'
const EMPTY_HINT = '[data-testid="wallet-reading-empty-hint"]'

const AUDREY_READ_AT = '2026-11-13T20:04:10+00:00'
const AUDREY_NEXT_READ_AT = '2026-11-13T21:04:00+00:00'
const BRUNO_NEXT_READ_AT = '2026-11-13T21:37:00+00:00'

const AUDREY_READ: WalletReading = { characterName: 'Audrey Vance', status: 'read', readAt: AUDREY_READ_AT, nextReadAt: AUDREY_NEXT_READ_AT }
const BRUNO_READ: WalletReading = { characterName: 'Bruno Keld', status: 'read', readAt: '2026-11-13T20:37:00+00:00', nextReadAt: BRUNO_NEXT_READ_AT }
const CELINE_MISSING_SCOPE: WalletReading = { characterName: 'Celine Orm', status: 'missing_scope', readAt: null, nextReadAt: null }
const DAMIEN_NEVER_READ: WalletReading = { characterName: 'Damien Fry', status: 'never_read', readAt: null, nextReadAt: null }
const EVA_NO_TOKEN: WalletReading = { characterName: 'Eva Strand', status: 'no_token', readAt: null, nextReadAt: null }

function clockTime(iso: string): string {
  const date = new Date(iso)
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function mountChips(readings: WalletReading[], options: { locale?: 'en' | 'fr'; emptyHint?: boolean } = {}): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: options.locale ?? 'en', fallbackLocale: 'en', messages: { en, fr } })
  return mount(WalletReadingChips, { props: { readings, emptyHint: options.emptyHint }, global: { plugins: [i18n] } })
}

function chipOf(wrapper: VueWrapper, characterName: string) {
  const chip = wrapper.findAll(CHIP).find(candidate => candidate.text().includes(characterName))
  if (chip === undefined) throw new Error(`no chip for ${characterName}`)
  return chip
}

beforeEach(() => {
  dateFormat.value = 'eu'
})

describe('WalletReadingChips', () => {
  it('tells the time of the last wallet reading and of the next one in the chip of a character that was read', () => {
    const wrapper = mountChips([AUDREY_READ])

    const chip = chipOf(wrapper, 'Audrey Vance')
    expect(normalized(chip.text())).toContain(`Wallet read at ${clockTime(AUDREY_READ_AT)} · next read around ${clockTime(AUDREY_NEXT_READ_AT)}`)
    expect(chip.attributes('data-emphasized')).toBeUndefined()
  })

  it('tells the wallet reading in French', () => {
    const wrapper = mountChips([AUDREY_READ], { locale: 'fr' })

    const text = normalized(chipOf(wrapper, 'Audrey Vance').text())
    expect(text).toContain(`Wallet lu à ${clockTime(AUDREY_READ_AT)} · prochaine lecture vers ${clockTime(AUDREY_NEXT_READ_AT)}`)
    expect(text).not.toContain('next read')
  })

  it('names the character whose wallet scope is missing and emphasizes its chip', () => {
    const wrapper = mountChips([AUDREY_READ, CELINE_MISSING_SCOPE])

    const chip = chipOf(wrapper, 'Celine Orm')
    expect(normalized(chip.text()).toLowerCase()).toContain('wallet scope missing')
    expect(chip.attributes('data-emphasized')).toBe('true')
    expect(chipOf(wrapper, 'Audrey Vance').attributes('data-emphasized')).toBeUndefined()
  })

  it('says never read for a character with the scope and no reading, and no token for a character without token', () => {
    const wrapper = mountChips([DAMIEN_NEVER_READ, EVA_NO_TOKEN])

    expect(wrapper.findAll(CHIP)).toHaveLength(2)
    expect(normalized(chipOf(wrapper, 'Damien Fry').text()).toLowerCase()).toContain('never read')
    expect(normalized(chipOf(wrapper, 'Eva Strand').text()).toLowerCase()).toContain('no token')
  })

  it('bounds the appearance of an in-game transaction by the latest next reading of the characters that were read, and points to the manual entry', () => {
    const wrapper = mountChips([AUDREY_READ, BRUNO_READ, CELINE_MISSING_SCOPE], { emptyHint: true })

    const hint = normalized(wrapper.get(EMPTY_HINT).text())
    expect(hint).toContain(clockTime(BRUNO_NEXT_READ_AT))
    expect(hint).not.toContain(clockTime(AUDREY_NEXT_READ_AT))
    expect(hint.toLowerCase()).toContain('manual')
  })
})
