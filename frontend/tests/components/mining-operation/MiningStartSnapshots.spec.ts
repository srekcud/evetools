import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import MiningStartSnapshots from '@/components/mining-operation/MiningStartSnapshots.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type { MiningLedgerParticipant } from '@/stores/miningOperation'
import { ledgerCharacter, participant } from '../../views/MiningOperation/miningOperationLedgerFixtures'

const HULK_TYPE_ID = 22_544
const ORCA_TYPE_ID = 28_606
const LONG_SHIP_NAME = 'Hulk Exhumer-Valdemar Edition Prototype de la Ceinture Extérieure'
const CHARACTER = '[data-testid="snapshot-character"]'
const SHIP = '[data-testid="snapshot-character-ship"]'

function mountSnapshots(participants: MiningLedgerParticipant[], locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(MiningStartSnapshots, { props: { participants }, global: { plugins: [i18n], stubs: { 'router-link': true } } })
}

enableAutoUnmount(afterEach)

describe('MiningStartSnapshots', () => {
  it('shows the ship of each character next to its name and wraps a long ship name', () => {
    const wrapper = mountSnapshots([
      participant('member-aria', 'Aria Solberg', [
        ledgerCharacter('Aria II', { shipTypeId: ORCA_TYPE_ID, shipName: 'Orca' }),
        ledgerCharacter('Aria Solberg', { shipTypeId: HULK_TYPE_ID, shipName: LONG_SHIP_NAME }),
      ]),
    ])

    const rows = wrapper.findAll(CHARACTER)
    const ships = wrapper.findAll(SHIP)
    expect({
      rows: rows.map(row => [row.text().includes('Aria II'), row.text().includes('Orca')]),
      longShipRow: [rows[1]?.text().includes('Aria Solberg'), rows[1]?.text().includes(LONG_SHIP_NAME)],
      longShipWraps: ships[1]?.classes().includes('[overflow-wrap:anywhere]'),
    }).toEqual({
      rows: [[true, true], [false, false]],
      longShipRow: [true, true],
      longShipWraps: true,
    })
  })

  it('says the ship is unknown for a character without a known ship instead of naming a default ship', () => {
    const wrapper = mountSnapshots([
      participant('member-brann', 'Brann Holt', [
        ledgerCharacter('Brann Holt', { shipTypeId: HULK_TYPE_ID, shipName: 'Hulk' }),
        ledgerCharacter('Brann II'),
      ]),
    ], 'fr')

    const rows = wrapper.findAll(CHARACTER)
    expect([
      rows[0]?.text().includes('Vaisseau inconnu'),
      rows[1]?.text().includes('Brann II'),
      rows[1]?.text().includes('Vaisseau inconnu'),
      rows[1]?.text().includes('Hulk'),
    ]).toEqual([false, true, true, false])
  })
})
