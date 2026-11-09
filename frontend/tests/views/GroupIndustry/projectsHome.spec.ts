import { describe, expect, it } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import { PROJECTS_HOME } from '@/views/GroupIndustry/projectsHome'

describe('PROJECTS_HOME', () => {
  it('resolves to the workshop tab of the industry module', () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/industry', name: 'industry', component: {} },
        { path: '/group-industry', name: 'group-industry', component: {} },
      ],
    })

    expect(router.resolve(PROJECTS_HOME).fullPath).toBe('/industry?tab=workshop')
  })
})
