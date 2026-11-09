import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PendingRequestsTable from '@/components/group-industry/PendingRequestsTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupMember } from '@/stores/group-industry/types'

function pendingPilot(overrides: Partial<GroupMember>): GroupMember {
  return {
    id: 'member-217',
    characterName: 'Ilse Varga',
    characterId: 2_119_000_217,
    corporationId: 98_000_217,
    corporationName: 'Varga Haulage',
    role: 'member',
    status: 'pending',
    totalContributionValue: 0,
    contributionCount: 0,
    joinedAt: '2026-10-21T09:30:00+00:00',
    ...overrides,
  }
}

function corporationCellText(member: GroupMember): string {
  setActivePinia(createPinia())
  useGroupDistributionStore().members = [member]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(PendingRequestsTable, {
    global: { plugins: [i18n] },
    props: { projectId: 'project-217', isAdmin: true },
  })

  return wrapper.findAll('tbody tr td')[1].text()
}

enableAutoUnmount(afterEach)

describe('PendingRequestsTable corporation', () => {
  it('shows the name of the corporation of the requester', () => {
    expect(corporationCellText(pendingPilot({}))).toBe('Varga Haulage')
  })

  it('falls back to the corporation number when only the id is known', () => {
    expect(corporationCellText(pendingPilot({ corporationName: null }))).toBe('Corp #98000217')
  })

  it('shows a dash when the corporation is unknown', () => {
    expect(corporationCellText(pendingPilot({ corporationId: null, corporationName: null }))).toBe('--')
  })
})
