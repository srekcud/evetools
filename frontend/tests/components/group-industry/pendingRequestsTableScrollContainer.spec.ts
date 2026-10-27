import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PendingRequestsTable from '@/components/group-industry/PendingRequestsTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupMember } from '@/stores/group-industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

const PENDING_PILOT: GroupMember = {
  id: 'member-pending',
  characterName: 'Carol Hauler',
  characterId: 2_119_000_001,
  corporationId: 98_000_001,
  corporationName: 'Hauling Inc',
  role: 'member',
  status: 'pending',
  totalContributionValue: 0,
  contributionCount: 0,
  joinedAt: '2026-10-20T08:00:00+00:00',
}

const ACCEPTED_PILOT: GroupMember = {
  id: 'member-accepted',
  characterName: 'Dave Miner',
  characterId: 2_119_000_002,
  corporationId: 98_000_002,
  corporationName: 'Mining Corp',
  role: 'member',
  status: 'accepted',
  totalContributionValue: 2_500_000,
  contributionCount: 3,
  joinedAt: '2026-10-18T12:00:00+00:00',
}

function mountPendingRequests() {
  setActivePinia(createPinia())
  useGroupDistributionStore().members = [PENDING_PILOT, ACCEPTED_PILOT]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PendingRequestsTable, {
    global: { plugins: [i18n] },
    props: { projectId: 'project-226', isAdmin: true },
  })
}

describe('PendingRequestsTable on a narrow screen', () => {
  it('puts the pending requests table in a horizontally scrollable container', () => {
    const wrapper = mountPendingRequests()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})
