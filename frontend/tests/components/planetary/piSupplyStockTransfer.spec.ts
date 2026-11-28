import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import type { SupplyHolders } from '@/utils/planetarySupply'
import { pendingStockResponse } from '../../planetaryPendingStockFixtures'
import { supplyResponse } from '../../planetarySupplyFixtures'
import type { SupplyLaunchpad } from '@/utils/planetarySupply'
import {
  ALPHA,
  BRAVO,
  HOLD,
  X,
  Y,
  alphaFirstLaunchpad,
  alphaSecondLaunchpad,
  bravoLaunchpad,
  holdersOf,
  knownHolder,
  referenceHolders,
  transferLaunchpads,
  unknownHolder,
} from '../../planetarySupplyTransferFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SUPPLY_HOLDERS_ENDPOINT = '/planetary/supply-holders'
const STALE_ASSETS_READ_AT = '2026-11-26T12:00:00+00:00'

const writeText = vi.fn()

async function mountTab(holders: SupplyHolders | Error, launchpads: SupplyLaunchpad[] = transferLaunchpads()) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary/input-needs') return Promise.resolve(supplyResponse(launchpads))
    if (endpoint === '/planetary/pending-stock') return Promise.resolve(pendingStockResponse([]))
    if (endpoint === SUPPLY_HOLDERS_ENDPOINT) return holders instanceof Error ? Promise.reject(holders) : Promise.resolve(holders)
    return new Promise(() => {})
  })
  const wrapper = mount(PiSupplyTab, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountTab>>

function amountOf(text: string): number {
  return Number(text.replace(/[^\d.]/g, ''))
}

function cards(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="pi-transfer-card"]')
}

function cardOf(wrapper: Wrapper, characterId: number) {
  return cards(wrapper).filter(card => card.attributes('data-character-id') === String(characterId))
}

function lettersAndDigits(text: string): string {
  return text.replace(/[^\p{L}\p{N}]/gu, '')
}

function holdersOfLine(line: ReturnType<typeof lineOf>[number]) {
  return line.findAll('[data-testid="pi-transfer-holder"]').map(holder => [
    holder.attributes('data-character-id'),
    holder.text().includes(holder.attributes('data-character-id') === String(HOLD.characterId) ? 'Hold' : 'Bravo'),
    holder.text().includes(en.pi.supply.unknown) ? null : amountOf(holder.text()),
  ])
}

function shortfallOfLine(line: ReturnType<typeof lineOf>[number]): string {
  return line.get('[data-testid="pi-transfer-shortfall"]').text()
}

function linesOf(wrapper: Wrapper, characterId: number) {
  return cardOf(wrapper, characterId).flatMap(card => card.findAll('[data-testid="pi-transfer-line"]'))
}

function lineOf(wrapper: Wrapper, characterId: number, typeId: number) {
  return linesOf(wrapper, characterId).filter(line => line.attributes('data-type-id') === String(typeId))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('PiSupplyTab stock to character', () => {
  it('shows one card per recipient sorted by name with the transfer, the volume, the holders and the total m3 of E1', async () => {
    const wrapper = await mountTab(referenceHolders())

    expect(cards(wrapper).map(card => card.get('[data-testid="pi-transfer-character"]').text())).toEqual(['Alpha', 'Bravo'])
    expect(linesOf(wrapper, ALPHA.characterId).map(line => [
      line.attributes('data-type-id'),
      amountOf(line.get('[data-testid="pi-transfer-quantity"]').text()),
      amountOf(line.get('[data-testid="pi-transfer-volume"]').text()),
    ])).toEqual([[String(X), 900, 675], [String(Y), 500, 375]])
    expect(cardOf(wrapper, ALPHA.characterId).map(card => amountOf(card.get('[data-testid="pi-transfer-total"]').text()))).toEqual([1050])
    expect(cardOf(wrapper, BRAVO.characterId).map(card => amountOf(card.get('[data-testid="pi-transfer-total"]').text()))).toEqual([300])
    expect(lineOf(wrapper, ALPHA.characterId, Y).flatMap(line => line.findAll('[data-testid="pi-transfer-holder"]')).map(holder => [
      holder.attributes('data-character-id'),
      holder.text().includes('Hold'),
      amountOf(holder.text()),
    ])).toEqual([[String(HOLD.characterId), true, 100]])
  })

  it('copies the transfers of a recipient as a multibuy list and disables the copy of a recipient with nothing to receive', async () => {
    const wrapper = await mountTab(holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      knownHolder(BRAVO, [{ typeId: X, quantity: 400 }]),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
    ]))

    const copyButtons = (characterId: number) => cardOf(wrapper, characterId).map(card => card.get('[data-testid="pi-transfer-copy"]'))
    expect(copyButtons(BRAVO.characterId).map(button => button.attributes('disabled') !== undefined)).toEqual([true])
    await copyButtons(ALPHA.characterId)[0].trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    expect(writeText).toHaveBeenCalledWith('Coolant\t900\nMechanical Parts\t500')
  })

  it('shows the need with an unknown transfer and links to the Stock settings when the home location is not set', async () => {
    const wrapper = await mountTab(holdersOf([knownHolder(ALPHA, []), knownHolder(BRAVO, []), knownHolder(HOLD, [])], null))

    expect(lineOf(wrapper, ALPHA.characterId, X).map(line => [
      amountOf(line.get('[data-testid="pi-transfer-need"]').text()),
      line.get('[data-testid="pi-transfer-quantity"]').text(),
      line.get('[data-testid="pi-transfer-volume"]').text(),
    ])).toEqual([[1200, en.pi.supply.unknown, en.pi.supply.unknown]])
    const homeUnset = wrapper.findAll('[data-testid="pi-transfer-home-unset"]')
    expect(homeUnset).toHaveLength(1)
    await homeUnset[0].get('[data-testid="pi-transfer-open-stock"]').trigger('click')

    expect(wrapper.emitted('open-stock')).toHaveLength(1)
  })

  it('marks the character whose assets were never read and the character whose assets are stale with their read date', async () => {
    const wrapper = await mountTab(holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      unknownHolder(BRAVO, 'assets_never_read'),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }], { assetsReadAt: STALE_ASSETS_READ_AT, stale: true }),
    ]))

    const unknownMarks = wrapper.findAll('[data-testid="pi-transfer-assets-unknown"]')
    expect(unknownMarks.map(mark => [mark.attributes('data-character-id'), mark.attributes('data-reason'), mark.text().includes('Bravo')]))
      .toEqual([[String(BRAVO.characterId), 'assets_never_read', true]])
    const staleMarks = wrapper.findAll('[data-testid="pi-transfer-assets-stale"]')
    expect(staleMarks.map(mark => [mark.attributes('data-character-id'), mark.text().includes('Hold'), mark.text().includes('26/11')]))
      .toEqual([[String(HOLD.characterId), true, true]])
    expect(wrapper.find('[data-testid="pi-transfer-home-unset"]').exists()).toBe(false)
  })

  it('shows the error of a failing supply holders read in the block and keeps the grid', async () => {
    const wrapper = await mountTab(new Error('supply holders unavailable'))

    const blocks = wrapper.findAll('[data-testid="pi-transfer-block"]')
    expect(blocks.map(block => block.findComponent(ErrorBanner).exists() ? block.findComponent(ErrorBanner).props('message') : null))
      .toEqual(['supply holders unavailable'])
    expect(cards(wrapper)).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="pi-supply-row"]')).toHaveLength(3)
  })

  it('shows the shortfall of 400 Y and none for X of E1 and folds the detail into the grid lines of the character', async () => {
    const wrapper = await mountTab(referenceHolders())

    expect(lineOf(wrapper, ALPHA.characterId, X).map(line => amountOf(shortfallOfLine(line)))).toEqual([0])
    expect(lineOf(wrapper, ALPHA.characterId, Y).map(line => amountOf(shortfallOfLine(line)))).toEqual([400])
    const details = cardOf(wrapper, ALPHA.characterId).flatMap(card => card.findAll('[data-testid="pi-transfer-details"]'))
    expect(details.map(detail => (detail.element as HTMLDetailsElement).open)).toEqual([false])
    expect(details[0].findAll('[data-testid="pi-transfer-detail-row"]').map(row => lettersAndDigits(row.text()))).toEqual([
      'AlphaILaunchpadCoolant1000MechanicalParts500',
      'AlphaIILaunchpadCoolant200',
    ])
    expect(cardOf(wrapper, BRAVO.characterId).flatMap(card => card.findAll('[data-testid="pi-transfer-detail-row"]').map(row => lettersAndDigits(row.text()))))
      .toEqual(['BravoILaunchpadCoolant400'])
  })

  it('shows an unknown shortfall, an unknown transfer and the unknown holder Bravo for E4', async () => {
    const wrapper = await mountTab(holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      unknownHolder(BRAVO, 'assets_never_read'),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
    ]))

    const bravoX = lineOf(wrapper, BRAVO.characterId, X)
    expect(bravoX.map(line => [
      amountOf(line.get('[data-testid="pi-transfer-need"]').text()),
      line.get('[data-testid="pi-transfer-quantity"]').text(),
      shortfallOfLine(line),
    ])).toEqual([[400, en.pi.supply.unknown, en.pi.supply.unknown]])
    expect(bravoX.map(holdersOfLine)).toEqual([[[String(HOLD.characterId), true, 3000], [String(BRAVO.characterId), true, null]]])
    expect(lineOf(wrapper, ALPHA.characterId, Y).map(shortfallOfLine)).toEqual([en.pi.supply.unknown])
  })

  it('lists Hold as an unknown holder with its reason and shows unknown shortfalls for E5', async () => {
    const wrapper = await mountTab(holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      knownHolder(BRAVO, []),
      unknownHolder(HOLD, 'missing_assets_scope'),
    ]))

    const alphaX = lineOf(wrapper, ALPHA.characterId, X)
    expect(alphaX.map(holdersOfLine)).toEqual([[[String(HOLD.characterId), true, null]]])
    expect(alphaX.map(shortfallOfLine)).toEqual([en.pi.supply.unknown])
    expect(wrapper.findAll('[data-testid="pi-transfer-assets-unknown"]').map(mark => [
      mark.attributes('data-reason'),
      mark.text().includes(en.pi.supply.transfer.reasons.missing_assets_scope),
    ])).toEqual([['missing_assets_scope', true]])
  })

  it('says 1 launchpad is left out for Alpha and shows the excluded launchpad as unknown in the detail for E6', async () => {
    const wrapper = await mountTab(referenceHolders(), [alphaFirstLaunchpad(), alphaSecondLaunchpad({ capacityM3: null }), bravoLaunchpad()])

    expect(cards(wrapper).map(card => card.findAll('[data-testid="pi-transfer-excluded"]').map(excluded => excluded.text()))).toEqual([
      ['1 launchpad left out of this character\'s need, its capacity or an input volume is unknown.'],
      [],
    ])
    expect(lineOf(wrapper, ALPHA.characterId, X).map(line => amountOf(line.get('[data-testid="pi-transfer-quantity"]').text()))).toEqual([700])
    expect(cardOf(wrapper, ALPHA.characterId).flatMap(card => card.findAll('[data-testid="pi-transfer-detail-row"]').map(row => lettersAndDigits(row.text()))))
      .toEqual(['AlphaILaunchpadCoolant1000MechanicalParts500', `AlphaIILaunchpad${lettersAndDigits(en.pi.supply.unknown)}`])
  })

  it('leaves X out of the multibuy of Alpha when it holds 2 000 X, and lists Hold 3 000 then Alpha 800 as X holders for E2', async () => {
    const wrapper = await mountTab(holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 2000 }]),
      knownHolder(BRAVO, []),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
    ]))

    await cardOf(wrapper, ALPHA.characterId)[0].get('[data-testid="pi-transfer-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    expect(writeText).toHaveBeenCalledWith('Mechanical Parts\t500')
    expect(lineOf(wrapper, ALPHA.characterId, X).flatMap(line => line.findAll('[data-testid="pi-transfer-holder"]')).map(holder => [
      holder.attributes('data-character-id'),
      amountOf(holder.text()),
    ])).toEqual([[String(HOLD.characterId), 3000], [String(ALPHA.characterId), 800]])
  })

  it('uses the stale quantities of Hold for the transfers and the holders and still marks them stale for E8', async () => {
    const wrapper = await mountTab(holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      knownHolder(BRAVO, []),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }], { assetsReadAt: STALE_ASSETS_READ_AT, stale: true }),
    ]))

    expect(linesOf(wrapper, ALPHA.characterId).map(line => amountOf(line.get('[data-testid="pi-transfer-quantity"]').text()))).toEqual([900, 500])
    expect(lineOf(wrapper, ALPHA.characterId, X).map(holdersOfLine)).toEqual([[[String(HOLD.characterId), true, 3000]]])
    expect(lineOf(wrapper, ALPHA.characterId, Y).map(shortfallOfLine).map(amountOf)).toEqual([400])
    expect(wrapper.findAll('[data-testid="pi-transfer-assets-stale"]')).toHaveLength(1)
  })

  it('hides the whole block for 0 days and shows it again for 4 days', async () => {
    const wrapper = await mountTab(referenceHolders())

    await wrapper.get('[data-testid="pi-supply-days"]').setValue('0')
    await flushPromises()
    const hidden = wrapper.findAll('[data-testid="pi-transfer-block"]').length
    await wrapper.get('[data-testid="pi-supply-days"]').setValue('4')
    await flushPromises()

    expect(hidden).toBe(0)
    expect(cards(wrapper).map(card => card.get('[data-testid="pi-transfer-character"]').text())).toEqual(['Alpha', 'Bravo'])
  })
})
