import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectDiscordRecap from '@/components/group-industry/ProjectDiscordRecap.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { Distribution, GroupProject } from '@/stores/group-industry/types'
import { messageOf, type TestLocale } from '../../localeTexts'
import {
  DISCORD_MESSAGE_LIMIT,
  completedProject,
  memberDistribution,
  profitableDistribution,
} from './projectDiscordRecapFixtures'

const TEXT = '[data-test="discord-recap-text"]'
const COPY = '[data-test="copy-discord-recap"]'
const LENGTH = '[data-test="discord-recap-length"]'
const COPY_FAILED = '[data-test="discord-recap-copy-failed"]'

const clipboard = { writeText: vi.fn() }

function mountRecap(project: GroupProject | null, distribution: Distribution | null, locale: TestLocale = 'en'): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().currentProject = project
  useGroupDistributionStore().distribution = distribution
  return mount(ProjectDiscordRecap, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

beforeEach(() => {
  clipboard.writeText = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true })
})

afterEach(() => {
  vi.restoreAllMocks()
  Reflect.deleteProperty(navigator, 'clipboard')
})

enableAutoUnmount(afterEach)

describe('ProjectDiscordRecap', () => {
  it('copies exactly the shown text to the clipboard and shows the button as copied', async () => {
    const wrapper = mountRecap(completedProject(), profitableDistribution())
    const shownText = wrapper.get(TEXT).element.textContent ?? ''

    expect(wrapper.get(COPY).text()).toBe(messageOf('en', 'groupIndustry.discordRecap.copy'))
    await wrapper.get(COPY).trigger('click')
    await flushPromises()

    expect(shownText).toContain('Sabre run')
    expect(clipboard.writeText.mock.calls).toEqual([[shownText]])
    expect(wrapper.get(COPY).text()).toBe(messageOf('en', 'groupIndustry.discordRecap.copied'))
  })

  it('tells the user when the clipboard refuses the copy instead of showing it as copied', async () => {
    clipboard.writeText = vi.fn().mockRejectedValue(new Error('denied'))
    const wrapper = mountRecap(completedProject(), profitableDistribution())

    await wrapper.get(COPY).trigger('click')
    await flushPromises()

    expect({ failure: wrapper.get(COPY_FAILED).text(), label: wrapper.get(COPY).text() }).toEqual({
      failure: messageOf('en', 'groupIndustry.discordRecap.copyFailed'),
      label: messageOf('en', 'groupIndustry.discordRecap.copy'),
    })
  })

  it('shows the message length against the Discord limit and flags a message that is too long', () => {
    const manyMembers = Array.from({ length: 60 }, (_, index) => memberDistribution({ memberId: `member-${index}`, characterName: `Pilot number ${index}` }))
    const short = mountRecap(completedProject(), profitableDistribution())
    const shortText = short.get(TEXT).element.textContent ?? ''
    const shortState = { length: short.get(LENGTH).text(), overLimit: short.get(LENGTH).attributes('data-over-limit') }

    const long = mountRecap(completedProject(), profitableDistribution({ members: manyMembers }))
    const longText = long.get(TEXT).element.textContent ?? ''
    const longState = { length: long.get(LENGTH).text(), overLimit: long.get(LENGTH).attributes('data-over-limit') }

    expect({ shortState, longState }).toEqual({
      shortState: { length: `${shortText.length} / ${DISCORD_MESSAGE_LIMIT}`, overLimit: 'false' },
      longState: { length: `${longText.length} / ${DISCORD_MESSAGE_LIMIT}`, overLimit: 'true' },
    })
    expect(longText.length).toBeGreaterThan(DISCORD_MESSAGE_LIMIT)
  })

  it('labels the copy button in French when the locale is French', () => {
    const wrapper = mountRecap(completedProject(), profitableDistribution(), 'fr')

    expect(wrapper.get(COPY).text()).toBe(messageOf('fr', 'groupIndustry.discordRecap.copy'))
    expect(wrapper.get(TEXT).element.textContent).toContain('terminé')
  })

  it.each([
    ['a project still in progress', completedProject({ status: 'selling' }), profitableDistribution()],
    ['no loaded project', null, profitableDistribution()],
    ['no loaded distribution', completedProject(), null],
  ])('shows nothing for %s', (_label, project, distribution) => {
    const wrapper = mountRecap(project, distribution)

    expect(wrapper.find(TEXT).exists()).toBe(false)
    expect(wrapper.find(COPY).exists()).toBe(false)
  })
})
