import { enableAutoUnmount } from '@vue/test-utils'
import FactoryBlueprints from '@/components/industry-factory/FactoryBlueprints.vue'
import { useBlueprintsStore } from '@/stores/industry/blueprints'
import type { BlueprintLibrary, BlueprintLibraryEntry } from '@/stores/industry/types'
import { mountFactory } from './mountFactory'

enableAutoUnmount(afterEach)

const BLUEPRINTS_URL = '/api/industry/blueprints'
const ROWS = '[data-testid="factory-blueprint-row"]'
const SEARCH = '[data-testid="factory-blueprint-search"]'

function blueprint(overrides: Partial<BlueprintLibraryEntry>): BlueprintLibraryEntry {
  return {
    typeId: 1000,
    name: 'Ishtar Blueprint',
    kind: 'bpo',
    me: 10,
    te: 20,
    runs: null,
    scope: 'personal',
    ownerName: 'Mira',
    locationId: 60_003_760,
    divisionNumber: null,
    divisionName: null,
    count: 1,
    usedByRunningJob: true,
    productTypeId: 12_005,
    productName: 'Ishtar',
    ...overrides,
  }
}

const LIBRARY: BlueprintLibrary = {
  corporationState: 'known',
  blueprints: [
    blueprint({}),
    blueprint({ typeId: 1001, name: 'Vexor Blueprint', kind: 'bpc', me: 2, te: 4, runs: 20, count: 5, usedByRunningJob: false, productName: 'Vexor' }),
  ],
}

async function mountBlueprints(library: BlueprintLibrary = LIBRARY) {
  return mountFactory(FactoryBlueprints, {
    answers: { [`GET ${BLUEPRINTS_URL}`]: library },
    seed: () => {
      useBlueprintsStore().library = library
    },
  })
}

describe('FactoryBlueprints', () => {
  it('lists one row per blueprint with its kind, ME and TE, flags the one in a running job and filters by name', async () => {
    const { wrapper } = await mountBlueprints()

    const rows = wrapper.findAll(ROWS)
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Ishtar Blueprint')
    expect(rows[0].attributes('data-in-job')).toBe('true')
    expect(rows[1].text()).toContain('Vexor Blueprint')
    expect(rows[1].attributes('data-in-job')).toBe('false')

    await wrapper.get(SEARCH).setValue('vexor')

    const filtered = wrapper.findAll(ROWS)
    expect(filtered).toHaveLength(1)
    expect(filtered[0].text()).toContain('Vexor Blueprint')
  })

  it('says the corporation blueprints are not readable when the corporation state is not known', async () => {
    const { wrapper } = await mountBlueprints({ ...LIBRARY, corporationState: 'no_director' })

    expect(wrapper.get('[data-testid="factory-blueprint-corporation-state"]').attributes('data-state')).toBe('no_director')
  })
})
