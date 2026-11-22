import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FlowDetailSupplyBlock from '@/components/group-industry/FlowDetailSupplyBlock.vue'
import type { BomItem, LineReservation } from '@/stores/group-industry/types'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { bomLineOf, flowCard } from './projectFlowFixtures'

const NOCXIUM = { typeId: 38, typeName: 'Nocxium' }
const RESERVED_BY_OTHERS = '[data-testid="flow-detail-reserved-by-others"]'

enableAutoUnmount(afterEach)

function reservation(participantId: string, characterName: string | null, quantity: number, isMine: boolean): LineReservation {
  return { participantId, characterName, quantity, isMine, expiresAt: '2026-11-24T10:00:00+00:00' }
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function mountStockBlock(line: BomItem, locale: TestLocale): VueWrapper {
  setActivePinia(createPinia())
  return mount(FlowDetailSupplyBlock, {
    props: { kind: 'useMyStock', card: flowCard('onSite', { ...NOCXIUM, quantity: 600, source: 'stock', key: 'onSite:38:azbel' }), line },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe.each(TEST_LOCALES)('FlowDetailSupplyBlock use my stock in %s', (locale) => {
  it('lists the reservations of the other members with their name and quantity, and not the ones of the pilot', () => {
    const line = bomLineOf({
      id: 'line-nocxium',
      ...NOCXIUM,
      reservedQuantity: 90,
      reservations: [
        reservation('participant-mira', 'Mira Kessel', 40, false),
        reservation('participant-me', 'Aria Solberg', 30, true),
        reservation('participant-kaelen', 'Kaelen Dray', 20, false),
      ],
    })

    const block = mountStockBlock(line, locale).get(RESERVED_BY_OTHERS)

    const text = squashed(block.text())
    expect(text).toContain('Mira Kessel 40')
    expect(text).toContain('Kaelen Dray 20')
    expect(text).not.toContain('Aria Solberg')
    expect(text).not.toContain('30')
  })

  it('lists nothing when the only reservation is the one of the pilot', () => {
    const line = bomLineOf({ id: 'line-nocxium', ...NOCXIUM, reservedQuantity: 30, reservations: [reservation('participant-me', 'Aria Solberg', 30, true)] })

    expect(mountStockBlock(line, locale).find(RESERVED_BY_OTHERS).exists()).toBe(false)
  })
})
