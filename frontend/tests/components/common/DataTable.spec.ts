import { mount } from '@vue/test-utils'
import DataTable from '@/components/common/DataTable.vue'

const SCROLL_CONTAINER_CLASS = 'overflow-x-auto'
const STICKY_HEADER_CLASS = 'data-table-sticky-header'

const MATERIALS_TABLE =
  '<table id="materials-table" class="w-full text-sm">'
  + '<thead><tr><th class="text-left">Material</th><th class="text-right tabular-nums">Quantity</th></tr></thead>'
  + '<tbody><tr><td class="text-left">Tritanium</td><td class="text-right tabular-nums">12500</td></tr>'
  + '<tr><td class="text-left">Pyerite</td><td class="text-right tabular-nums">3200</td></tr></tbody>'
  + '</table>'

function mountDataTable(props: { stickyHeader?: boolean } = {}) {
  return mount(DataTable, { props, slots: { default: MATERIALS_TABLE } })
}

describe('DataTable', () => {
  it('puts the slotted table in its own horizontally scrollable container', () => {
    const wrapper = mountDataTable()

    expect(wrapper.get('#materials-table').element.closest(`.${SCROLL_CONTAINER_CLASS}`)).toBe(wrapper.element)
  })

  it('makes the header sticky by default', () => {
    const wrapper = mountDataTable()

    expect(wrapper.classes()).toEqual(expect.arrayContaining([SCROLL_CONTAINER_CLASS, STICKY_HEADER_CLASS]))
  })

  it('keeps the scrollable container without a sticky header when stickyHeader is false', () => {
    const wrapper = mountDataTable({ stickyHeader: false })

    expect(wrapper.classes()).toContain(SCROLL_CONTAINER_CLASS)
    expect(wrapper.classes()).not.toContain(STICKY_HEADER_CLASS)
  })

  it('removes then restores the sticky header when stickyHeader changes', async () => {
    const wrapper = mountDataTable({ stickyHeader: true })

    await wrapper.setProps({ stickyHeader: false })
    expect(wrapper.classes()).not.toContain(STICKY_HEADER_CLASS)

    await wrapper.setProps({ stickyHeader: true })
    expect(wrapper.classes()).toContain(STICKY_HEADER_CLASS)
  })

  it('renders the slotted table as is', () => {
    const wrapper = mountDataTable()

    expect(wrapper.get('#materials-table').element.outerHTML).toBe(MATERIALS_TABLE)
  })
})
