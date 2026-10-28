import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectCard from '@/components/group-industry/ProjectCard.vue'
import type { GroupProject } from '@/stores/group-industry/types'

const JACKDAW_PROJECT: GroupProject = {
  id: 'project-jackdaw',
  name: 'Jackdaw batch',
  status: 'in_progress',
  shortLinkCode: 'jackdaw1',
  containerName: null,
  ownerCharacterName: 'Brann Holt',
  ownerCorporationId: 98000001,
  membersCount: 4,
  items: [{ typeId: 34828, typeName: 'Jackdaw', meLevel: 10, teLevel: 20, runs: 20 }],
  totalBomValue: null,
  fulfillmentPercent: 16.67,
  approvedContributionValue: 0,
  brokerFeePercent: 3.6,
  salesTaxPercent: 3.6,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-20T18:30:00+00:00',
  myRole: 'admin',
  myStatus: 'accepted',
}

function mountProjectCard(project: GroupProject): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(ProjectCard, { props: { project }, global: { plugins: [i18n] } })
}

describe('ProjectCard BOM value', () => {
  it('shows an unknown BOM value as unknown', () => {
    const wrapper = mountProjectCard(JACKDAW_PROJECT)

    const unknownValue = wrapper.find(`[title="${en.common.unknownValue}"]`)
    expect(unknownValue.exists()).toBe(true)
    expect(unknownValue.text()).toBe('—')
    expect(wrapper.text()).not.toContain('---')
  })

  it('shows a known BOM value', () => {
    const wrapper = mountProjectCard({ ...JACKDAW_PROJECT, totalBomValue: 6_000_000, fulfillmentPercent: 70 })

    expect(wrapper.text()).toContain('6.0 M')
    expect(wrapper.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(false)
  })
})
