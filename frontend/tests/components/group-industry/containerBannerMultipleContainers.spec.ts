import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { ContainerVerification } from '@/stores/group-industry/types'

const PROJECT_ID = 'project-hull-run'

function line(missingContainerNames: string[], status: ContainerVerification['status'] = 'verified'): ContainerVerification {
  return {
    bomItemId: 'bom-tritanium',
    typeId: 34,
    typeName: 'Tritanium',
    requiredQuantity: 500,
    containerQuantity: 500,
    status,
    unknownReason: null,
    assetsSyncedAt: null,
    missingContainerNames,
  }
}

function mountBanner(containerNames: string[], lines: ContainerVerification[], locale: 'en' | 'fr' = 'en') {
  setActivePinia(createPinia())
  useGroupProjectStore().containerVerification = lines
  return mount(ContainerBanner, {
    props: { containerNames, projectId: PROJECT_ID, canVerify: true },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('ContainerBanner with several containers', () => {
  it('names every container the project watches', () => {
    const wrapper = mountBanner(['Mats', 'Products'], [])

    expect(wrapper.get('[data-testid="container-names"]').text()).toBe('"Mats", "Products"')
  })

  it('shows no missing container when every one was found', () => {
    const wrapper = mountBanner(['Mats', 'Products'], [line([])])

    expect(wrapper.find('[data-testid="container-missing-names"]').exists()).toBe(false)
  })

  it('names each container that was not found', () => {
    const wrapper = mountBanner(['Mats', 'Products', 'Fuel'], [line(['Products', 'Fuel'])])

    expect(wrapper.get('[data-testid="container-missing-names"]').text()).toContain('"Products", "Fuel"')
  })

  it('names the missing containers in French as well, without a raw translation key', () => {
    const wrapper = mountBanner(['Mats', 'Products'], [line(['Products'])], 'fr')

    const shown = wrapper.get('[data-testid="container-missing-names"]').text()
    expect([shown.includes('"Products"'), /groupIndustry\./.test(shown)]).toEqual([true, false])
  })

  it('reports the missing names once even when every line carries them', () => {
    const wrapper = mountBanner(['Mats', 'Products'], [line(['Products']), line(['Products'])])

    expect(wrapper.findAll('[data-testid="container-missing-names"]')).toHaveLength(1)
  })
})
