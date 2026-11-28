import { enableAutoUnmount, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetupStations from '@/components/group-industry/ProjectSetupStations.vue'
import type { WorkStationRow } from '@/stores/group-industry/types'
import { COMPONENTS_RECOMMENDED_ID, componentsType, projectWorkStations, stationRow } from '../../projectSetupFixtures'

const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/
const RAW_REASON_CODE = /[a-z]+_[a-z_]+/
const SETTINGS_LOCATION = { name: 'settings', params: { section: 'industry' } }

enableAutoUnmount(afterEach)

function mountWith(row: WorkStationRow, locale: 'fr' | 'en' = 'en'): VueWrapper {
  const type = componentsType({ rows: [row] })
  return mount(ProjectSetupStations, {
    props: {
      workStations: projectWorkStations([type]),
      picks: {},
      acceptsOutsideFavoriteSystem: false,
      minimumOutsideSaving: 5,
    },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { RouterLink: RouterLinkStub } },
  })
}

function cardTestId(prefix: string): string {
  return `${prefix}-components-${COMPONENTS_RECOMMENDED_ID}`
}

function find(wrapper: VueWrapper, prefix: string) {
  return wrapper.find(`[data-testid="${cardTestId(prefix)}"]`)
}

describe('ProjectSetupStations rigs and missing data (#1474)', () => {
  it('lists the rigs taken into account with their categories and bonus', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { rigs: [{ categories: ['drone', 'fighter'], bonusPercent: 6 }], productCategory: 'drone' }))

    const rigs = find(wrapper, 'station-rigs')
    expect(rigs.exists()).toBe(true)
    expect(rigs.text()).toContain('drones')
    expect(rigs.text()).toContain('fighters')
    expect(rigs.text()).toContain('6 %')
    expect(rigs.text()).not.toMatch(RAW_TRANSLATION_KEY)
  })

  it('says so when the structure has no material rig', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { rigs: [] }))

    expect(find(wrapper, 'station-rigs').text()).toContain('No material rig')
  })

  it('shows an unknown bonus instead of a zero when the bonus is not known', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { rigs: [{ categories: ['drone'], bonusPercent: null }] }))

    expect(find(wrapper, 'station-rigs').text()).toContain('unknown')
    expect(find(wrapper, 'station-rigs').text()).not.toContain('0 %')
  })

  it('warns when no rig targets the product and links to the structure settings', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { productCategory: 'drone', noRigForProduct: true }))

    const warning = find(wrapper, 'station-no-rig')
    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain('No rig for drones')
    expect(warning.findComponent(RouterLinkStub).props('to')).toEqual(SETTINGS_LOCATION)
  })

  it('shows no warning when a rig targets the product', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { productCategory: 'drone', noRigForProduct: false, rigs: [{ categories: ['drone'], bonusPercent: 6 }] }))

    expect(find(wrapper, 'station-no-rig').exists()).toBe(false)
  })

  it('warns in French too', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { productCategory: 'drone', noRigForProduct: true }), 'fr')

    expect(find(wrapper, 'station-no-rig').text()).toContain('Aucun rig pour drones')
    expect(find(wrapper, 'station-no-rig').text()).not.toMatch(RAW_TRANSLATION_KEY)
  })

  it('renders the unrecognised rig of the row missing data', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { missingData: [{ reason: 'unrecognised_rig', typeId: null }] }))

    const missing = find(wrapper, 'station-missing')
    expect(missing.exists()).toBe(true)
    expect(missing.text()).toContain('A rig of this structure is not recognised')
  })

  it('never prints the raw reason code of an unlabelled missing data', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { missingData: [{ reason: 'some_future_reason', typeId: 34 }] }))

    const missing = find(wrapper, 'station-missing')
    expect(missing.text()).toContain('Some data is unknown')
    expect(missing.text()).not.toMatch(RAW_REASON_CODE)
  })

  it('shows each reason once', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID, { missingData: [{ reason: 'market_price', typeId: 34 }, { reason: 'market_price', typeId: 35 }] }))

    expect(find(wrapper, 'station-missing').findAll('li')).toHaveLength(1)
  })

  it('shows no missing data block when nothing is missing', () => {
    const wrapper = mountWith(stationRow(COMPONENTS_RECOMMENDED_ID))

    expect(find(wrapper, 'station-missing').exists()).toBe(false)
  })
})
