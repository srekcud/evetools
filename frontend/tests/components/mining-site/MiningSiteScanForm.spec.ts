import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import MiningSiteScanForm from '@/components/mining-site/MiningSiteScanForm.vue'
import {
  KERNITE,
  ORE_VALUES_PATH,
  SCANS_PATH,
  SCORDITE,
  VELDSPAR,
  at,
  depletedType,
  found,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteAfterThreeScans,
  siteOf,
} from '../../views/MiningSite/miningSiteFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MAX_LINES = 100

function answerWith(recorded: MiningSiteScan): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
    if (endpoint === SCANS_PATH) return recorded as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountForm(site: MiningSite) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningSiteScanForm, { props: { site }, global: { plugins: [i18n] } })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

async function addLine(wrapper: VueWrapper, typeId: number, units: string): Promise<void> {
  await found(wrapper, '[data-testid="scan-add-line"]').trigger('click')
  const lines = wrapper.findAll('[data-testid="scan-line"]')
  const line = lines[lines.length - 1]
  expect(line, 'new line').toBeDefined()
  if (line === undefined) return
  await found(line, 'select[name="typeId"]').setValue(String(typeId))
  await found(line, 'input[name="units"]').setValue(units)
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await found(wrapper, '[data-testid="scan-form"]').trigger('submit')
  await flushPromises()
}

function postedScans(): unknown[] {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === SCANS_PATH && call[1]?.method === 'POST').map(call => JSON.parse(String(call[1]?.body)))
}

function textOf(wrapper: VueWrapper, testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningSiteScanForm', () => {
  it('offers the ores of the value table, sends the lines of the scan, announces the ETA before and after and keeps no text', async () => {
    const recorded = scanOf(2, at('20:30:00'), [presentType(VELDSPAR, 25_000, { trendEta: at('21:00:00') }), presentType(SCORDITE, 5_000)], { trendEta: at('21:00:00') })
    answerWith(recorded)
    const { wrapper, t } = await mountForm(siteOf({ lastScanNumber: 1, lastScanAt: at('20:20:00') }))

    await addLine(wrapper, VELDSPAR, '25000')
    await addLine(wrapper, SCORDITE, '5000')
    const options = found(wrapper, 'select[name="typeId"]').findAll('option').filter(option => option.element.value !== '')
    await submit(wrapper)

    expect({
      offered: options.map(option => [option.element.value, option.text()]),
      posted: postedScans(),
      message: textOf(wrapper, 'scan-added'),
      linesLeft: wrapper.findAll('[data-testid="scan-line"]').length,
      emitted: wrapper.emitted('recorded')?.[0] ?? null,
    }).toEqual({
      offered: [[String(VELDSPAR), 'Veldspar'], [String(SCORDITE), 'Scordite'], [String(KERNITE), 'Kernite']],
      posted: [{ lines: [{ typeId: VELDSPAR, units: 25_000 }, { typeId: SCORDITE, units: 5_000 }] }],
      message: expect.stringContaining(t('miningSite.scan.added', { number: 2, from: t('miningSite.eta.unknown'), to: '21:00' })),
      linesLeft: 0,
      emitted: [recorded],
    })
  })

  it('refuses a first scan without line, units that are not a whole number above 0, a type twice and more than 100 lines, without calling the server', async () => {
    answerWith(scanOf(1, at('20:30:00'), [presentType(VELDSPAR, 25_000)]))
    const { wrapper, t } = await mountForm(siteOf())

    await submit(wrapper)
    const withoutLine = textOf(wrapper, 'scan-error')
    await addLine(wrapper, VELDSPAR, '0')
    await submit(wrapper)
    const zeroUnits = textOf(wrapper, 'scan-error')
    await found(wrapper, 'input[name="units"]').setValue('-5')
    await submit(wrapper)
    const negativeUnits = textOf(wrapper, 'scan-error')
    await found(wrapper, 'input[name="units"]').setValue('1.5')
    await submit(wrapper)
    const fractionalUnits = textOf(wrapper, 'scan-error')
    await found(wrapper, 'input[name="units"]').setValue('100')
    await addLine(wrapper, VELDSPAR, '200')
    await submit(wrapper)
    const duplicatedType = textOf(wrapper, 'scan-error')
    for (let line = wrapper.findAll('[data-testid="scan-line"]').length; line < MAX_LINES; line++) {
      await found(wrapper, '[data-testid="scan-add-line"]').trigger('click')
    }

    expect({
      withoutLine,
      zeroUnits,
      negativeUnits,
      fractionalUnits,
      duplicatedType,
      posted: postedScans(),
      lines: wrapper.findAll('[data-testid="scan-line"]').length,
      addDisabled: wrapper.find('[data-testid="scan-add-line"]').exists() && wrapper.find('[data-testid="scan-add-line"]').attributes('disabled') !== undefined,
    }).toEqual({
      withoutLine: expect.stringContaining(t('miningSite.scan.errors.noLines')),
      zeroUnits: expect.stringContaining(t('miningSite.scan.errors.unitsInvalid')),
      negativeUnits: expect.stringContaining(t('miningSite.scan.errors.unitsInvalid')),
      fractionalUnits: expect.stringContaining(t('miningSite.scan.errors.unitsInvalid')),
      duplicatedType: expect.stringContaining(t('miningSite.scan.errors.duplicate')),
      posted: [],
      lines: MAX_LINES,
      addDisabled: true,
    })
  })

  it('lets a site that already has scans report that everything is depleted with an empty scan, and offers it to no first scan', async () => {
    const everythingDepleted = scanOf(4, at('20:30:00'), [depletedType(VELDSPAR, at('20:20:00'), at('20:30:00')), depletedType(SCORDITE, at('20:20:00'), at('20:30:00'))])
    answerWith(everythingDepleted)
    const withoutScan = await mountForm(siteOf())
    const offeredToFirstScan = withoutScan.wrapper.find('[data-testid="scan-all-depleted"]').exists()
    withoutScan.wrapper.unmount()
    const { wrapper, t } = await mountForm(siteAfterThreeScans())

    await found(wrapper, '[data-testid="scan-all-depleted"]').trigger('click')
    await flushPromises()

    expect({
      offeredToFirstScan,
      label: textOf(wrapper, 'scan-all-depleted'),
      posted: postedScans(),
      message: textOf(wrapper, 'scan-added'),
    }).toEqual({
      offeredToFirstScan: false,
      label: t('miningSite.scan.allDepleted'),
      posted: [{ lines: [] }],
      message: expect.stringContaining(t('miningSite.scan.added', { number: 4, from: '20:50', to: t('miningSite.eta.depleted') })),
    })
  })
})
