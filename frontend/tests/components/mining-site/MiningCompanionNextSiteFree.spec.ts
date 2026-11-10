import { enableAutoUnmount, mount } from '@vue/test-utils'
import MiningCompanionNextSite from '@/components/mining-site/MiningCompanionNextSite.vue'
import { i18nFor } from '../../views/MiningSite/miningSiteFixtures'

function warningShown(props: { closesActive?: boolean }): boolean {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningCompanionNextSite, {
    props: { failure: null, submitting: false, ...props },
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
  })
  return wrapper.text().includes(i18n.global.t('miningSite.create.closesActive'))
}

enableAutoUnmount(afterEach)

describe('MiningCompanionNextSite closing warning', () => {
  it('hides the warning that the active sites will be closed when closesActive is false and shows it by default or when true', () => {
    expect({
      closesNothing: warningShown({ closesActive: false }),
      byDefault: warningShown({}),
      closesActive: warningShown({ closesActive: true }),
    }).toEqual({ closesNothing: false, byDefault: true, closesActive: true })
  })
})
