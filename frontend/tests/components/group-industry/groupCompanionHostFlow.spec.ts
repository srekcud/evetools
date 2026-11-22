import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import GroupCompanionHost from '@/components/group-industry/GroupCompanionHost.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useAuthStore } from '@/stores/auth'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import {
  characterLocation,
  COMPANION_PROJECT_ID,
  companionProject,
  MAIN_EVE_CHARACTER_ID,
  projectTransportLegs,
  stationTransfer,
  transportLeg,
} from './companionFixtures'

const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: (() => router) as never,
}))

enableAutoUnmount(afterEach)

const POSITION_REFRESH_MS = 60_000

function pictureInPictureWindowStub(visibilityState: 'visible' | 'hidden'): Window {
  const companionDocument = document.implementation.createHTMLDocument('companion')
  Object.defineProperty(companionDocument, 'visibilityState', { get: () => visibilityState, configurable: true })
  return {
    document: companionDocument,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    resizeTo: vi.fn(),
    close: vi.fn(),
    focus: vi.fn(),
  } as unknown as Window
}

async function mountOpenCompanion(companionWindow: 'visible' | 'hidden' = 'visible') {
  Object.defineProperty(window, 'documentPictureInPicture', {
    value: { requestWindow: vi.fn(async () => pictureInPictureWindowStub(companionWindow)) },
    configurable: true,
  })
  setActivePinia(createPinia())
  const companion = useGroupCompanionStore()
  const refreshPlace = vi.spyOn(companion, 'refreshPlace').mockResolvedValue(undefined)
  const arriveTransfer = vi.spyOn(companion, 'arriveTransfer').mockResolvedValue(undefined)
  const supply = vi.spyOn(companion, 'supply').mockResolvedValue(undefined)
  const declareLeg = vi.spyOn(companion, 'declareLeg').mockResolvedValue(undefined)
  companion.projectId = COMPANION_PROJECT_ID
  companion.project = companionProject()
  const wrapper = mount(GroupCompanionHost, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { GroupCompanion: true },
    },
  })
  await flushPromises()
  return { wrapper, companion, refreshPlace, arriveTransfer, supply, declareLeg }
}

beforeEach(() => {
  router.push.mockReset()
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
})

afterEach(() => {
  vi.useRealTimers()
  Reflect.deleteProperty(window, 'documentPictureInPicture')
})

describe('GroupCompanionHost place and flow', () => {
  it('opens the members tab of the project from the join request alert', async () => {
    const { wrapper } = await mountOpenCompanion()

    wrapper.getComponent(GroupCompanion).vm.$emit('open-members')
    await flushPromises()

    expect(router.push.mock.calls).toEqual([[{
      name: 'industry-project',
      params: { id: COMPANION_PROJECT_ID },
      query: { tab: 'members' },
    }]])
  })

  it('feeds the tasks with the positions, the transfers and the eve ids of my characters', async () => {
    const { wrapper, companion } = await mountOpenCompanion()
    useAuthStore().user = { characters: [{ eveCharacterId: MAIN_EVE_CHARACTER_ID, name: 'Alpha' }] } as never
    companion.positions = [characterLocation({ solarSystemName: 'Jita' })]
    companion.transfers = [stationTransfer({ id: 'transfer-mine' }), stationTransfer({ id: 'transfer-other', carrier: { kind: 'pilot', characterId: 2_112_000_777, contractId: null } })]
    await flushPromises()
    const tasks = wrapper.getComponent(GroupCompanion).props('tasks')

    expect({ hereSystems: tasks.hereSystems, carries: tasks.carries.map(carry => carry.id) }).toEqual({ hereSystems: ['Jita'], carries: ['transfer-mine'] })
  })

  it('wires the arrival and supply events of the companion to the store', async () => {
    const { wrapper, arriveTransfer, supply } = await mountOpenCompanion()

    wrapper.getComponent(GroupCompanion).vm.$emit('arrive', 'transfer-mine')
    wrapper.getComponent(GroupCompanion).vm.$emit('supply', 'bom-morphite')
    await flushPromises()

    expect({ arriveTransfer: arriveTransfer.mock.calls, supply: supply.mock.calls }).toEqual({
      arriveTransfer: [['transfer-mine']],
      supply: [['bom-morphite']],
    })
  })

  it('feeds the tasks with the transport legs and wires the declare leg event of the companion to the store', async () => {
    const { wrapper, companion, declareLeg } = await mountOpenCompanion()
    useAuthStore().user = { characters: [{ eveCharacterId: MAIN_EVE_CHARACTER_ID, name: 'Alpha' }] } as never
    companion.transportLegs = projectTransportLegs({ legs: [transportLeg({ key: 'leg-jita-perimeter' })] })
    await flushPromises()
    const tasks = wrapper.getComponent(GroupCompanion).props('tasks')

    wrapper.getComponent(GroupCompanion).vm.$emit('declare-leg', 'leg-jita-perimeter')
    await flushPromises()

    expect({ legs: tasks.legs.map(leg => leg.key), readyLegCount: tasks.readyLegCount, declareLeg: declareLeg.mock.calls }).toEqual({
      legs: ['leg-jita-perimeter'],
      readyLegCount: 1,
      declareLeg: [['leg-jita-perimeter']],
    })
  })

  it('reads the position and the place actions every minute while the window is visible', async () => {
    const { refreshPlace } = await mountOpenCompanion('visible')

    vi.advanceTimersByTime(POSITION_REFRESH_MS * 2)

    expect(refreshPlace).toHaveBeenCalledTimes(2)
  })

  it('does not read the position while the companion window is hidden', async () => {
    const { refreshPlace } = await mountOpenCompanion('hidden')

    vi.advanceTimersByTime(POSITION_REFRESH_MS * 2)

    expect(refreshPlace).not.toHaveBeenCalled()
  })

  it('stops reading the position once the companion is closed', async () => {
    const { companion, refreshPlace } = await mountOpenCompanion('visible')

    companion.close()
    await flushPromises()
    vi.advanceTimersByTime(POSITION_REFRESH_MS * 2)

    expect(refreshPlace).not.toHaveBeenCalled()
  })
})
