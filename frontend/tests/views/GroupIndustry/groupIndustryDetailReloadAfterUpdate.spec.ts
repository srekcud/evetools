import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/i18n/locales/en.json'
import ProjectBlacklistEditor from '@/components/group-industry/ProjectBlacklistEditor.vue'
import ProjectBuyOrBuild from '@/components/group-industry/ProjectBuyOrBuild.vue'
import { apiRequest } from '@/services/api'
import { callsTo, cockpitOf, mountCockpitDetail, nextAction, nyxProject, PROJECT_PATH, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SOURCING_PATH = `${PROJECT_PATH}/material-sourcing`
const COURIER_PATH = `${PROJECT_PATH}/courier`
const DELIVERIES_PATH = `${PROJECT_PATH}/purchases-to-deliver`
const VERIFICATION_PATH = `${PROJECT_PATH}/container-verification`
const BOM_PATH = `${PROJECT_PATH}/bom`

async function mountOwnerOnSourcing(containerNames: string[] = []): Promise<VueWrapper> {
  route.query = { tab: 'bom' }
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { containerNames }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials' })),
  })
}

function sourcingBlockRequests(): number[] {
  return [SOURCING_PATH, COURIER_PATH, DELIVERIES_PATH].map(callsTo)
}

async function saveEditedContainers(wrapper: VueWrapper, containers: string): Promise<void> {
  await wrapper.get(`button[aria-label="${en.groupIndustry.detail.edit}"]`).trigger('click')
  await flushPromises()
  await wrapper.get('textarea').setValue(containers)
  const saveButton = wrapper.findAll('button').find(button => button.text() === en.common.actions.save)
  if (saveButton === undefined) throw new Error('no save button')
  await saveButton.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('sourcing blocks reloaded after a project update', () => {
  it('reloads where to buy, the courier and the purchases to deliver when a buy or build switch completes', async () => {
    const wrapper = await mountOwnerOnSourcing()
    const before = sourcingBlockRequests()

    wrapper.findComponent(ProjectBuyOrBuild).vm.$emit('switched')
    await flushPromises()

    expect(sourcingBlockRequests()).toEqual(before.map(count => count + 1))
  })

  it('reloads the three blocks when the blacklist is saved', async () => {
    const wrapper = await mountOwnerOnSourcing()
    const before = sourcingBlockRequests()

    wrapper.findComponent(ProjectBlacklistEditor).vm.$emit('saved')
    await flushPromises()

    expect(sourcingBlockRequests()).toEqual(before.map(count => count + 1))
  })

  it('reloads the container check, the plan and the three blocks when the containers are edited', async () => {
    const wrapper = await mountOwnerOnSourcing(['ISHTAR-206'])
    const before = [callsTo(VERIFICATION_PATH), callsTo(BOM_PATH), ...sourcingBlockRequests()]

    await saveEditedContainers(wrapper, 'ISHTAR-206\nNYX-731')

    expect([callsTo(VERIFICATION_PATH), callsTo(BOM_PATH), ...sourcingBlockRequests()]).toEqual(before.map(count => count + 1))
  })

  it('reloads the container check when the last container is removed so the stale supply is dropped', async () => {
    const wrapper = await mountOwnerOnSourcing(['ISHTAR-206'])
    const before = callsTo(VERIFICATION_PATH)

    await saveEditedContainers(wrapper, '')

    expect(callsTo(VERIFICATION_PATH)).toBe(before + 1)
  })

  it('does not reload the container check or the blocks when the edit modal is cancelled', async () => {
    const wrapper = await mountOwnerOnSourcing(['ISHTAR-206'])
    const before = [callsTo(VERIFICATION_PATH), ...sourcingBlockRequests()]

    await wrapper.get(`button[aria-label="${en.groupIndustry.detail.edit}"]`).trigger('click')
    await flushPromises()

    expect([callsTo(VERIFICATION_PATH), ...sourcingBlockRequests()]).toEqual(before)
  })
})
