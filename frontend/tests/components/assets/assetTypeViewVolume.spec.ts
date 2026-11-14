import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AssetTypeView from '@/components/assets/AssetTypeView.vue'
import type { TypeGroup } from '@/utils/assetsByType'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'

const TRITANIUM = 34
const PYERITE = 35
const EXCLUDED_TYPE = 17368
const UNLISTED_TYPE = 999001

const catalog: PackagedVolumeCatalog = {
  volumes: new Map([[TRITANIUM, 0.01], [PYERITE, 0.5]]),
  excludedTypeIds: new Set([EXCLUDED_TYPE]),
}

function group(typeId: number, typeName: string, totalQuantity: number): TypeGroup {
  return { typeId, typeName, categoryId: 4, totalQuantity, locations: [{ locationName: 'Jita IV', solarSystemName: 'Jita', quantity: totalQuantity }] }
}

const groups = [
  group(TRITANIUM, 'Tritanium', 1000),
  group(PYERITE, 'Pyerite', 100),
  group(UNLISTED_TYPE, 'Mystery Crate', 4),
  group(EXCLUDED_TYPE, 'Station Warehouse Container', 1),
]

function mountView(packagedVolumes: PackagedVolumeCatalog | null) {
  return mount(AssetTypeView, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { OpenInGameButton: true } },
    props: { groups, packagedVolumes },
  })
}

function rowNames(wrapper: ReturnType<typeof mountView>): string[] {
  return wrapper.findAll('[data-testid="asset-type-row"]').map(row => row.find('span.truncate').text())
}

function sortButton(wrapper: ReturnType<typeof mountView>, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().startsWith(label))
  if (button === undefined) {
    throw new Error(`No sort button ${label}`)
  }
  return button
}

describe('AssetTypeView packaged volume', () => {
  it('shows the volume of each type row', () => {
    const wrapper = mountView(catalog)
    const rows = wrapper.findAll('[data-testid="asset-type-row"]')

    expect(rows[0].get('[data-testid="type-volume"]').text()).toBe('10 m³')
    expect(rows[1].get('[data-testid="type-volume"]').text()).toBe('50 m³')
  })

  it('marks a type absent from the catalogue', () => {
    const wrapper = mountView(catalog)
    const mystery = wrapper.findAll('[data-testid="asset-type-row"]').find(row => row.text().includes('Mystery Crate'))

    expect(mystery?.get('[data-testid="volume-unknown"]').exists()).toBe(true)
  })

  it('shows no volume for an excluded type', () => {
    const wrapper = mountView(catalog)
    const warehouse = wrapper.findAll('[data-testid="asset-type-row"]').find(row => row.text().includes('Station Warehouse Container'))

    expect(warehouse?.find('[data-testid="type-volume"]').exists()).toBe(false)
  })

  it('sorts by volume, descending first, then ascending on a second click', async () => {
    const wrapper = mountView(catalog)

    await sortButton(wrapper, 'Volume').trigger('click')
    expect(rowNames(wrapper)).toEqual(['Pyerite', 'Tritanium', 'Mystery Crate', 'Station Warehouse Container'])

    await sortButton(wrapper, 'Volume').trigger('click')
    expect(rowNames(wrapper)).toEqual(['Tritanium', 'Pyerite', 'Mystery Crate', 'Station Warehouse Container'])
  })

  it('offers neither volume nor volume sort without a catalogue', () => {
    const wrapper = mountView(null)

    expect(wrapper.find('[data-testid="type-volume"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text().startsWith('Volume'))).toBe(false)
  })

  it('keeps the quantity sort working beside the volume sort', async () => {
    const wrapper = mountView(catalog)

    expect(rowNames(wrapper)).toEqual(['Tritanium', 'Pyerite', 'Mystery Crate', 'Station Warehouse Container'])

    await sortButton(wrapper, 'Quantity').trigger('click')

    expect(rowNames(wrapper)).toEqual(['Station Warehouse Container', 'Mystery Crate', 'Pyerite', 'Tritanium'])
  })
})
