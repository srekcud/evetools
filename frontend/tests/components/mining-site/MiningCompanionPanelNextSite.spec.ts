import { enableAutoUnmount, mount } from '@vue/test-utils'
import MiningCompanionPanel from '@/components/mining-site/MiningCompanionPanel.vue'
import { OPERATION_ID, at, i18nFor, siteAfterThreeScans, veldsparAndScorditeScans } from '../../views/MiningSite/miningSiteFixtures'

type Operation = { id: string; status: string; myDepartedAt: string | null }

function mountPanel(operation: Operation | null, size: 'bar' | 'compact' | 'detail' = 'compact') {
  return mount(MiningCompanionPanel, {
    props: {
      site: siteAfterThreeScans(),
      scans: veldsparAndScorditeScans(),
      size,
      now: new Date(at('20:25:00')),
      rescanMinutes: 10,
      typeNames: {},
      operation,
      notice: null,
    },
    global: { plugins: [i18nFor('en')] },
  })
}

function operationOf(status: string, myDepartedAt: string | null = null): Operation {
  return { id: OPERATION_ID, status, myDepartedAt }
}

function hasNextSiteButton(wrapper: ReturnType<typeof mountPanel>): boolean {
  return wrapper.find('[data-testid="companion-next-site"]').exists()
}

enableAutoUnmount(afterEach)

describe('MiningCompanionPanel next site', () => {
  it('offers the next site only for an operation in progress that I have not left, outside the bar size', () => {
    expect({
      inProgress: hasNextSiteButton(mountPanel(operationOf('in_progress'))),
      inProgressDetail: hasNextSiteButton(mountPanel(operationOf('in_progress'), 'detail')),
      inProgressBar: hasNextSiteButton(mountPanel(operationOf('in_progress'), 'bar')),
      departed: hasNextSiteButton(mountPanel(operationOf('in_progress', at('20:10:00')))),
      ended: hasNextSiteButton(mountPanel(operationOf('ended'))),
      planned: hasNextSiteButton(mountPanel(operationOf('planned'))),
      withoutOperation: hasNextSiteButton(mountPanel(null)),
    }).toEqual({
      inProgress: true,
      inProgressDetail: true,
      inProgressBar: false,
      departed: false,
      ended: false,
      planned: false,
      withoutOperation: false,
    })
  })

  it('emits next-site when the button is clicked', async () => {
    const wrapper = mountPanel(operationOf('in_progress'))

    const button = wrapper.find('[data-testid="companion-next-site"]')
    if (button.exists()) await button.trigger('click')

    expect(wrapper.emitted('next-site')).toEqual([[]])
  })
})
