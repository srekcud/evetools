import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerLineStatus from '@/components/group-industry/ContainerLineStatus.vue'
import type { ContainerVerification, ContainerVerificationStatus } from '@/stores/group-industry/types'

type Locale = 'en' | 'fr'

const BLANK = /[\u00a0\u202f]/g
const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/

function cadmiumLine(
  status: ContainerVerificationStatus,
  requiredQuantity: number,
  containerQuantity: number,
  consumedQuantity: number,
): ContainerVerification {
  return {
    bomItemId: 'bom-cadmium',
    typeId: 16647,
    typeName: 'Cadmium',
    requiredQuantity,
    containerQuantity,
    consumedQuantity,
    status,
    unknownReason: null,
    assetsSyncedAt: null,
    missingContainerNames: [],
  }
}

function statusTextOf(locale: Locale, verification: ContainerVerification): string {
  const wrapper = mount(ContainerLineStatus, {
    props: { verification },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  return wrapper.text().replace(BLANK, ' ').replace(/\s+/g, ' ').trim()
}

describe('ContainerLineStatus quantity consumed by started jobs', () => {
  it('compares the container to the required quantity minus the consumed one and says how much the started jobs used', () => {
    const text = statusTextOf('en', cadmiumLine('verified', 38_200, 34_987, 3_213))

    expect([text.includes('34,987 / 34,987'), text.includes('3,213 used by started jobs'), text.includes('38,200'), RAW_TRANSLATION_KEY.test(text)]).toEqual([true, true, false, false])
  })

  it('words the consumed quantity in French on a partial line', () => {
    const text = statusTextOf('fr', cadmiumLine('partial', 1_000, 600, 300))

    expect([text.includes('600 / 700'), /300 utilis.+ par les jobs lancés/.test(text), RAW_TRANSLATION_KEY.test(text)]).toEqual([true, true, false])
  })

  it.each<Locale>(['en', 'fr'])('shows no mention and the required quantity when no started job consumed anything in %s', (locale) => {
    const text = statusTextOf(locale, cadmiumLine('partial', 500, 450, 0))

    expect([text.includes('450 / 500'), /started jobs|jobs lancés/.test(text), RAW_TRANSLATION_KEY.test(text)]).toEqual([true, false, false])
  })
})
