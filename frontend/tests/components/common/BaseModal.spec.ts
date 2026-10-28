import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import BaseModal from '@/components/common/BaseModal.vue'

const MODAL_TITLE = 'Delete stockpile'
const ACTIONS_SLOT =
  '<button id="cancel-action">Cancel</button><input id="quantity-input" /><button id="save-action">Save</button>'
const TEXT_ONLY_SLOT = '<p id="notice-text">Nothing to edit</p>'
const BODY_OVERFLOW_BEFORE_OPENING = 'auto'

let mountedWrappers: VueWrapper[] = []

function mountModal(show: boolean, slot: string = ACTIONS_SLOT) {
  const host = document.createElement('div')
  host.id = 'modal-host'
  document.body.appendChild(host)
  const wrapper = mount(BaseModal, {
    props: { show, title: MODAL_TITLE },
    slots: { default: slot },
    attachTo: host,
  })
  mountedWrappers.push(wrapper)
  return { wrapper, host }
}

function addTrigger() {
  const trigger = document.createElement('button')
  trigger.id = 'open-modal-trigger'
  document.body.appendChild(trigger)
  trigger.focus()
  return trigger
}

function byId(id: string) {
  return document.getElementById(id) as HTMLElement
}

function pressKey(key: string, shiftKey = false) {
  const target = (document.activeElement ?? document.body) as HTMLElement
  target.dispatchEvent(new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }))
}

async function open(wrapper: VueWrapper) {
  await wrapper.setProps({ show: true })
  await flushPromises()
}

async function close(wrapper: VueWrapper) {
  await wrapper.setProps({ show: false })
  await flushPromises()
}

describe('BaseModal', () => {
  beforeEach(() => {
    document.body.style.overflow = BODY_OVERFLOW_BEFORE_OPENING
  })

  afterEach(() => {
    mountedWrappers.forEach((wrapper) => wrapper.unmount())
    mountedWrappers = []
    document.body.innerHTML = ''
    document.body.style.overflow = ''
  })

  it('renders nothing while closed', () => {
    mountModal(false)

    expect(document.getElementById('cancel-action')).toBeNull()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('teleports its content to the body, outside the parent element', async () => {
    const { wrapper, host } = mountModal(false)

    await open(wrapper)

    const firstAction = byId('cancel-action')
    expect(document.body.contains(firstAction)).toBe(true)
    expect(host.contains(firstAction)).toBe(false)
  })

  it('exposes a modal dialog labelled by its title', async () => {
    const { wrapper } = mountModal(false)

    await open(wrapper)

    const dialog = document.querySelector('[role="dialog"]') as HTMLElement
    expect(dialog).not.toBeNull()
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    const titleId = dialog.getAttribute('aria-labelledby') as string
    expect(titleId).toBeTruthy()
    expect(document.getElementById(titleId)?.textContent?.trim()).toBe(MODAL_TITLE)
    expect(dialog.contains(document.getElementById(titleId))).toBe(true)
  })

  it('moves the focus to the first focusable element on opening', async () => {
    addTrigger()
    const { wrapper } = mountModal(false)

    await open(wrapper)

    expect(document.activeElement).toBe(byId('cancel-action'))
  })

  it('moves the focus to the dialog container when it has no focusable element', async () => {
    addTrigger()
    const { wrapper } = mountModal(false, TEXT_ONLY_SLOT)

    await open(wrapper)

    expect(document.activeElement).toBe(document.querySelector('[role="dialog"]'))
  })

  it('wraps Tab from the last focusable element back to the first', async () => {
    const { wrapper } = mountModal(false)
    await open(wrapper)
    byId('save-action').focus()

    pressKey('Tab')

    expect(document.activeElement).toBe(byId('cancel-action'))
  })

  it('wraps Shift+Tab from the first focusable element to the last', async () => {
    const { wrapper } = mountModal(false)
    await open(wrapper)
    byId('cancel-action').focus()

    pressKey('Tab', true)

    expect(document.activeElement).toBe(byId('save-action'))
  })

  it('emits close once when Escape is pressed', async () => {
    const { wrapper } = mountModal(false)
    await open(wrapper)

    pressKey('Escape')

    expect(wrapper.emitted('close')).toEqual([[]])
  })

  it('gives the focus back to the trigger on closing', async () => {
    const trigger = addTrigger()
    const { wrapper } = mountModal(false)
    await open(wrapper)
    byId('quantity-input').focus()

    await close(wrapper)

    expect(document.activeElement).toBe(trigger)
  })

  it('locks the page scroll on opening and restores it on closing', async () => {
    const { wrapper } = mountModal(false)

    await open(wrapper)
    expect(document.body.style.overflow).toBe('hidden')

    await close(wrapper)
    expect(document.body.style.overflow).toBe(BODY_OVERFLOW_BEFORE_OPENING)
  })

  it('focuses, locks and restores the same way on a second opening', async () => {
    const trigger = addTrigger()
    const { wrapper } = mountModal(false)
    await open(wrapper)
    await close(wrapper)

    await open(wrapper)

    expect(document.activeElement).toBe(byId('cancel-action'))
    expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1)
    expect(document.body.style.overflow).toBe('hidden')

    await close(wrapper)

    expect(document.activeElement).toBe(trigger)
    expect(document.body.style.overflow).toBe(BODY_OVERFLOW_BEFORE_OPENING)
  })

  it('restores the page scroll when unmounted while open', async () => {
    const { wrapper } = mountModal(false)
    await open(wrapper)
    expect(document.body.style.overflow).toBe('hidden')

    wrapper.unmount()
    mountedWrappers = []

    expect(document.body.style.overflow).toBe(BODY_OVERFLOW_BEFORE_OPENING)
  })
})
