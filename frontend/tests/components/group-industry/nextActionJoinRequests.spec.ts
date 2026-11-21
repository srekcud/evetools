import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectNextActionCard from '@/components/group-industry/ProjectNextActionCard.vue'
import type { ProjectNextAction } from '@/stores/group-industry/types'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

const JOIN_REQUESTS_ACTION: ProjectNextAction = {
  code: 'review_join_requests',
  blocked: false,
  tab: 'members',
  targetStatus: null,
  bomLineId: null,
  typeId: null,
  typeName: null,
  quantity: 3,
  endsAt: null,
}

function mountCard(locale: TestLocale) {
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, fr } })
  return mount(ProjectNextActionCard, {
    props: { nextAction: JOIN_REQUESTS_ACTION, disabled: false },
    global: { plugins: [i18n] },
  })
}

describe('Next action: join requests to handle', () => {
  it.each(TEST_LOCALES)('writes the pending join requests of the project to the manager in %s', locale => {
    const text = mountCard(locale).text()

    expect(text).toContain(messageOf(locale, 'groupIndustry.cockpit.nextAction.review_join_requests.title'))
    expect(text).toContain(messageOf(locale, 'groupIndustry.cockpit.nextAction.review_join_requests.button'))
    expect(text).toContain('3')
    expect(text).not.toContain('review_join_requests')
  })

  it.each(TEST_LOCALES)('has the workshop action texts in %s', locale => {
    for (const key of ['text', 'textUnknownQuantity', 'button']) {
      expect(messageOf(locale, `industry.workshop.actions.review_join_requests.${key}`)).not.toBe('')
    }
  })
})
