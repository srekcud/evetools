import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, companionTasksOf, jobMatch } from './companionFixtures'

enableAutoUnmount(afterEach)

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000

function jobEntry(id: string, status: string, endOffsetMs: number) {
  const item = bomLine({ id: `bom-${id}`, typeId: 12_000 + id.length, typeName: `Job ${id}`, isJob: true, activityType: 'manufacturing', runs: 4 })
  const match = jobMatch({ id: `match-${id}`, bomLineId: item.id, status, esiJobId: id.length, characterName: 'Alpha', endDate: new Date(NOW + endOffsetMs).toISOString() })
  return { match, item }
}

function mountCompanion(tasks: CompanionTasks, locale: 'en' | 'fr'): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function statusLabelsOf(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="companion-job"]').map(line => line.get('[data-testid="companion-job-status"]').text())
}

const jobs = [
  jobEntry('past-end', 'active', -ONE_MINUTE),
  jobEntry('esi-ready', 'ready', -ONE_MINUTE),
  jobEntry('future-end', 'active', ONE_HOUR),
]

describe('GroupCompanion job status line', () => {
  it('labels an active job past its end date and a ready job as ready to deliver, and a job still running as running', () => {
    const wrapper = mountCompanion(companionTasksOf({ jobs, readyJobCount: 2 }), 'en')

    expect(statusLabelsOf(wrapper)).toEqual(['Ready to deliver', 'Ready to deliver', 'Running'])
  })

  it('labels the same jobs in French', () => {
    const wrapper = mountCompanion(companionTasksOf({ jobs, readyJobCount: 2 }), 'fr')

    expect(statusLabelsOf(wrapper)).toEqual(['À livrer', 'À livrer', 'En cours'])
  })
})
