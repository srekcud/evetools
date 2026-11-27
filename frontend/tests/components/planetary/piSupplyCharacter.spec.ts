import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import { usePlanetaryStore } from '@/stores/planetary'
import type { Colony } from '@/stores/planetary'
import type { SupplyLaunchpad } from '@/utils/planetarySupply'
import type { CustomsOfficeStock, PendingOutput } from '@/utils/planetaryPickup'
import { coolantFromAmarr, customsOfficeStockOf, pendingStockResponse } from '../../planetaryPendingStockFixtures'
import { referenceLaunchpad, supplyResponse } from '../../planetarySupplyFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SHARED_PLANET = 'Jita IV'
const FIRST_COLONY = 'colony-first'
const SECOND_COLONY = 'colony-second'

function colonyOf(id: string, characterName: string): Colony {
  return { id, characterName } as Colony
}

async function mountTab(
  launchpads: SupplyLaunchpad[],
  colonies: Colony[],
  outputs: PendingOutput[] = [],
  customsOfficeStocks: CustomsOfficeStock[] = [],
) {
  setActivePinia(createPinia())
  usePlanetaryStore().colonies = colonies
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary/input-needs') return Promise.resolve(supplyResponse(launchpads))
    if (endpoint === '/planetary/pending-stock') return Promise.resolve(pendingStockResponse(outputs, true, customsOfficeStocks))
    return new Promise(() => {})
  })
  const wrapper = mount(PiSupplyTab, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function twoLaunchpadsOnTheSamePlanet(): SupplyLaunchpad[] {
  return [
    referenceLaunchpad({ colonyId: FIRST_COLONY, planetName: SHARED_PLANET, pinId: 1_001 }),
    referenceLaunchpad({ colonyId: SECOND_COLONY, planetName: SHARED_PLANET, pinId: 2_002 }),
  ]
}

const TWO_COLONIES = [colonyOf(FIRST_COLONY, 'Pilot One'), colonyOf(SECOND_COLONY, 'Pilot Two')]

enableAutoUnmount(afterEach)

describe('PiSupplyTab character on planets', () => {
  it('names the character on each launchpad row so same-named planets are told apart', async () => {
    const wrapper = await mountTab(twoLaunchpadsOnTheSamePlanet(), TWO_COLONIES)

    const characters = wrapper.findAll('[data-testid="pi-supply-row"]').map(row => row.get('[data-testid="pi-supply-row-character"]').text())
    expect(characters).toEqual(['Pilot One', 'Pilot Two'])
  })

  it('names the character on each capacity warning', async () => {
    const wrapper = await mountTab(twoLaunchpadsOnTheSamePlanet(), TWO_COLONIES)
    await wrapper.get('[data-testid="pi-supply-days"]').setValue('7')
    await flushPromises()

    const warnings = wrapper.findAll('[data-testid="pi-supply-capacity-warning"]').map(warning => warning.text())
    expect(warnings).toHaveLength(2)
    expect(warnings[0]).toContain('Pilot One')
    expect(warnings[1]).toContain('Pilot Two')
  })

  it('shows the planet alone when the colony of a launchpad is unknown', async () => {
    const wrapper = await mountTab(twoLaunchpadsOnTheSamePlanet(), [])
    await wrapper.get('[data-testid="pi-supply-days"]').setValue('7')
    await flushPromises()

    expect(wrapper.findAll('[data-testid="pi-supply-row-character"]')).toHaveLength(0)
    const warning = wrapper.findAll('[data-testid="pi-supply-capacity-warning"]')[0].text()
    expect(warning).toContain(SHARED_PLANET)
    expect(warning).not.toMatch(/undefined|\(\)/)
  })

  it('names the character of each pickup source and of each bring-back row', async () => {
    const wrapper = await mountTab(
      [referenceLaunchpad()],
      TWO_COLONIES,
      [coolantFromAmarr({ characterName: 'Pilot Two' })],
      [customsOfficeStockOf()],
    )

    const colonySource = wrapper.get('[data-testid="pi-pickup-source"][data-kind="colony"]')
    expect(colonySource.get('[data-testid="pi-pickup-source-character"]').text()).toBe('Pilot Two')
    expect(wrapper.get('[data-testid="pi-bringback-row"] [data-testid="pi-bringback-character"]').text()).toBe('Pilot Two')
    expect(wrapper.get('[data-testid="pi-pickup-source"][data-kind="customs"]').find('[data-testid="pi-pickup-source-character"]').exists()).toBe(false)
  })
})
