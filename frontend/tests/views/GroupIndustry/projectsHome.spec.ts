import { describe, expect, it } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import { PROJECTS_HOME } from '@/views/GroupIndustry/projectsHome'

describe('PROJECTS_HOME', () => {
  it('resolves to the projects page of the industry module', () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/industry', name: 'industry', component: {} },
        { path: '/industry/projects', name: 'industry-projects', component: {} },
      ],
    })

    expect(router.resolve(PROJECTS_HOME).fullPath).toBe('/industry/projects')
  })
})
