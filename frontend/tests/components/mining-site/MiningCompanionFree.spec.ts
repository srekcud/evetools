import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import MiningCompanionFree from '@/components/mining-site/MiningCompanionFree.vue'
import type { CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { UNKNOWN_DASH } from '@/composables/useMiningSiteFormat'
import { found, i18nFor } from '../../views/MiningSite/miningSiteFixtures'
import { CHUNK_B, CHUNK_READY, CHUNK_UNNAMED, FREE_NOW, UNNAMED_MOON_ID } from './companionFreeFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

interface FreeProps {
  chunk: CorporationMiningExtraction | null
  loading: boolean
  failure: string | null
  estimating: boolean
  estimateFailure: string | null
}

const IDLE: FreeProps = { chunk: null, loading: false, failure: null, estimating: false, estimateFailure: null }
const LOAD_FAILURE = 'Unexpected corporation moon chunks payload'

function mountFree(props: Partial<FreeProps>) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningCompanionFree, {
    props: { ...IDLE, now: FREE_NOW, ...props },
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
  })
  return { wrapper, i18n, t: i18n.global.t }
}

function textOf(wrapper: ReturnType<typeof mountFree>['wrapper'], testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

function exists(wrapper: ReturnType<typeof mountFree>['wrapper'], testId: string): boolean {
  return wrapper.find(`[data-testid="${testId}"]`).exists()
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue([] as never)
})

enableAutoUnmount(afterEach)

describe('MiningCompanionFree', () => {
  it('shows the moon, the solar system, the state and the countdown of the next chunk, the moon identifier and the unknown dash when names are missing, and the countdown to the decay of a ready chunk', async () => {
    const { wrapper } = mountFree({ chunk: CHUNK_B })
    const extracting = {
      chunk: textOf(wrapper, 'companion-free-chunk'),
      countdown: textOf(wrapper, 'companion-free-countdown'),
    }

    await wrapper.setProps({ chunk: CHUNK_UNNAMED })
    const unnamed = textOf(wrapper, 'companion-free-chunk')

    await wrapper.setProps({ chunk: CHUNK_READY })
    const ready = { chunk: textOf(wrapper, 'companion-free-chunk'), countdown: textOf(wrapper, 'companion-free-countdown') }

    expect({
      extractingChunk: extracting.chunk,
      extractingCountdown: extracting.countdown,
      unnamedHasMoonId: unnamed?.includes(String(UNNAMED_MOON_ID)),
      unnamedHasDash: unnamed?.includes(UNKNOWN_DASH),
      readyChunk: ready.chunk,
      readyCountdown: ready.countdown,
    }).toEqual({
      extractingChunk: expect.stringMatching(/Jaspet V - Moon 1[\s\S]*Jaspet[\s\S]*Extracting/),
      extractingCountdown: '1h 05m',
      unnamedHasMoonId: true,
      unnamedHasDash: true,
      readyChunk: expect.stringMatching(/Plagioclase I - Moon 7[\s\S]*Ready/),
      readyCountdown: '1h 30m',
    })
  })

  it('says there is no chunk but keeps the estimate of a single site, and shows no chunk while loading or after a failure', async () => {
    const { wrapper, i18n, t } = mountFree({})
    const none = {
      message: textOf(wrapper, 'companion-free-none'),
      messageKnown: i18n.global.te('miningCompanion.free.none'),
      chunk: exists(wrapper, 'companion-free-chunk'),
      create: exists(wrapper, 'companion-free-create-operation'),
      estimate: exists(wrapper, 'companion-free-estimate'),
    }

    await wrapper.setProps({ chunk: CHUNK_B, loading: true })
    const loading = { text: wrapper.text(), chunk: exists(wrapper, 'companion-free-chunk') }

    await wrapper.setProps({ loading: false, failure: LOAD_FAILURE })
    const failed = { error: textOf(wrapper, 'companion-free-error'), chunk: exists(wrapper, 'companion-free-chunk'), create: exists(wrapper, 'companion-free-create-operation') }

    expect({
      none,
      loadingMentioned: loading.text.includes(t('miningCompanion.loading')),
      loadingChunk: loading.chunk,
      failed,
    }).toEqual({
      none: { message: t('miningCompanion.free.none'), messageKnown: true, chunk: false, create: false, estimate: true },
      loadingMentioned: true,
      loadingChunk: false,
      failed: { error: LOAD_FAILURE, chunk: false, create: false },
    })
  })

  it('emits the chunk identifier from the create operation button', async () => {
    const { wrapper } = mountFree({ chunk: CHUNK_B })

    await found(wrapper, '[data-testid="companion-free-create-operation"]').trigger('click')

    expect(wrapper.emitted('create-operation')).toEqual([[CHUNK_B.id]])
  })

  it('opens the form of a single site without the closing warning and emits the label and the solar system when submitted', async () => {
    const { wrapper, t } = mountFree({})
    const formBefore = exists(wrapper, 'companion-next-site-form')

    await found(wrapper, '[data-testid="companion-free-estimate"]').trigger('click')
    const form = found(wrapper, '[data-testid="companion-next-site-form"]')
    const warned = form.text().includes(t('miningSite.create.closesActive'))
    await found(wrapper, 'input[name="label"]').setValue('Belt Jaspet')
    await form.trigger('submit')
    await flushPromises()

    expect({ formBefore, warned, emitted: wrapper.emitted('estimate') }).toEqual({
      formBefore: false,
      warned: false,
      emitted: [[{ label: 'Belt Jaspet', solarSystemId: null }]],
    })
  })
})
