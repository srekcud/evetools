import { TEST_LOCALES, messageOf } from '../../localeTexts'

describe('Workshop action: join requests to handle', () => {
  it.each(TEST_LOCALES)('has the workshop action texts in %s', locale => {
    for (const key of ['text', 'textUnknownQuantity', 'button']) {
      expect(messageOf(locale, `industry.workshop.actions.review_join_requests.${key}`)).not.toBe('')
    }
  })
})
