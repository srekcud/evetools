import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ShareLinkCard from '@/components/group-industry/ShareLinkCard.vue'
import { expectNoneOf, expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const SHORT_LINK_CODE = 'N4T8QW'

const FORMER_HARDCODED_TEXTS = [
  'Share Join Link',
  'Copied!',
  'Members from your corporation join instantly. Others require your approval.',
]

function mountShareLinkCard(locale: TestLocale) {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(ShareLinkCard, { global: { plugins: [i18n] }, props: { shortLinkCode: SHORT_LINK_CODE } })
}

beforeEach(() => {
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
  })
})

enableAutoUnmount(afterEach)

describe('ShareLinkCard locale', () => {
  it.each(TEST_LOCALES)('shows only %s texts before copying the link', (locale) => {
    const text = mountShareLinkCard(locale).text()

    expectOnlyLocaleTexts(text, locale, [
      'groupIndustry.shareLink.title',
      'common.actions.copy',
      'groupIndustry.shareLink.sameCorp',
      'groupIndustry.shareLink.otherCorp',
    ])
    expectNoneOf(text, FORMER_HARDCODED_TEXTS)
  })

  it.each(TEST_LOCALES)('shows only the %s copied confirmation after copying the link', async (locale) => {
    const wrapper = mountShareLinkCard(locale)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expectOnlyLocaleTexts(wrapper.text(), locale, ['groupIndustry.shareLink.copied'])
    expectNoneOf(wrapper.text(), FORMER_HARDCODED_TEXTS)
  })
})
