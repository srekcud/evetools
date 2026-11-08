import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { apiRequest, authFetch } from '@/services/api'
import { LONG_STRUCTURE_NAME } from './moonChunksHarness'
import {
  DAX_ID,
  defaultRoutes,
  MINE_DUE,
  MINE_PROVISIONAL,
  MINE_UNKNOWN,
  mineCell,
  mineRows,
  mountMoonTax,
  NOW,
  present,
  requestLines,
  type MoonTaxWrapper,
} from './moonTaxHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

enableAutoUnmount(afterEach)

const MINE_PATH = '/mining/moon-tax/mine'
const clipboard = { writeText: vi.fn() }

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  vi.mocked(authFetch).mockReset()
  clipboard.writeText = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true })
})

afterEach(() => {
  vi.useRealTimers()
  Reflect.deleteProperty(navigator, 'clipboard')
})

async function openMinerView(mine: unknown[]): Promise<MoonTaxWrapper> {
  const wrapper = await mountMoonTax({ ...defaultRoutes(), [`GET ${MINE_PATH}`]: mine })
  await present(wrapper, '[data-moon-tax-view="miner"]').trigger('click')
  await flushPromises()
  return wrapper
}

describe('MoonTaxTab miner view', () => {
  it('lists the statements of my characters with their reference to copy, a provisional period without reference and an unknown tax with its type ids', async () => {
    const wrapper = await openMinerView([MINE_DUE, MINE_PROVISIONAL, MINE_UNKNOWN])

    expect([present(wrapper, '[data-moon-tax-view="miner"]').attributes('aria-pressed'), present(wrapper, '[data-moon-tax-view="manager"]').attributes('aria-pressed')]).toEqual(['true', 'false'])
    expect(requestLines()).toContain(`GET ${MINE_PATH}`)
    expect(mineRows(wrapper)).toHaveLength(3)
    expect(mineCell(wrapper, 0, 'period').text()).toContain('2026-10-26')
    expect(mineCell(wrapper, 0, 'period').text()).toContain('2026-11-02')
    expect(['refinery', 'tax', 'paid', 'remaining', 'reference'].map(name => mineCell(wrapper, 0, name).text())).toEqual([
      'NOL-M9 - Rock Hollow',
      '6.00 M',
      '0',
      '6.00 M',
      'TAX-2026-W44-7F3K',
    ])
    expect(mineCell(wrapper, 0, 'character').text()).toContain(String(DAX_ID))
    expect(mineCell(wrapper, 0, 'status').attributes('data-status')).toBe('due')

    await mineRows(wrapper)[0].get('[data-copy-reference]').trigger('click')
    expect(clipboard.writeText).toHaveBeenCalledTimes(1)
    expect(clipboard.writeText).toHaveBeenCalledWith('TAX-2026-W44-7F3K')

    expect(mineCell(wrapper, 1, 'status').attributes('data-status')).toBe('provisional')
    expect(mineCell(wrapper, 1, 'reference').text()).not.toContain('TAX-')
    expect(mineRows(wrapper)[1].find('[data-copy-reference]').exists()).toBe(false)
    expect(wrapper.findAll('[data-copy-reference]')).toHaveLength(2)

    expect(mineCell(wrapper, 2, 'refinery').text()).toBe(LONG_STRUCTURE_NAME)
    expect(mineCell(wrapper, 2, 'tax').attributes('data-unknown')).toBeDefined()
    expect(mineCell(wrapper, 2, 'tax').text()).toContain('3, 4')
    expect(mineCell(wrapper, 2, 'remaining').attributes('data-unknown')).toBeDefined()
  })

  it('says there is no moon tax for my characters when no statement is returned', async () => {
    const wrapper = await openMinerView([])

    expect(mineRows(wrapper)).toHaveLength(0)
    expect(present(wrapper, '[data-empty-mine]').text().length).toBeGreaterThan(0)
  })
})
