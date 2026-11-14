import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AssetChangesPanel from '@/components/assets/AssetChangesPanel.vue'
import type { AssetChange, AssetChanges } from '@/types'

const AMMO_BOX = 'Ammo box — Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const JITA_STATION = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const MINUS = '−'

function change(overrides: Partial<AssetChange>): AssetChange {
  return {
    typeId: 21894,
    typeName: 'Caldari Navy Iridium Charge M',
    locationId: 1080000888001,
    locationName: AMMO_BOX,
    divisionName: 'Ammunition',
    quantityDelta: -4,
    ...overrides,
  }
}

function payload(changes: AssetChange[]): AssetChanges {
  return {
    syncedAt: '2026-11-13T09:00:00+00:00',
    previousSyncedAt: '2026-11-13T08:00:00+00:00',
    total: changes.length,
    changes,
  }
}

function mountPanel(changes: AssetChanges | null, error = false) {
  return mount(AssetChangesPanel, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { changes, error },
  })
}

async function expand(wrapper: ReturnType<typeof mountPanel>) {
  await wrapper.find('[data-testid="asset-changes-toggle"]').trigger('click')
}

describe('AssetChangesPanel', () => {
  it('shows the totals of added and removed units while collapsed and hides the lines', () => {
    const wrapper = mountPanel(payload([
      change({ quantityDelta: -4 }),
      change({ typeId: 24698, typeName: 'Drake', locationId: 60003760, locationName: JITA_STATION, quantityDelta: 3 }),
    ]))

    expect(wrapper.text()).toContain(en.assets.changes.title)
    expect(wrapper.find('[data-testid="asset-changes-added"]').text()).toContain('3')
    expect(wrapper.find('[data-testid="asset-changes-removed"]').text()).toContain('4')
    expect(wrapper.findAll('[data-testid="asset-changes-line"]')).toHaveLength(0)
  })

  it('lists the lines grouped by location with a signed quantity once expanded', async () => {
    const wrapper = mountPanel(payload([
      change({ quantityDelta: -4 }),
      change({ typeId: 21896, typeName: 'Caldari Navy Antimatter Charge M', quantityDelta: -2 }),
      change({ typeId: 24698, typeName: 'Drake', locationId: 60003760, locationName: JITA_STATION, divisionName: 'Ships', quantityDelta: 3 }),
    ]))

    await expand(wrapper)

    const locations = wrapper.findAll('[data-testid="asset-changes-location"]')
    expect(locations).toHaveLength(2)
    expect(locations[0]?.text()).toContain(AMMO_BOX)
    expect(locations[0]?.findAll('[data-testid="asset-changes-line"]')).toHaveLength(2)
    expect(locations[0]?.text()).toContain(`${MINUS}4`)
    expect(locations[0]?.text()).toContain('Caldari Navy Iridium Charge M')
    expect(locations[1]?.text()).toContain(JITA_STATION)
    expect(locations[1]?.text()).toContain('+3')
    expect(locations[1]?.text()).toContain('Drake')
  })

  it('colours added lines green and removed lines red', async () => {
    const wrapper = mountPanel(payload([
      change({ quantityDelta: -4 }),
      change({ typeId: 24698, typeName: 'Drake', quantityDelta: 3 }),
    ]))

    await expand(wrapper)

    const deltas = wrapper.findAll('[data-testid="asset-changes-delta"]')
    expect(deltas.map(delta => delta.text())).toEqual([`${MINUS}4`, '+3'])
    expect(deltas[0]?.classes().join(' ')).toContain('red')
    expect(deltas[1]?.classes().join(' ')).toContain('emerald')
  })

  it('shows the two sync dates of the interval', () => {
    const wrapper = mountPanel(payload([change({})]))

    const interval = wrapper.find('[data-testid="asset-changes-interval"]').text()

    expect(interval).toMatch(/2026|13/)
    expect(interval).toContain('→')
  })

  it('says nothing changed between the last two syncs when the diff has no line but dates', () => {
    const wrapper = mountPanel({ syncedAt: '2026-11-13T09:00:00+00:00', previousSyncedAt: '2026-11-13T08:00:00+00:00', total: 0, changes: [] })

    expect(wrapper.find('[data-testid="asset-changes-empty"]').text()).toBe(en.assets.changes.empty)
  })

  it('renders nothing when no diff was ever recorded', () => {
    const wrapper = mountPanel({ syncedAt: null, previousSyncedAt: null, total: 0, changes: [] })

    expect(wrapper.find('[data-testid="asset-changes-panel"]').exists()).toBe(false)
  })

  it('renders nothing while the diff is not loaded', () => {
    const wrapper = mountPanel(null)

    expect(wrapper.find('[data-testid="asset-changes-panel"]').exists()).toBe(false)
  })

  it('shows its own error message when the diff could not be read', () => {
    const wrapper = mountPanel(null, true)

    expect(wrapper.find('[data-testid="asset-changes-error"]').text()).toBe(en.assets.changes.loadFailed)
  })

  it('falls back to the type id when a type has no name', async () => {
    const wrapper = mountPanel(payload([change({ typeId: 99999, typeName: null, quantityDelta: 2 })]))

    await expand(wrapper)

    expect(wrapper.find('[data-testid="asset-changes-line"]').text()).toContain('#99999')
  })
})
