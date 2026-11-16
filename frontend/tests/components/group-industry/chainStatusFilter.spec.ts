import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ChainStatusFilter from '@/components/group-industry/ChainStatusFilter.vue'
import ProductionChainFlow from '@/components/group-industry/ProductionChainFlow.vue'
import ProductionChainJobTree from '@/components/group-industry/ProductionChainJobTree.vue'
import {
  CHAIN_STATUS_FILTER_STORAGE_KEY,
  filterChainNodes,
  recallChainStatusFilter,
  rememberChainStatusFilter,
  toggleChainStatus,
} from '@/components/group-industry/chainStatusFilter'
import type { ChainStatus } from '@/stores/group-industry/types'
import { chainNode } from '../../productionChainNodes'

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

const NODES = [
  chainNode({ id: 'todo-1', stage: 'component', level: 1, typeName: 'Alpha', activity: 'manufacturing', status: 'todo' }),
  chainNode({ id: 'todo-2', stage: 'product', level: 2, typeName: 'Beta', activity: 'manufacturing', status: 'todo' }),
  chainNode({ id: 'active-1', stage: 'component', level: 1, typeName: 'Gamma', activity: 'reaction', status: 'active' }),
  chainNode({ id: 'done-1', stage: 'component', level: 1, typeName: 'Delta', activity: 'manufacturing', status: 'done' }),
]

describe('filterChainNodes', () => {
  it('keeps only the nodes whose status is selected', () => {
    expect(filterChainNodes(NODES, ['todo']).map(node => node.id)).toEqual(['todo-1', 'todo-2'])
  })

  it('keeps the nodes of every selected status', () => {
    expect(filterChainNodes(NODES, ['todo', 'active']).map(node => node.id)).toEqual(['todo-1', 'todo-2', 'active-1'])
  })

  it('keeps every node when no status is selected', () => {
    expect(filterChainNodes(NODES, [])).toHaveLength(NODES.length)
  })
})

describe('toggleChainStatus', () => {
  it('adds a status that is not selected and removes one that is', () => {
    expect(toggleChainStatus([], 'todo')).toEqual(['todo'])
    expect(toggleChainStatus(['todo', 'active'], 'todo')).toEqual(['active'])
  })
})

describe('chain status filter memory', () => {
  beforeEach(() => localStorage.clear())

  it('restores the statuses remembered by the viewer', () => {
    rememberChainStatusFilter(['todo', 'blocked'])
    expect(recallChainStatusFilter()).toEqual(['todo', 'blocked'])
  })

  it('starts empty when nothing is remembered', () => {
    expect(recallChainStatusFilter()).toEqual([])
  })

  it('ignores a stored value that is not a list of known statuses', () => {
    localStorage.setItem(CHAIN_STATUS_FILTER_STORAGE_KEY, JSON.stringify(['todo', 'nonsense']))
    expect(recallChainStatusFilter()).toEqual([])
    localStorage.setItem(CHAIN_STATUS_FILTER_STORAGE_KEY, '{broken')
    expect(recallChainStatusFilter()).toEqual([])
  })
})

describe('ChainStatusFilter', () => {
  function mountFilter(modelValue: ChainStatus[]) {
    return mount(ChainStatusFilter, { props: { nodes: NODES, modelValue }, global: { plugins: [i18n()] } })
  }

  it('shows the count of steps per status', () => {
    const wrapper = mountFilter([])
    expect(wrapper.get('[data-testid="chain-status-filter-todo"]').text()).toContain('2')
    expect(wrapper.get('[data-testid="chain-status-filter-active"]').text()).toContain('1')
  })

  it('emits the toggled selection when a status is clicked', async () => {
    const wrapper = mountFilter(['active'])
    await wrapper.get('[data-testid="chain-status-filter-todo"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['active', 'todo']])
  })

  it('marks the selected statuses as pressed', () => {
    const wrapper = mountFilter(['todo'])
    expect(wrapper.get('[data-testid="chain-status-filter-todo"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid="chain-status-filter-done"]').attributes('aria-pressed')).toBe('false')
  })

  it('clears the selection', async () => {
    const wrapper = mountFilter(['todo'])
    await wrapper.get('[data-testid="chain-status-filter-clear"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([[]])
  })

  it('offers no clear button while nothing is selected', () => {
    expect(mountFilter([]).find('[data-testid="chain-status-filter-clear"]').exists()).toBe(false)
  })
})

describe('job tree status filter', () => {
  function mountTree(statusFilter?: ChainStatus[]) {
    return mount(ProductionChainJobTree, { props: { nodes: NODES, selectedId: null, statusFilter }, global: { plugins: [i18n()] } })
  }

  it('lists only the steps of the selected status', () => {
    const wrapper = mountTree(['todo'])
    expect(wrapper.find('[data-testid="chain-row-todo-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chain-row-todo-2"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chain-row-active-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="chain-row-done-1"]').exists()).toBe(false)
  })

  it('counts the jobs of a level among the visible steps only', () => {
    const wrapper = mountTree(['todo'])
    expect(wrapper.get('[data-testid="chain-level-1"]').text()).toContain('1 job')
  })

  it('drops the level header of a level without any matching step', () => {
    const wrapper = mountTree(['active'])
    expect(wrapper.find('[data-testid="chain-level-2"]').exists()).toBe(false)
  })

  it('says so when no step matches the filter', () => {
    const wrapper = mountTree(['blocked'])
    expect(wrapper.find('[data-testid="chain-row-todo-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="chain-filter-empty"]').exists()).toBe(true)
  })

  it('lists every step without a filter', () => {
    const wrapper = mountTree(undefined)
    expect(wrapper.findAll('[data-testid^="chain-row-"]')).toHaveLength(NODES.length)
    expect(wrapper.find('[data-testid="chain-filter-empty"]').exists()).toBe(false)
  })

  it('lists every step when the filter is empty', () => {
    expect(mountTree([]).findAll('[data-testid^="chain-row-"]')).toHaveLength(NODES.length)
  })
})

describe('overview status filter', () => {
  function mountFlow(statusFilter?: ChainStatus[]) {
    return mount(ProductionChainFlow, { props: { nodes: NODES, statusFilter }, global: { plugins: [i18n()] } })
  }

  it('shows only the cards of the selected status', () => {
    const wrapper = mountFlow(['active'])
    expect(wrapper.text()).toContain('Gamma')
    expect(wrapper.text()).not.toContain('Alpha')
    expect(wrapper.text()).not.toContain('Beta')
  })

  it('keeps showing every card without a filter', () => {
    const wrapper = mountFlow(undefined)
    for (const name of ['Alpha', 'Beta', 'Gamma', 'Delta']) expect(wrapper.text()).toContain(name)
  })
})
