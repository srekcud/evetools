import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'

function characterOptionLabels(locale: 'en' | 'fr'): string[] {
  const wrapper = mount(AssetsFilterBar, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
    props: {
      viewMode: 'character',
      characters: [
        { id: 'char-main', name: 'Aria Voss', isMain: true },
        { id: 'char-alt', name: 'Brin Tal', isMain: false },
      ],
      selectedCharacterId: 'char-main',
      searchQuery: '',
      solarSystems: [],
      locations: [],
      selectedSolarSystem: '',
      selectedLocation: '',
    },
  })
  return wrapper.find('select').findAll('option').map(option => option.text().replace(/\s+/g, ' ').trim())
}

describe('Assets filter bar character options', () => {
  it('marks the main character with the English label', () => {
    expect(characterOptionLabels('en')).toEqual(['Aria Voss (Main)', 'Brin Tal'])
  })

  it('marks the main character with the French label', () => {
    expect(characterOptionLabels('fr')).toEqual(['Aria Voss (Principal)', 'Brin Tal'])
  })
})
