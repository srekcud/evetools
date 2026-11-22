import { enableAutoUnmount, mount, RouterLinkStub } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import type { FlowGrid } from '@/components/group-industry/projectFlow'
import { JITA_ID, STATION_A_ID, flowGrid, flowRow } from './projectFlowFixtures'

enableAutoUnmount(afterEach)

function linksOf(grid: FlowGrid) {
  const wrapper = mount(ProjectFlowGrid, {
    props: { grid, personalUse: false },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  return wrapper.findAllComponents(RouterLinkStub).filter(link => link.attributes('data-testid') === 'flow-row-link')
}

describe('station link of a flow row', () => {
  it('opens the station view from a station row', () => {
    const links = linksOf(flowGrid([flowRow({ key: 'station:a', locationId: STATION_A_ID })]))

    expect(links.map(link => link.props('to'))).toEqual([{ name: 'industry-station', params: { locationId: String(STATION_A_ID) } }])
  })

  it('opens the station view from a market row', () => {
    const links = linksOf(flowGrid([flowRow({ key: 'market:jita', kind: 'market', locationId: JITA_ID, name: 'Jita' })]))

    expect(links.map(link => link.props('to'))).toEqual([{ name: 'industry-station', params: { locationId: String(JITA_ID) } }])
  })

  it('has no link for the row of an unresolved place', () => {
    const links = linksOf(flowGrid([flowRow({ key: 'unresolved', kind: 'unresolved', locationId: null, name: null })]))

    expect(links).toEqual([])
  })

  it('keeps the name of the station as the text of the link', () => {
    const links = linksOf(flowGrid([flowRow({ key: 'station:a', locationId: STATION_A_ID, name: 'Azbel' })]))

    expect(links.map(link => link.text())).toEqual(['Azbel'])
  })
})
