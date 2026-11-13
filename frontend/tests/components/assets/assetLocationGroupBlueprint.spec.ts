import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset, AssetBlueprint } from '@/types'

const BLUEPRINT_CATEGORY_ID = 9
const COPY_ITEM_ID = 1870000000001
const ORIGINAL_ITEM_ID = 1870000000002
const UNSYNCED_ITEM_ID = 1870000000003
const TRITANIUM_ITEM_ID = 1870000000004
const CONTAINER_ITEM_ID = 1870000000005

function asset(overrides: Partial<Asset>): Asset {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    itemId: TRITANIUM_ITEM_ID,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 8800,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    blueprint: null,
    cachedAt: '2026-10-22T10:00:00+00:00',
    ...overrides,
  }
}

function blueprintAsset(itemId: number, blueprint: AssetBlueprint): Asset {
  return asset({
    id: `00000000-0000-4000-8000-${String(itemId).slice(-12)}`,
    itemId,
    typeId: 691,
    typeName: 'Rifter Blueprint',
    categoryId: BLUEPRINT_CATEGORY_ID,
    quantity: 1,
    isSingleton: true,
    blueprint,
  })
}

function mountGroup(assets: Asset[], containerContents: Map<number, Asset[]> = new Map()) {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr } })
  return mount(AssetLocationGroup, {
    global: { plugins: [i18n], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: 60003760,
        locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
        solarSystemName: 'Jita',
        assets,
        totalQuantity: assets.reduce((sum, a) => sum + a.quantity, 0),
      },
      expandedContainers: new Set(containerContents.keys()),
      collapsedLocations: new Set<string>(),
      containerContents,
    },
  })
}

function badgeTexts(wrapper: ReturnType<typeof mountGroup>): string[] {
  return wrapper.findAll('[data-testid="asset-blueprint"]').map((badge) => badge.text().replace(/\s+/g, ' '))
}

describe('AssetLocationGroup blueprint rows', () => {
  it('shows a copy with its runs and efficiencies', () => {
    const wrapper = mountGroup([blueprintAsset(COPY_ITEM_ID, { status: 'copy', runs: 10, materialEfficiency: 8, timeEfficiency: 14 })])

    expect(badgeTexts(wrapper)).toEqual(['BPC · 10 runs · ME8/TE14'])
  })

  it('shows an original with its efficiencies and no runs', () => {
    const wrapper = mountGroup([blueprintAsset(ORIGINAL_ITEM_ID, { status: 'original', runs: null, materialEfficiency: 10, timeEfficiency: 20 })])

    expect(badgeTexts(wrapper)).toEqual(['BPO · ME10/TE20'])
  })

  it('shows an explicit not-in-the-sync state for a blueprint missing from the blueprint sync, without efficiency values', () => {
    const wrapper = mountGroup([blueprintAsset(UNSYNCED_ITEM_ID, { status: 'not_synced', runs: null, materialEfficiency: null, timeEfficiency: null })])

    const [text] = badgeTexts(wrapper)
    expect(text).toBe('Absent du sync blueprints')
    expect(text).not.toMatch(/ME|TE|BPC|BPO/)
  })

  it('shows nothing on a row that is not a blueprint', () => {
    const wrapper = mountGroup([asset({})])

    expect(badgeTexts(wrapper)).toEqual([])
  })

  it('shows the blueprint state of a blueprint inside an expanded container', () => {
    const container = asset({ id: '00000000-0000-4000-8000-000000000009', itemId: CONTAINER_ITEM_ID, typeId: 17366, typeName: 'Station Container', categoryId: 2, quantity: 1 })
    const contained = blueprintAsset(COPY_ITEM_ID, { status: 'copy', runs: 3, materialEfficiency: 1, timeEfficiency: 2 })

    const wrapper = mountGroup([container], new Map([[CONTAINER_ITEM_ID, [contained]]]))

    expect(badgeTexts(wrapper)).toEqual(['BPC · 3 runs · ME1/TE2'])
  })
})
