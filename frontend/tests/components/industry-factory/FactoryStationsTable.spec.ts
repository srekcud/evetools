import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import FactoryStationsTable from '@/components/industry-factory/FactoryStationsTable.vue'
import { useFactoryStore } from '@/stores/industry/factory'
import { RAITARU_ALPHA, STATIONS_URL, UNLOCATED_BETA } from '../../industryFactoryFixtures'
import { isUnknown, mountFactory } from './mountFactory'

enableAutoUnmount(afterEach)

async function mountStations() {
  return mountFactory(FactoryStationsTable, {
    answers: { [`GET ${STATIONS_URL}`]: [RAITARU_ALPHA, UNLOCATED_BETA] },
    seed: () => {
      useFactoryStore().stations = [RAITARU_ALPHA, UNLOCATED_BETA]
    },
  })
}

function stationRows(wrapper: VueWrapper) {
  const rows = wrapper.findAll('[data-testid="factory-station-row"]')
  expect(rows).toHaveLength(2)
  return rows
}

describe('FactoryStationsTable', () => {
  it('shows the unknown label, never a zero, for a missing cost index and unknown lines in use', async () => {
    const { wrapper } = await mountStations()

    const unlocated = stationRows(wrapper)[1]
    expect(isUnknown(unlocated.get('[data-testid="factory-station-index"]'))).toBe(true)
    expect(isUnknown(unlocated.get('[data-testid="factory-station-lines"]'))).toBe(true)
    expect(unlocated.get('[data-testid="factory-station-index"]').text()).not.toMatch(/\b0\b/)
    expect(unlocated.get('[data-testid="factory-station-lines"]').text()).not.toMatch(/\b0\b/)
  })

  it('shows the known cost index, the lines in use, one access marker per character and the linked open projects of a located station', async () => {
    const { wrapper } = await mountStations()

    const raitaru = stationRows(wrapper)[0]
    const index = raitaru.get('[data-testid="factory-station-index"]')
    const lines = raitaru.get('[data-testid="factory-station-lines"]')
    expect(isUnknown(index)).toBe(false)
    expect(isUnknown(lines)).toBe(false)
    expect(index.text()).toMatch(/4[.,]1/)
    expect(lines.text()).toContain('3')
    expect(raitaru.text()).toContain('Raitaru Alpha')
    const markers = raitaru.get('[data-testid="factory-station-access"]').findAll('[data-status]')
    expect(markers.map(marker => marker.attributes('data-status'))).toEqual(['granted', 'denied', 'unknown'])
    expect(markers.map(marker => marker.attributes('data-character-id'))).toEqual(['2131400001', '2131400002', '2131400003'])
    const links = raitaru.findAll('a').filter(link => link.text().includes('batch'))
    expect(links.map(link => link.text())).toEqual(['Rifter batch', 'Merlin batch'])
    expect(links.map(link => link.attributes('href'))).toEqual([
      '/industry/projects/0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4f01',
      '/industry/projects/0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4f02',
    ])
  })
})
