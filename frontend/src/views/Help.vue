<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { HELP_SECTIONS, helpCatalog, type HelpLanguage, type HelpPage } from '@/help/helpCatalog'
import { renderHelpMarkdown } from '@/help/renderHelpMarkdown'

const ARTICLE_CLASS = [
  'text-slate-300 leading-relaxed',
  '[&_h1]:text-3xl [&_h1]:font-bold [&_h1]:text-slate-100 [&_h1]:mb-6 [&_h1]:pb-3 [&_h1]:border-b [&_h1]:border-cyan-500/30',
  '[&_h2]:text-2xl [&_h2]:font-bold [&_h2]:text-slate-100 [&_h2]:mt-10 [&_h2]:mb-4 [&_h2]:scroll-mt-20',
  '[&_h3]:text-lg [&_h3]:font-semibold [&_h3]:text-slate-200 [&_h3]:mt-8 [&_h3]:mb-3 [&_h3]:scroll-mt-20',
  '[&_h4]:font-semibold [&_h4]:text-slate-200 [&_h4]:mt-6 [&_h4]:mb-2 [&_h4]:scroll-mt-20',
  '[&_p]:mb-4 [&_p]:text-slate-400',
  '[&_ul]:list-disc [&_ul]:pl-6 [&_ul]:mb-4 [&_ul]:space-y-1.5 [&_ul]:text-slate-400',
  '[&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:mb-4 [&_ol]:space-y-1.5 [&_ol]:text-slate-400',
  '[&_a]:text-cyan-400 [&_a:hover]:text-cyan-300 [&_a]:transition-colors',
  '[&_strong]:text-slate-200',
  '[&_code]:font-mono [&_code]:text-sm [&_code]:text-cyan-300 [&_code]:bg-slate-800 [&_code]:px-1 [&_code]:rounded',
  '[&_pre]:bg-slate-950 [&_pre]:border [&_pre]:border-slate-800 [&_pre]:rounded-lg [&_pre]:p-4 [&_pre]:mb-4 [&_pre]:overflow-x-auto',
  '[&_blockquote]:border-l-2 [&_blockquote]:border-cyan-500/40 [&_blockquote]:pl-4 [&_blockquote]:mb-4 [&_blockquote]:text-slate-400',
  '[&_table]:w-full [&_table]:mb-4 [&_table]:text-sm [&_table]:border-collapse',
  '[&_th]:text-left [&_th]:text-slate-200 [&_th]:font-semibold [&_th]:border-b [&_th]:border-slate-700 [&_th]:px-3 [&_th]:py-2',
  '[&_td]:border-b [&_td]:border-slate-800 [&_td]:px-3 [&_td]:py-2 [&_td]:text-slate-400',
  '[&_hr]:border-slate-800 [&_hr]:my-8',
].join(' ')

const { t, locale } = useI18n()
const route = useRoute()

const languageCatalog = computed(() => helpCatalog[locale.value as HelpLanguage])
const requestedPage = computed(() => (typeof route.query.page === 'string' ? route.query.page : null))

const currentPage = computed<HelpPage | null>(() => {
  if (requestedPage.value === null) {
    return null
  }
  const [section, slug, ...rest] = requestedPage.value.split('/')
  if (rest.length > 0) {
    return null
  }
  return HELP_SECTIONS
    .filter(name => name === section)
    .flatMap(name => languageCatalog.value[name])
    .find(page => page.slug === slug) ?? null
})

const isPageNotFound = computed(() => requestedPage.value !== null && currentPage.value === null)
const renderedPage = computed(() => (currentPage.value === null ? '' : renderHelpMarkdown(currentPage.value.source)))

function pageQuery(page: HelpPage): { page: string } {
  return { page: `${page.section}/${page.slug}` }
}

async function scrollToHash(): Promise<void> {
  if (route.hash === '' || currentPage.value === null) {
    return
  }
  await nextTick()
  document.getElementById(decodeURIComponent(route.hash.slice(1)))?.scrollIntoView({ behavior: 'smooth' })
}

watch([() => route.hash, currentPage], scrollToHash, { immediate: true, flush: 'post' })
</script>

<template>
  <div class="min-h-screen bg-slate-900 text-slate-300">
    <header class="sticky top-0 z-10 bg-slate-900/95 backdrop-blur border-b border-slate-800">
      <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
        <RouterLink
          to="/"
          class="flex items-center gap-2 text-sm text-cyan-400 hover:text-cyan-300 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          {{ t('help.backToApp') }}
        </RouterLink>
        <span class="text-sm font-mono uppercase tracking-wider text-slate-400">{{ t('help.title') }}</span>
      </div>
    </header>

    <main class="max-w-4xl mx-auto px-6 py-12">
      <template v-if="currentPage">
        <nav :aria-label="t('help.breadcrumbLabel')" class="mb-8 flex flex-wrap items-center gap-2 text-sm text-slate-400">
          <RouterLink :to="{ name: 'help' }" class="text-cyan-400 hover:text-cyan-300 transition-colors">
            {{ t('help.title') }}
          </RouterLink>
          <span class="text-slate-600" aria-hidden="true">›</span>
          <span>{{ t(`help.sections.${currentPage.section}.title`) }}</span>
          <span class="text-slate-600" aria-hidden="true">›</span>
          <span class="text-slate-200" aria-current="page">{{ currentPage.title }}</span>
        </nav>
        <article :class="ARTICLE_CLASS" v-html="renderedPage" />
      </template>

      <div v-else-if="isPageNotFound" class="rounded-lg border border-slate-800 bg-slate-950/50 p-6">
        <p class="text-slate-300 mb-4">{{ t('help.pageNotFound') }}</p>
        <RouterLink :to="{ name: 'help' }" class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors">
          {{ t('help.backToIndex') }}
        </RouterLink>
      </div>

      <template v-else>
        <h1 class="text-3xl font-bold text-slate-100 mb-10 pb-3 border-b border-cyan-500/30">{{ t('help.title') }}</h1>
        <section v-for="section in HELP_SECTIONS" :key="section" class="mb-10">
          <h2 class="text-xl font-semibold text-slate-100 mb-4">{{ t(`help.sections.${section}.title`) }}</h2>
          <ul v-if="languageCatalog[section].length > 0" class="space-y-2">
            <li v-for="page in languageCatalog[section]" :key="page.slug">
              <RouterLink
                :to="{ name: 'help', query: pageQuery(page) }"
                class="text-cyan-400 hover:text-cyan-300 transition-colors"
              >
                {{ page.title }}
              </RouterLink>
            </li>
          </ul>
          <p v-else class="text-sm text-slate-400">{{ t('help.emptySection') }}</p>
        </section>
      </template>
    </main>
  </div>
</template>
