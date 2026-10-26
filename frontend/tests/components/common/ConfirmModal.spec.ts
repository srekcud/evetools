import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ConfirmModal from '@/components/common/ConfirmModal.vue'

const CONFIRM_TITLE = 'Delete project'
const CONFIRM_LABEL = 'Delete'

enableAutoUnmount(afterEach)

async function mountOpenConfirmModal() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ConfirmModal, {
    props: { show: true, title: CONFIRM_TITLE, confirmLabel: CONFIRM_LABEL },
    global: { plugins: [i18n] },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

function buttonLabelled(label: string) {
  return Array.from(document.querySelectorAll('button')).find(
    (button) => button.textContent?.trim() === label,
  ) as HTMLButtonElement
}

describe('ConfirmModal', () => {
  afterEach(() => {
    document.body.style.overflow = ''
  })

  it('exposes a modal dialog labelled by its title', async () => {
    await mountOpenConfirmModal()

    const dialog = document.querySelector('[role="dialog"]') as HTMLElement
    expect(dialog).not.toBeNull()
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    const titleId = dialog.getAttribute('aria-labelledby') as string
    expect(document.getElementById(titleId)?.textContent?.trim()).toBe(CONFIRM_TITLE)
  })

  it('emits cancel once when Escape is pressed', async () => {
    const wrapper = await mountOpenConfirmModal()

    const focused = (document.activeElement ?? document.body) as HTMLElement
    focused.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))

    expect(wrapper.emitted('cancel')).toEqual([[]])
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('emits cancel from the cancel button and confirm from the confirm button', async () => {
    const wrapper = await mountOpenConfirmModal()

    buttonLabelled('Cancel').click()
    buttonLabelled(CONFIRM_LABEL).click()

    expect(wrapper.emitted('cancel')).toEqual([[]])
    expect(wrapper.emitted('confirm')).toEqual([[]])
  })
})
