import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiAlertsBanner from '@/components/planetary/PiAlertsBanner.vue'
import type { Colony } from '@/stores/planetary'

const TAMA_COLONY: Colony = {
  id: 'colony-tama',
  characterId: 2_119_000_003,
  characterName: 'Erin Planeteer',
  planetId: 40_009_082,
  planetName: 'Tama VII',
  planetType: 'barren',
  solarSystemId: 30_002_813,
  solarSystemName: 'Tama',
  solarSystemSecurity: 0.3,
  upgradeLevel: 5,
  numPins: 2,
  lastUpdate: '2026-10-26T10:00:00+00:00',
  cachedAt: '2026-10-26T10:05:00+00:00',
  extractorCount: 2,
  factoryCount: 0,
  activeExtractors: 2,
  nearestExpiry: '2026-11-10T15:00:00+00:00',
  status: 'expiring',
  pins: [],
  routes: [],
}

const AMAMAKE_COLONY: Colony = { ...TAMA_COLONY, id: 'colony-amamake', planetId: 40_009_083, solarSystemName: 'Amamake', planetType: 'lava' }

function mountBanner(thresholdMinutes: number | null): string {
  return mount(PiAlertsBanner, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { expiredColonies: [], expiringColonies: [TAMA_COLONY, AMAMAKE_COLONY], thresholdMinutes },
  }).text()
}

describe('PiAlertsBanner expiring window', () => {
  it('names no window while the threshold is unknown instead of showing 0 or 24 hours', () => {
    const text = mountBanner(null)

    expect(text).toBe('2 expiring soon')
    expect(text).not.toMatch(/\b24\s?h/)
    expect(text).not.toMatch(/\b0\s?(h|min)/)
  })

  it('names the window of the threshold of the user', () => {
    expect(mountBanner(360)).toBe('2 expiring within 6h')
    expect(mountBanner(90)).toBe('2 expiring within 1h30')
  })
})
