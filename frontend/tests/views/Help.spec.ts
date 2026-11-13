import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import Help from '@/views/Help.vue'
import { expectedMessage } from '../expectedMessage'

vi.mock('@/help/helpCatalog', async importOriginal => {
  const original = await importOriginal<typeof import('@/help/helpCatalog')>()
  return {
    ...original,
    helpCatalog: original.buildHelpCatalog({
      './fr/playbooks/industry-structures.md': '# Configurer ses structures industrielles\n\nTexte du guide en français.\n\n## Ajouter une structure\n\nÉtapes en français.',
      './en/playbooks/industry-structures.md': '# Configuring your industry structures\n\nGuide text in English.\n\n## Add a structure\n\nSteps in English.',
      './fr/limits/esi-delay.md': '# Délai de l\'ESI\n\nTexte.',
      './en/limits/esi-delay.md': '# ESI delay\n\nText.',
    }),
  }
})

const SECTIONS = ['faq', 'playbooks', 'calculations', 'data', 'limits']
const INDUSTRY_STRUCTURES_PAGE = 'playbooks/industry-structures'
const TITLES = {
  fr: { structures: 'Configurer ses structures industrielles', delay: 'Délai de l\'ESI' },
  en: { structures: 'Configuring your industry structures', delay: 'ESI delay' },
}

async function mountHelpAt(path: string, locale: 'fr' | 'en') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/help', name: 'help', component: Help },
    ],
  })
  await router.push(path)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale, messages: { fr, en } })
  const wrapper = mount(Help, { attachTo: document.body, global: { plugins: [i18n, router] } })
  await flushPromises()
  return { wrapper, i18n }
}

function sectionTitles(locale: 'fr' | 'en'): string[] {
  return SECTIONS.map(section => expectedMessage(locale, `help.sections.${section}.title`))
}

function expectIncreasing(positions: number[]): void {
  expect(positions.every(position => position >= 0)).toBe(true)
  expect(positions).toEqual([...positions].sort((first, second) => first - second))
}

function occurrencesOf(text: string, part: string): number {
  return text.split(part).length - 1
}

function decodedHrefs(wrapper: { findAll: (selector: string) => { attributes: (name: string) => string | undefined, text: () => string }[] }, hrefPart: string) {
  return wrapper.findAll('a')
    .map(link => ({ href: decodeURIComponent(link.attributes('href') ?? ''), text: link.text() }))
    .filter(link => link.href.includes(hrefPart))
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('Help view', () => {
  it('lists the five sections in order with their pages and the empty section text, in French', async () => {
    const { wrapper } = await mountHelpAt('/help', 'fr')

    const text = wrapper.text()
    const [faq, playbooks, calculations, data, limits] = sectionTitles('fr').map(title => text.indexOf(title))
    expectIncreasing([faq, playbooks, text.indexOf(TITLES.fr.structures), calculations, data, limits, text.indexOf(TITLES.fr.delay)])
    expect(decodedHrefs(wrapper, 'page=').map(link => link.href)).toEqual([`/help?page=${INDUSTRY_STRUCTURES_PAGE}`, '/help?page=limits/esi-delay'])
    expect(occurrencesOf(text, expectedMessage('fr', 'help.emptySection'))).toBe(3)
  })

  it('switches the index to English without reloading when the language changes', async () => {
    const { wrapper, i18n } = await mountHelpAt('/help', 'fr')

    i18n.global.locale.value = 'en'
    await nextTick()

    const text = wrapper.text()
    const [faq, playbooks, calculations, data, limits] = sectionTitles('en').map(title => text.indexOf(title))
    expectIncreasing([faq, playbooks, text.indexOf(TITLES.en.structures), calculations, data, limits, text.indexOf(TITLES.en.delay)])
    expect(text).not.toContain(TITLES.fr.structures)
    expect(occurrencesOf(text, expectedMessage('en', 'help.emptySection'))).toBe(3)
  })

  it('shows the requested page with a title heading and a breadcrumb, then the other language on a language change', async () => {
    const { wrapper, i18n } = await mountHelpAt(`/help?page=${INDUSTRY_STRUCTURES_PAGE}`, 'fr')

    const frenchHeadings = wrapper.findAll('h1').map(heading => heading.text())
    expect(frenchHeadings).toContain(TITLES.fr.structures)
    expect(wrapper.text()).toContain('Texte du guide en français.')
    expect(wrapper.text()).toContain(expectedMessage('fr', 'help.sections.playbooks.title'))
    expect(decodedHrefs(wrapper, '/help').filter(link => link.href === '/help')).not.toEqual([])

    i18n.global.locale.value = 'en'
    await nextTick()

    expect(wrapper.findAll('h1').map(heading => heading.text())).toContain(TITLES.en.structures)
    expect(wrapper.text()).toContain('Guide text in English.')
    expect(wrapper.text()).not.toContain('Texte du guide en français.')
  })

  it.each([
    'playbooks/nope',
    'unknown/industry-structures',
    'playbooks/..',
    '../playbooks/industry-structures',
  ])('shows the page not found message and a link to the index for the page %s', async page => {
    const { wrapper } = await mountHelpAt(`/help?page=${encodeURIComponent(page)}`, 'en')

    expect(wrapper.text()).toContain(expectedMessage('en', 'help.pageNotFound'))
    expect(decodedHrefs(wrapper, '/help').filter(link => link.href === '/help')).not.toEqual([])
    expect(wrapper.findAll('h1').map(heading => heading.text())).not.toContain(TITLES.en.structures)
  })
})
