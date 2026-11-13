import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AppraisalResults from '@/components/appraisal/AppraisalResults.vue'
import type { AppraisalItem, AppraisalTotals } from '@/components/appraisal/AppraisalResults.vue'
import { present } from '../../present'

const UNKNOWN_TYPE_ID = 34
const UNKNOWN_TYPE_NAME = 'Mystery Ore'

function mountOptions() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return { global: { plugins: [i18n], stubs: { OpenInGameButton: true } } }
}

function appraisalItem(overrides: Partial<AppraisalItem>): AppraisalItem {
  return {
    typeId: 35,
    typeName: 'Pyerite',
    quantity: 10,
    volume: 2,
    totalVolume: 20,
    sellPrice: 1000,
    sellTotal: 10000,
    buyPrice: 900,
    buyTotal: 9000,
    splitPrice: 950,
    splitTotal: 9500,
    sellPriceWeighted: null,
    sellTotalWeighted: null,
    buyPriceWeighted: null,
    buyTotalWeighted: null,
    splitPriceWeighted: null,
    splitTotalWeighted: null,
    sellCoverage: null,
    buyCoverage: null,
    ...overrides,
  }
}

const APPRAISAL_TOTALS: AppraisalTotals = {
  sellTotal: 10000,
  buyTotal: 9000,
  splitTotal: 9500,
  volume: 20,
  sellTotalWeighted: null,
  buyTotalWeighted: null,
  splitTotalWeighted: null,
}

describe('AppraisalResults with a type of unknown volume', () => {
  function mountResults(items: AppraisalItem[], totals: AppraisalTotals, missingVolumeTypeIds: number[]) {
    return mount(AppraisalResults, {
      ...mountOptions(),
      props: { items, totals, notFound: [], missingVolumeTypeIds },
    })
  }

  it('shows the item volume and the total volume as unknown and names the type in a warning', () => {
    const wrapper = mountResults(
      [appraisalItem({ typeId: UNKNOWN_TYPE_ID, typeName: UNKNOWN_TYPE_NAME, volume: null, totalVolume: null }), appraisalItem({})],
      { ...APPRAISAL_TOTALS, volume: null },
      [UNKNOWN_TYPE_ID],
    )
    expect(wrapper.text()).toContain(`Unknown volume in the SDE for: ${UNKNOWN_TYPE_NAME}. Their volume and the total volume are unknown.`)
    expect(wrapper.findAll('tbody tr')[0]?.findAll('td')[2]?.text()).toBe('Unknown')
    expect(wrapper.find('tfoot').findAll('td')[2]?.text()).toBe('Unknown')
    expect(wrapper.text()).not.toMatch(/(^|[^\d,.])0 m³/)
  })

  it('renders a shared list saved before the fix as before, without warning', () => {
    const wrapper = mountResults([appraisalItem({})], APPRAISAL_TOTALS, [])
    expect(wrapper.text()).not.toContain('Unknown')
    expect(wrapper.find('tfoot').findAll('td')[2]?.text()).toBe('20 m³')
  })
})

describe('AppraisalResults table copy with a type of unknown volume', () => {
  it('copies the item volume and the total volume as unknown, never as 0', async () => {
    const writeText = vi.fn()
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const wrapper = mount(AppraisalResults, {
      ...mountOptions(),
      props: {
        items: [appraisalItem({ typeId: UNKNOWN_TYPE_ID, typeName: UNKNOWN_TYPE_NAME, volume: null, totalVolume: null }), appraisalItem({})],
        totals: { ...APPRAISAL_TOTALS, volume: null },
        notFound: [],
        missingVolumeTypeIds: [UNKNOWN_TYPE_ID],
      },
    })

    await present(wrapper.findAll('button').find(button => button.text() === 'Copy')).trigger('click')

    const rows = (present(writeText.mock.calls[0])[0] as string).split('\n').map(row => row.split('\t'))
    expect(present(rows[1])[2]).toBe('Unknown')
    expect(present(rows[2])[2]).toBe('20 m3')
    expect(present(rows[3])[2]).toBe('Unknown')
  })
})
