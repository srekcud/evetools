import { enableAutoUnmount } from '@vue/test-utils'
import FactoryBlueprints from '@/components/industry-factory/FactoryBlueprints.vue'
import FactoryPersonalStock from '@/components/industry-factory/FactoryPersonalStock.vue'
import FactoryPlayerLines from '@/components/industry-factory/FactoryPlayerLines.vue'
import FactoryProjectStock from '@/components/industry-factory/FactoryProjectStock.vue'
import FactoryStationsTable from '@/components/industry-factory/FactoryStationsTable.vue'
import FactoryStockpile from '@/components/industry-factory/FactoryStockpile.vue'
import IndustryFactory from '@/views/IndustryFactory.vue'
import { mountFactory } from '../components/industry-factory/mountFactory'

enableAutoUnmount(afterEach)

describe('IndustryFactory page', () => {
  it('stacks the six sections in the order of the prototype', async () => {
    const { wrapper } = await mountFactory(IndustryFactory, { stubs: { IndustryModuleTabs: true } })

    const sections = [
      FactoryStationsTable,
      FactoryStockpile,
      FactoryProjectStock,
      FactoryPlayerLines,
      FactoryBlueprints,
      FactoryPersonalStock,
    ].map(section => wrapper.findComponent(section))
    expect(sections.map(section => section.exists())).toEqual([true, true, true, true, true, true])
    const follows = (before: number, after: number) =>
      Boolean(sections[before].element.compareDocumentPosition(sections[after].element) & Node.DOCUMENT_POSITION_FOLLOWING)
    expect([0, 1, 2, 3, 4].map(index => follows(index, index + 1))).toEqual([true, true, true, true, true])
  })
})
