import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import { apiRequest } from '@/services/api'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

const STATION_ID = 60003760

function mountButton(locale: 'en' | 'fr') {
  return mount(OpenInGameButton, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
    props: { type: 'waypoint', targetId: STATION_ID } as never,
  })
}

describe('OpenInGameButton waypoint', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it('is titled "Set as destination" in English', () => {
    expect(mountButton('en').attributes('title')).toBe('Set as destination')
  })

  it('is titled in French', () => {
    expect(mountButton('fr').attributes('title')).toBe('Definir comme destination')
  })

  it('posts the destination and confirms it', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: true, error: null })
    const wrapper = mountButton('en')

    await wrapper.trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith('/me/open-window/waypoint', {
      method: 'POST',
      body: JSON.stringify({ destinationId: STATION_ID }),
    })
    expect(wrapper.attributes('title')).toBe('Window opened in-game')
  })

  it('shows the error state when the destination is refused', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: false, error: 'missing scope' })
    const wrapper = mountButton('en')

    await wrapper.trigger('click')
    await flushPromises()

    expect(wrapper.attributes('title')).toBe('Error opening window')
  })
})
