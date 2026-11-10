import { enableAutoUnmount, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import MiningCompanionHauler from '@/components/mining-site/MiningCompanionHauler.vue'
import type { CompanionSizeKey } from '@/views/MiningSite/miningCompanion'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { EMPTIED_AT, haulerAttribute, haulerElement, haulerText, knownHaulerPlan } from '../mining-operation/haulerPlanFixtures'

function mountHauler(size: CompanionSizeKey, canDeclareEmptied: boolean, plan = knownHaulerPlan()) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(MiningCompanionHauler, {
    props: { plan, size, canDeclareEmptied },
    global: { plugins: [i18n] },
  })
  return { wrapper, t: i18n.global.t }
}

enableAutoUnmount(afterEach)

describe('MiningCompanionHauler', () => {
  it('shows only the short bar, without the flow nor the button, in the bar and compact sizes', () => {
    const results = (['bar', 'compact'] as const).map(size => {
      const { wrapper } = mountHauler(size, true)
      const result = {
        width: haulerAttribute(wrapper, 'hauler-fill', 'style'),
        tone: haulerAttribute(wrapper, 'hauler-fill', 'data-tone'),
        fillText: haulerText(wrapper, 'hauler-fill-text'),
        flow: haulerElement(wrapper, 'hauler-flow') !== null,
        button: haulerElement(wrapper, 'hauler-emptied-button') !== null,
      }
      wrapper.unmount()
      return result
    })

    expect(results).toEqual(
      Array.from({ length: 2 }, () => ({ width: expect.stringContaining('width: 50%'), tone: 'amber', fillText: expect.stringMatching(/50 %.*4 min/), flow: false, button: false })),
    )
  })

  it('adds the flow, the time of the last emptying and the emptied button in the detail size, and emits the declaration', async () => {
    const { wrapper, t } = mountHauler('detail', true, knownHaulerPlan({ emptiedAt: EMPTIED_AT, originIsStart: false }))

    haulerElement(wrapper, 'hauler-emptied-button')?.click()
    await nextTick()

    expect({
      flow: haulerText(wrapper, 'hauler-flow'),
      emptiedAt: haulerText(wrapper, 'hauler-emptied-at'),
      label: haulerText(wrapper, 'hauler-emptied-button'),
      emitted: wrapper.emitted('declare-emptied')?.length,
    }).toEqual({
      flow: expect.stringContaining('5,000'),
      emptiedAt: expect.stringContaining('20:56'),
      label: t('miningOperation.haulerPlan.emptied'),
      emitted: 1,
    })
  })
})
