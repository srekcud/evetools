import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupMember, GroupMemberRole, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1186'
const LINE_RENTAL_LABEL = 'Line Rental'
const PENDING_NOTE = en.groupIndustry.contribute.pendingNote

function member(role: GroupMemberRole, status: GroupMember['status'] = 'accepted'): GroupMember {
  return {
    id: `member-${role}-${status}`,
    characterName: 'Pilot',
    characterId: 1,
    corporationId: null,
    corporationName: null,
    role,
    status,
    totalContributionValue: null,
    contributionCount: 0,
    joinedAt: '2026-11-01T00:00:00+00:00',
  }
}

async function openModal(project: { solo: boolean; myRole: GroupMemberRole | null }, members: GroupMember[]): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValue({} as never)
  useGroupProjectStore().currentProject = { id: PROJECT_ID, name: 'Fuel batch', status: 'in_progress', items: [], ...project } as unknown as GroupProject
  useGroupDistributionStore().members = members
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function offersLineRental(wrapper: VueWrapper): boolean {
  return wrapper.findAll('button').some(candidate => candidate.text() === LINE_RENTAL_LABEL)
}

describe('ContributeModal on a solo project', () => {
  it('does not offer Line Rental', async () => {
    const wrapper = await openModal({ solo: true, myRole: 'owner' }, [member('owner')])

    expect(offersLineRental(wrapper)).toBe(false)
  })

  it('still offers the material, job install and BPC types', async () => {
    const wrapper = await openModal({ solo: true, myRole: 'owner' }, [member('owner')])

    const labels = wrapper.findAll('button').map(candidate => candidate.text())
    expect(labels).toEqual(expect.arrayContaining(['Material', 'Job Install', 'BPC']))
  })

  it('does not announce a review', async () => {
    const wrapper = await openModal({ solo: true, myRole: 'owner' }, [member('owner')])

    expect(wrapper.text()).not.toContain(PENDING_NOTE)
  })
})

describe('ContributeModal on a group project', () => {
  it('offers Line Rental', async () => {
    const wrapper = await openModal({ solo: false, myRole: 'member' }, [member('owner'), member('member')])

    expect(offersLineRental(wrapper)).toBe(true)
  })

  it('announces a review to a plain member', async () => {
    const wrapper = await openModal({ solo: false, myRole: 'member' }, [member('owner'), member('member')])

    expect(wrapper.text()).toContain(PENDING_NOTE)
  })

  it('announces a review to an admin, whose own contribution another reviewer approves', async () => {
    const wrapper = await openModal({ solo: false, myRole: 'admin' }, [member('owner'), member('admin')])

    expect(wrapper.text()).toContain(PENDING_NOTE)
  })

  it('announces a review to an owner when another admin can review', async () => {
    const wrapper = await openModal({ solo: false, myRole: 'owner' }, [member('owner'), member('admin')])

    expect(wrapper.text()).toContain(PENDING_NOTE)
  })

  it('does not announce a review to an owner who is the only reviewer', async () => {
    const wrapper = await openModal({ solo: false, myRole: 'owner' }, [member('owner'), member('member'), member('admin', 'pending')])

    expect(wrapper.text()).not.toContain(PENDING_NOTE)
  })
})

describe('project wording', () => {
  const locales = { en, fr }

  it.each(Object.entries(locales))('names a new project without group wording in %s', (_locale, messages) => {
    const { title } = messages.groupIndustry.modals.newProject

    expect(title).not.toMatch(/group/i)
  })

  it.each(Object.entries(locales))('names an empty project list without group wording in %s', (_locale, messages) => {
    const { title, description } = messages.groupIndustry.empty

    expect(title).not.toMatch(/group/i)
    expect(description).not.toMatch(/corporation|collabor/i)
  })
})
