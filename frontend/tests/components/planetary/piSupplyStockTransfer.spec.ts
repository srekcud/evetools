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
import {
  ALPHA,
  BRAVO,
  HOLD,
  X,
  Y,
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

async function mountTab(holders: SupplyHolders | Error) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary/input-needs') return Promise.resolve(supplyResponse(transferLaunchpads()))
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
})
