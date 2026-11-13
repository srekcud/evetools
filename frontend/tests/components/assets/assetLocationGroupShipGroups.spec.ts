import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'

const LOCATION_ID = 60003760
const SHIP_CATEGORY_ID = 6
const COLLAPSED_GROUP_LIMIT = 8
const EXPECTED_EFT = ['[Rifter, Alpha]', 'Damage Control II', '', 'Experimental 10MN Microwarpdrive I'].join('\n')

let nextItemId = 5000

function asset(overrides: Partial<Asset>): Asset {
  nextItemId += 1
  return {
    id: `00000000-0000-4000-8000-${String(nextItemId).padStart(12, '0')}`,
    itemId: nextItemId,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 100,
    locationId: LOCATION_ID,
    locationName: 'Jita IV',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

function ship(name: string | null, overrides: Partial<Asset> = {}): Asset {
  return asset({
    typeId: 587,
    typeName: 'Rifter',
    categoryId: SHIP_CATEGORY_ID,
    quantity: 1,
    isSingleton: true,
    itemName: name,
    ...overrides,
  })
}

function moduleIn(parent: Asset, flag: string, typeName: string): Asset {
  return asset({
    typeId: 1000 + typeName.length,
    typeName,
    categoryId: 7,
    quantity: 1,
    locationId: parent.itemId,
    locationType: 'item',
    locationFlag: flag,
  })
}

function contentsByParent(contents: Asset[]): Map<number, Asset[]> {
  const map = new Map<number, Asset[]>()
  for (const content of contents) {
    map.set(content.locationId, [...(map.get(content.locationId) ?? []), content])
  }
  return map
}

function mountGroup(assets: Asset[], contents: Asset[] = [], visibleContents?: Map<number, Asset[]>) {
  const full = contentsByParent(contents)
  return mount(AssetLocationGroup, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: LOCATION_ID,
        locationName: 'Jita IV',
        solarSystemName: 'Jita',
        assets,
        totalQuantity: assets.length,
      },
      expandedContainers: new Set<number>(),
      collapsedLocations: new Set<string>(),
      containerContents: visibleContents ?? full,
      fitContents: full,
    },
  })
}

describe('AssetLocationGroup ship groups', () => {
  it('shows ships that share type, name and fit as one group with a count', () => {
    const wrapper = mountGroup([ship('Alpha'), ship('Alpha'), ship('Alpha'), ship('Bravo')])

    const titles = wrapper.findAll('[data-testid="ship-group-title"]').map(title => title.text())

    expect(titles).toEqual(['Rifter · Alpha ×3', 'Rifter · Bravo ×1'])
  })

  it('shows only the hull and the count for a ship without a custom name', () => {
    const wrapper = mountGroup([ship(null), ship(null)])

    expect(wrapper.get('[data-testid="ship-group-title"]').text()).toBe('Rifter ×2')
  })

  it('keeps unassembled ships and other items as plain rows', () => {
    const wrapper = mountGroup([ship('Alpha'), ship(null, { isSingleton: false, quantity: 5 }), asset({})])

    expect(wrapper.findAll('[data-testid="ship-group"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Tritanium')
    expect(wrapper.text()).toContain('x5')
  })

  it('keeps a ship whose name is still to be resolved as a plain row with its resolve control', () => {
    const wrapper = mountGroup([ship(null, { canResolveItemName: true })])

    expect(wrapper.findAll('[data-testid="ship-group"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid^="resolve-item-name-"]').exists()).toBe(true)
  })

  it('shows the fit of a group only once it is opened, grouped by slot kind', async () => {
    const alpha = ship('Alpha')
    const contents = [moduleIn(alpha, 'LoSlot0', 'Damage Control II'), moduleIn(alpha, 'HiSlot0', '200mm AutoCannon II')]
    const wrapper = mountGroup([alpha], contents)

    expect(wrapper.text()).not.toContain('Damage Control II')

    await wrapper.get('[data-testid="ship-group-toggle"]').trigger('click')

    const fit = wrapper.get('[data-testid="ship-group-fit"]').text()
    expect(fit).toContain('Damage Control II')
    expect(fit).toContain('200mm AutoCannon II')
    expect(fit).toContain('Low slots')
    expect(fit).toContain('High slots')
  })

  it('reads the fit from the unfiltered contents when a search narrows the visible ones', async () => {
    const alpha = ship('Alpha')
    const contents = [moduleIn(alpha, 'LoSlot0', 'Damage Control II')]
    const wrapper = mountGroup([alpha], contents, new Map())

    await wrapper.get('[data-testid="ship-group-toggle"]').trigger('click')

    expect(wrapper.get('[data-testid="ship-group-fit"]').text()).toContain('Damage Control II')
  })

  it('does not list fitted modules as loose content of a ship that stays a plain row', () => {
    const unnamed = ship(null, { canResolveItemName: true })
    const contents = [moduleIn(unnamed, 'HiSlot0', '200mm AutoCannon II')]
    const wrapper = mountGroup([unnamed], contents)

    expect(wrapper.text()).not.toContain('1 items')
    expect(wrapper.findAll('div[role="button"]')).toHaveLength(1)
  })

  it('still treats a ship holding cargo as a container', () => {
    const unnamed = ship(null, { canResolveItemName: true })
    const contents = [asset({ locationId: unnamed.itemId, locationType: 'item', locationFlag: 'Cargo', typeName: 'Nanite Repair Paste' })]
    const wrapper = mountGroup([unnamed], contents)

    expect(wrapper.text()).toContain('1 items')
  })

  it('marks a group whose name says bad fit', () => {
    const wrapper = mountGroup([ship('Rifter BAD FIT'), ship('Alpha')])

    const groups = wrapper.findAll('[data-testid="ship-group"]')

    expect(groups[0].find('[data-testid="ship-group-bad-fit"]').text()).toBe('Fit to review')
    expect(groups[1].find('[data-testid="ship-group-bad-fit"]').exists()).toBe(false)
  })

  it('shows the first 8 groups, then all of them on demand', async () => {
    const ships = Array.from({ length: 10 }, (_, index) => ship(`Fit ${index}`))
    const wrapper = mountGroup(ships)

    expect(wrapper.findAll('[data-testid="ship-group"]')).toHaveLength(COLLAPSED_GROUP_LIMIT)

    await wrapper.get('[data-testid="ship-groups-show-all"]').trigger('click')

    expect(wrapper.find('[data-testid="ship-groups-show-all"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="ship-group"]')).toHaveLength(10)
  })

  it('offers no show-all button for 8 groups or fewer', () => {
    const wrapper = mountGroup(Array.from({ length: COLLAPSED_GROUP_LIMIT }, (_, index) => ship(`Fit ${index}`)))

    expect(wrapper.find('[data-testid="ship-groups-show-all"]').exists()).toBe(false)
  })

  describe('copy fit', () => {
    function stubClipboard(writeText: (text: string) => Promise<void>) {
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    }

    function fittedAlpha() {
      const alpha = ship('Alpha')
      const contents = [moduleIn(alpha, 'LoSlot0', 'Damage Control II'), moduleIn(alpha, 'MedSlot0', 'Experimental 10MN Microwarpdrive I')]
      return mountGroup([alpha], contents)
    }

    it('writes the EFT text of the group to the clipboard', async () => {
      const writeText = vi.fn().mockResolvedValue(undefined)
      stubClipboard(writeText)
      const wrapper = fittedAlpha()

      await wrapper.get('[data-testid="ship-group-copy-eft"]').trigger('click')

      expect(writeText).toHaveBeenCalledWith(EXPECTED_EFT)
      expect(wrapper.get('[data-testid="ship-group-copy-eft"]').attributes('data-state')).toBe('copied')
    })

    it('reports a clipboard that refuses the copy', async () => {
      stubClipboard(vi.fn().mockRejectedValue(new Error('denied')))
      const wrapper = fittedAlpha()

      await wrapper.get('[data-testid="ship-group-copy-eft"]').trigger('click')

      expect(wrapper.get('[data-testid="ship-group-copy-eft"]').attributes('data-state')).toBe('failed')
    })

    it('disables the copy when a module has no known type name', () => {
      const alpha = ship('Alpha')
      const nameless = { ...moduleIn(alpha, 'HiSlot0', 'x'), typeName: null }
      const wrapper = mountGroup([alpha], [nameless])

      expect(wrapper.get('[data-testid="ship-group-copy-eft"]').attributes('disabled')).toBeDefined()
    })
  })
})
