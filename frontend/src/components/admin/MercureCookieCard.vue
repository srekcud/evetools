<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AdminMercureCookie } from '@/stores/admin'

defineProps<{
  mercureCookie: AdminMercureCookie
}>()

const { t } = useI18n()
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.mercureCookie.title') }}</h3>
      <span
        data-testid="mercure-cookie-count"
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', mercureCookie.nearLimitCount > 0 ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400']"
      >{{ mercureCookie.nearLimitCount }}</span>
    </div>

    <p class="text-xs text-slate-500 mb-3">
      {{ t('admin.mercureCookie.threshold', { warning: mercureCookie.warningThresholdBytes, limit: mercureCookie.limitBytes }) }}
    </p>

    <table v-if="mercureCookie.users.length > 0" class="w-full text-sm">
      <thead>
        <tr class="text-slate-500 text-xs uppercase">
          <th class="text-left pb-2 font-medium">{{ t('admin.mercureCookie.user') }}</th>
          <th class="text-right pb-2 font-medium">{{ t('admin.mercureCookie.cookieSize') }}</th>
          <th class="text-right pb-2 font-medium">{{ t('admin.mercureCookie.topics') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr v-for="user in mercureCookie.users" :key="user.userId" data-testid="mercure-cookie-user">
          <td class="py-1.5 text-slate-300">{{ user.mainCharacterName ?? user.userId }}</td>
          <td class="py-1.5 text-right font-mono" :class="user.cookieBytes > mercureCookie.limitBytes ? 'text-red-400' : 'text-amber-400'">
            {{ user.cookieBytes }} / {{ mercureCookie.limitBytes }}
            <span
              v-if="user.cookieBytes > mercureCookie.limitBytes"
              data-testid="mercure-cookie-over-limit"
              class="ml-1 text-xs uppercase"
            >{{ t('admin.mercureCookie.overLimit') }}</span>
          </td>
          <td class="py-1.5 text-right font-mono text-slate-300">{{ user.topicCount }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else data-testid="mercure-cookie-empty" class="text-sm text-slate-500">{{ t('admin.mercureCookie.empty') }}</p>
  </div>
</template>
