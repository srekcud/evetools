import { expectedMessage } from '../expectedMessage'
import { mountMainLayoutAt } from './mainLayoutHarness'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('MainLayout help link', () => {
  it('shows the help link in the menu footer and keeps it out of the main menu', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    const footerLink = wrapper.find('aside a[href="/help"]')

    expect(footerLink.exists()).toBe(true)
    expect(footerLink.text()).toBe(expectedMessage('en', 'nav.help'))
    expect(wrapper.find('aside nav a[href="/help"]').exists()).toBe(false)
  })
})
