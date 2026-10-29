import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import NotificationItem from '@/components/notifications/NotificationItem.vue'
import type { AppNotification } from '@/stores/notifications'

const NOTIFICATION: AppNotification = {
  id: 'notification-1',
  category: 'esi',
  level: 'info',
  title: 'Planet extractors expire soon',
  message: 'Two extractors expire in one hour',
  data: null,
  route: null,
  isRead: false,
  createdAt: new Date().toISOString(),
}

function mountItem(locale: 'en' | 'fr' = 'en'): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(NotificationItem, {
    props: { notification: NOTIFICATION },
    global: { plugins: [i18n] },
  })
}

describe('NotificationItem delete button', () => {
  it('is present in the DOM and reachable by keyboard without hover', () => {
    const deleteButton = mountItem().find('button[aria-label="Delete"]')

    expect(deleteButton.exists()).toBe(true)
    expect(deleteButton.classes()).not.toContain('hidden')
    expect(deleteButton.attributes('tabindex')).toBeUndefined()
    expect(deleteButton.attributes('disabled')).toBeUndefined()
  })

  it('is shown on keyboard focus, on hover and on touch screens', () => {
    const classes = mountItem().find('button[aria-label="Delete"]').classes()

    expect(classes).toContain('focus-visible:opacity-100')
    expect(classes).toContain('group-focus-within/item:opacity-100')
    expect(classes).toContain('group-hover/item:opacity-100')
    expect(classes).toContain('[@media(hover:none)]:opacity-100')
  })

  it('hides the date while the item is hovered or focused', () => {
    const dateClasses = mountItem().find('span.whitespace-nowrap').classes()

    expect(dateClasses).toContain('group-hover/item:hidden')
    expect(dateClasses).toContain('group-focus-within/item:hidden')
  })

  it('is labelled in French', () => {
    expect(mountItem('fr').find('button[aria-label="Supprimer"]').exists()).toBe(true)
  })

  it('emits delete with the notification id and no click on click', async () => {
    const wrapper = mountItem()

    await wrapper.find('button[aria-label="Delete"]').trigger('click')

    expect(wrapper.emitted('delete')).toEqual([['notification-1']])
    expect(wrapper.emitted('click')).toBeUndefined()
  })
})
