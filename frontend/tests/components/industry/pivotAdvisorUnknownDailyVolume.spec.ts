import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { PivotAnalysisResult } from '@/stores/industry/types'
import { present } from '../../present'

const SOURCE_PRODUCT_TYPE_ID = 11379
const unknownLabels = en.industry.inventionUnknown
const dailyVolumeLabel = en.industry.scanner.pivotAdvisor.dailyVol

function candidate(typeId: number, typeName: string, dailyVolume: number | null, score: number | null) {
  return {
    typeId,
    typeName,
    groupName: 'Cruiser',
    marginPercent: 261.09,
    profitPerUnit: 671,
    dailyVolume,
    coveragePercent: 25,
    missingComponents: [],
    additionalCost: 0,
    estimatedProfit: 2013,
    score,
    unknownReason: dailyVolume == null ? 'missing_daily_volume' : null,
    missingPriceTypeIds: [],
  }
}

const CANDIDATE_WITHOUT_DAILY_VOLUME = candidate(22456, 'Candidate X', null, null)
const CANDIDATE_WITH_DAILY_VOLUME = candidate(22462, 'Candidate W', 9, 6100.2)
const CANDIDATE_WITH_ZERO_DAILY_VOLUME = candidate(22470, 'Candidate Y', 0, 0)

function pivotResult(sourceDailyVolume: number | null): PivotAnalysisResult {
  return {
    typeId: SOURCE_PRODUCT_TYPE_ID,
    sourceProduct: {
      typeId: SOURCE_PRODUCT_TYPE_ID,
      typeName: 'Source Ship',
      groupName: 'Destroyer',
      marginPercent: 823.38,
      sellPrice: 4000,
      dailyVolume: sourceDailyVolume,
      keyComponents: [],
      unknownReason: sourceDailyVolume == null ? 'missing_daily_volume' : null,
      missingPriceTypeIds: [],
    },
    matrix: [],
    candidates: [CANDIDATE_WITHOUT_DAILY_VOLUME, CANDIDATE_WITH_DAILY_VOLUME, CANDIDATE_WITH_ZERO_DAILY_VOLUME],
    matrixProductIds: [SOURCE_PRODUCT_TYPE_ID],
  } as unknown as PivotAnalysisResult
}

function mountPivotAdvisor(sourceDailyVolume: number | null): VueWrapper {
  useScannerStore().pivotResult = pivotResult(sourceDailyVolume)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PivotAdvisorTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

function cardOf(wrapper: VueWrapper, typeName: string) {
  const card = wrapper.findAll('div.grid > div.relative').find(c => c.find('span.font-bold').text() === typeName)
  expect(card, `card of ${typeName}`).toBeDefined()
  return present(card)
}

function dailyVolumeCellOf(wrapper: VueWrapper, typeName: string) {
  const cell = cardOf(wrapper, typeName).findAll('div.bg-slate-800').find(c => c.text().startsWith(dailyVolumeLabel))
  expect(cell, `daily volume cell of ${typeName}`).toBeDefined()
  return present(cell)
}

function displayedCandidateNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('div.grid > div.relative').map(card => card.find('span.font-bold').text())
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('PivotAdvisorTab with a product without daily volume', () => {
  it('shows an unknown candidate daily volume as unknown with its reason, never as zero', () => {
    const wrapper = mountPivotAdvisor(25)

    const cell = dailyVolumeCellOf(wrapper, 'Candidate X')
    expect(cell.text()).toContain(unknownLabels.label)
    expect(cell.text()).not.toMatch(/\b0\b/)
    expect(cell.find('.text-amber-400').exists()).toBe(true)
    expect(cell.attributes('title') ?? cell.find('[title]').attributes('title')).toBe(unknownLabels.missing_daily_volume)
  })

  it('keeps showing a known candidate daily volume, a known zero included', () => {
    const wrapper = mountPivotAdvisor(25)

    expect(dailyVolumeCellOf(wrapper, 'Candidate W').text()).toContain('9')
    expect(dailyVolumeCellOf(wrapper, 'Candidate W').text()).not.toContain(unknownLabels.label)
    expect(dailyVolumeCellOf(wrapper, 'Candidate Y').text()).toContain('0')
    expect(dailyVolumeCellOf(wrapper, 'Candidate Y').text()).not.toContain(unknownLabels.label)
  })

  it('shows an unknown source product daily volume as unknown, never as zero units per day', () => {
    const wrapper = mountPivotAdvisor(null)

    const sourceVolume = present(present(wrapper.findAll('span').find(span => span.text() === en.industry.scanner.pivotAdvisor.dailyVolume)).element.nextElementSibling)
    expect(sourceVolume.textContent).toContain(unknownLabels.label)
    expect(sourceVolume.textContent).not.toContain(en.industry.scanner.pivotAdvisor.unitsDay)
  })

  it('sorts by daily volume with the unknown ones last', async () => {
    const wrapper = mountPivotAdvisor(25)

    const sortSelect = wrapper.findAll('select').find(select => select.find('option[value="volume"]').exists())
    expect(sortSelect, 'sort select').toBeDefined()
    await present(sortSelect).setValue('volume')

    expect(displayedCandidateNames(wrapper)).toEqual(['Candidate W', 'Candidate Y', 'Candidate X'])
  })
})
