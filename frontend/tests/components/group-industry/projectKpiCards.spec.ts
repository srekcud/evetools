import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectKpiCards from '@/components/group-industry/ProjectKpiCards.vue'
import type { GroupProject } from '@/stores/group-industry/types'

function groupProject(overrides: Partial<GroupProject>): GroupProject {
  return {
    id: 'project',
    name: null,
    status: 'in_progress',
    shortLinkCode: 'link',
    containerName: null,
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: 98000001,
    membersCount: 2,
    items: [],
    totalBomValue: 0,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    brokerFeePercent: 3.6,
    salesTaxPercent: 3.6,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-20T18:30:00+00:00',
    myRole: 'member',
    myStatus: 'accepted',
    ...overrides,
  }
}

function mountKpiCards(projects: GroupProject[], locale: 'en' | 'fr' = 'en'): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(ProjectKpiCards, { props: { projects }, global: { plugins: [i18n] } })
}

function kpiCard(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const card = wrapper.findAll('div.card-enter').find(candidate => candidate.find('p').text() === label)
  if (card === undefined) throw new Error(`KPI card ${label} not found`)
  return card
}

function kpiValue(wrapper: VueWrapper, label: string): string {
  return kpiCard(wrapper, label).find('p.text-3xl').text()
}

function normalizedText(element: DOMWrapper<Element>): string {
  return element.text().replace(/[  ]/g, ' ')
}

describe('ProjectKpiCards total contributions', () => {
  it('adds the approved contribution values of the listed projects', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', approvedContributionValue: 1_250_000, totalBomValue: 5_000_000, fulfillmentPercent: 40 }),
      groupProject({ id: 'jackdaw', approvedContributionValue: 0, totalBomValue: 2_000_000, fulfillmentPercent: 10 }),
    ])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.totalContributions)).toBe('1.3 M')
  })

  it('ignores the BOM value and progress of the projects', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', approvedContributionValue: 2_340_000, totalBomValue: 9_000_000, fulfillmentPercent: 70 }),
      groupProject({ id: 'ishtar', approvedContributionValue: 560_000, totalBomValue: null, fulfillmentPercent: 16.67 }),
    ])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.totalContributions)).toBe('2.9 M')
  })

  it('shows 0 when no listed project has an approved contribution', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', approvedContributionValue: 0, totalBomValue: 5_000_000, fulfillmentPercent: 40 }),
    ])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.totalContributions)).toBe('0')
  })
})

describe('ProjectKpiCards pipeline value', () => {
  it('adds the known BOM values and counts the project with an unknown one as excluded', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', totalBomValue: 5_000_000 }),
      groupProject({ id: 'jackdaw', totalBomValue: null }),
      groupProject({ id: 'ishtar', totalBomValue: 2_000_000 }),
    ])

    const pipelineCard = kpiCard(wrapper, en.groupIndustry.kpis.pipelineValue)
    expect(kpiValue(wrapper, en.groupIndustry.kpis.pipelineValue)).toBe('7.0 M')
    expect(normalizedText(pipelineCard)).toContain('1 project with unknown value excluded')
  })

  it('counts every project with an unknown BOM value as excluded', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', totalBomValue: null }),
      groupProject({ id: 'jackdaw', totalBomValue: 3_000_000 }),
      groupProject({ id: 'ishtar', totalBomValue: null }),
    ])

    const pipelineCard = kpiCard(wrapper, en.groupIndustry.kpis.pipelineValue)
    expect(kpiValue(wrapper, en.groupIndustry.kpis.pipelineValue)).toBe('3.0 M')
    expect(normalizedText(pipelineCard)).toContain('2 projects with unknown value excluded')
  })

  it('shows 0 and the exclusion when no listed project has a known BOM value', () => {
    const wrapper = mountKpiCards([groupProject({ id: 'jackdaw', totalBomValue: null })])

    const pipelineCard = kpiCard(wrapper, en.groupIndustry.kpis.pipelineValue)
    expect(kpiValue(wrapper, en.groupIndustry.kpis.pipelineValue)).toBe('0')
    expect(normalizedText(pipelineCard)).toContain('1 project with unknown value excluded')
  })

  it('mentions no exclusion when every BOM value is known', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', totalBomValue: 5_000_000 }),
      groupProject({ id: 'ishtar', totalBomValue: 2_000_000 }),
    ])

    const pipelineCard = kpiCard(wrapper, en.groupIndustry.kpis.pipelineValue)
    expect(kpiValue(wrapper, en.groupIndustry.kpis.pipelineValue)).toBe('7.0 M')
    expect(pipelineCard.text()).not.toContain('excluded')
  })

  it('counts the excluded project in French', () => {
    const wrapper = mountKpiCards([
      groupProject({ id: 'sabre', totalBomValue: 5_000_000 }),
      groupProject({ id: 'jackdaw', totalBomValue: null }),
      groupProject({ id: 'ishtar', totalBomValue: null }),
    ], 'fr')

    const pipelineCard = kpiCard(wrapper, fr.groupIndustry.kpis.pipelineValue)
    expect(normalizedText(pipelineCard)).toContain('2 projets à la valeur inconnue exclus')
  })

  it('describes the pipeline value as an estimated material cost', () => {
    const wrapper = mountKpiCards([groupProject({ id: 'sabre', totalBomValue: 5_000_000 })])

    const pipelineCard = kpiCard(wrapper, en.groupIndustry.kpis.pipelineValue)
    expect(pipelineCard.text()).toContain('estimated material cost')
    expect(pipelineCard.text()).not.toContain('sell value')
  })

  it('describes the pipeline value as an estimated material cost in French', () => {
    const wrapper = mountKpiCards([groupProject({ id: 'sabre', totalBomValue: 5_000_000 })], 'fr')

    const pipelineCard = kpiCard(wrapper, fr.groupIndustry.kpis.pipelineValue)
    expect(pipelineCard.text()).toContain('coût matériaux estimé')
    expect(pipelineCard.text()).not.toContain('vente')
  })
})
