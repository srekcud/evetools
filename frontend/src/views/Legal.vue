<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { APP_VERSION } from '@/version'
</script>

<template>
  <div class="min-h-screen bg-slate-900 text-slate-300">
    <!-- Header -->
    <header class="sticky top-0 z-10 bg-slate-900/95 backdrop-blur border-b border-slate-800">
      <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
        <RouterLink
          to="/login"
          class="flex items-center gap-2 text-sm text-cyan-400 hover:text-cyan-300 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to app
        </RouterLink>
        <nav class="flex items-center gap-4 text-sm">
          <a href="#legal-notice" class="text-slate-400 hover:text-slate-200 transition-colors">Legal Notice</a>
          <span class="text-slate-700">|</span>
          <a href="#privacy-policy" class="text-slate-400 hover:text-slate-200 transition-colors">Privacy Policy</a>
          <span class="text-slate-700">|</span>
          <a href="#ccp-disclaimer" class="text-slate-400 hover:text-slate-200 transition-colors">CCP Disclaimer</a>
        </nav>
      </div>
    </header>

    <!-- Content -->
    <main class="max-w-4xl mx-auto px-6 py-12">

      <!-- Section 1: Legal Notice -->
      <section id="legal-notice" class="mb-16 scroll-mt-20">
        <h2 class="text-2xl font-bold text-slate-100 mb-8 pb-3 border-b border-cyan-500/30">
          Legal Notice
        </h2>

        <div class="space-y-6">
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">Publisher</h3>
            <ul class="space-y-1.5 text-slate-400 leading-relaxed">
              <li><span class="text-slate-500">Name:</span> Sylvain (personal non-commercial project)</li>
              <li><span class="text-slate-500">Contact:</span> contact@evetools.example.com</li>
              <li><span class="text-slate-500">Status:</span> Personal non-commercial website</li>
            </ul>
          </div>

          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">Hosting</h3>
            <ul class="space-y-1.5 text-slate-400 leading-relaxed">
              <li><span class="text-slate-500">Company:</span> OVH SAS</li>
              <li><span class="text-slate-500">Address:</span> 2 rue Kellermann, 59100 Roubaix, France</li>
              <li>
                <span class="text-slate-500">Website:</span>
                <a href="https://www.ovhcloud.com" target="_blank" rel="noopener noreferrer" class="text-cyan-400 hover:text-cyan-300 transition-colors">https://www.ovhcloud.com</a>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Section 2: Privacy Policy -->
      <section id="privacy-policy" class="mb-16 scroll-mt-20">
        <h2 class="text-2xl font-bold text-slate-100 mb-8 pb-3 border-b border-cyan-500/30">
          Privacy Policy
        </h2>

        <p class="text-sm text-slate-500 mb-8">Effective date: February 2026</p>

        <div class="space-y-8">
          <!-- 1. Data Controller -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">1. Data Controller</h3>
            <p class="text-slate-400 leading-relaxed">
              Sylvain (personal non-commercial project).
              Contact: contact@evetools.example.com
            </p>
          </div>

          <!-- 2. Data Collected -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">2. Data Collected</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li><span class="font-medium text-slate-300">EVE Online identity:</span> character name, character ID, corporation, alliance (provided via EVE SSO OAuth2)</li>
              <li><span class="font-medium text-slate-300">EVE SSO access token</span> (encrypted in database)</li>
              <li><span class="font-medium text-slate-300">In-game data:</span> assets, industry jobs, wallet transactions, mining ledger, planetary colonies, escalations, skills, blueprints</li>
              <li><span class="font-medium text-slate-300">User preferences:</span> language, display settings, industry configuration</li>
              <li><span class="font-medium text-slate-300">Account metadata:</span> creation date, last login date</li>
            </ul>
          </div>

          <!-- 3. Data NOT Collected -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">3. Data NOT Collected</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li>No personal email address</li>
              <li>No payment information</li>
              <li>No tracking cookies or advertising cookies</li>
              <li>No analytics tools (no Google Analytics, no third-party trackers)</li>
              <li>No data shared with third parties</li>
            </ul>
          </div>

          <!-- 4. Legal Basis -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">4. Legal Basis (GDPR Article 6)</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li><span class="font-medium text-slate-300">Consent:</span> by connecting your EVE Online account via SSO, you consent to the processing of associated game data</li>
              <li><span class="font-medium text-slate-300">Legitimate interest:</span> technical operation of the service (authentication, caching, synchronization)</li>
            </ul>
          </div>

          <!-- 5. Purpose -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">5. Purpose</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li>Provide application features (industry management, mining, PVE, planetary interaction, escalations)</li>
              <li>Synchronize data from EVE Online's ESI API</li>
              <li>No commercial use, no data resale</li>
            </ul>
          </div>

          <!-- 6. Data Recipients -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">6. Data Recipients</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li>Data is stored on OVH servers in France (EU)</li>
              <li>Game data is exchanged with CCP Games' ESI API (Iceland/EU)</li>
              <li>No transfer to third parties</li>
            </ul>
          </div>

          <!-- 7. Data Retention -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">7. Data Retention</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li>Data is retained as long as your account is active</li>
              <li>Upon account deletion: immediate erasure of all associated data</li>
            </ul>
          </div>

          <!-- 8. Browser Storage -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">8. Browser Storage</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li><span class="font-medium text-slate-300">JWT token</span> (authentication): localStorage, cleared on logout</li>
              <li><span class="font-medium text-slate-300">Display preferences:</span> localStorage, strictly functional</li>
              <li><span class="font-medium text-slate-300">Temporary OAuth data:</span> sessionStorage, automatically cleared</li>
              <li>No tracking cookies</li>
            </ul>
          </div>

          <!-- 9. Your Rights -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">9. Your Rights (GDPR Articles 15-21)</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li><span class="font-medium text-slate-300">Right of access:</span> view your data</li>
              <li><span class="font-medium text-slate-300">Right to rectification:</span> game data is automatically updated from ESI</li>
              <li><span class="font-medium text-slate-300">Right to erasure:</span> delete your account and all associated data</li>
              <li><span class="font-medium text-slate-300">Right to data portability:</span> export your data upon request</li>
              <li><span class="font-medium text-slate-300">Right to object:</span> revoke OAuth access via the EVE Online website</li>
            </ul>
            <div class="mt-4 p-4 bg-slate-800/50 rounded-lg border border-slate-700">
              <p class="text-slate-400 text-sm leading-relaxed">
                <span class="font-medium text-slate-300">Contact:</span> contact@evetools.example.com
              </p>
              <p class="text-slate-400 text-sm leading-relaxed mt-2">
                <span class="font-medium text-slate-300">Supervisory authority:</span>
                Autorite de protection des donnees (APD/GBA), Rue de la Presse 35, 1000 Brussels, Belgium
                &mdash;
                <a href="https://www.autoriteprotectiondonnees.be" target="_blank" rel="noopener noreferrer" class="text-cyan-400 hover:text-cyan-300 transition-colors">https://www.autoriteprotectiondonnees.be</a>
              </p>
            </div>
          </div>

          <!-- 10. Security -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">10. Security</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li>OAuth tokens encrypted in database</li>
              <li>All communications encrypted (HTTPS/TLS)</li>
              <li>JWT authentication with expiration</li>
              <li>No passwords stored (delegated to EVE SSO)</li>
            </ul>
          </div>

          <!-- 11. Changes -->
          <div>
            <h3 class="text-lg font-semibold text-slate-200 mb-3">11. Changes to This Policy</h3>
            <ul class="list-disc list-inside space-y-1.5 text-slate-400 leading-relaxed">
              <li>Any changes will be posted on this page</li>
              <li>Continued use after changes constitutes acceptance</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Section 3: CCP Games Disclaimer -->
      <section id="ccp-disclaimer" class="mb-16 scroll-mt-20">
        <h2 class="text-2xl font-bold text-slate-100 mb-8 pb-3 border-b border-cyan-500/30">
          CCP Games Disclaimer
        </h2>

        <div class="space-y-6 text-slate-400 leading-relaxed">
          <p>
            &copy; 2014 CCP hf. All rights reserved. "EVE", "EVE Online", "CCP", and all related logos and images are trademarks or registered trademarks of CCP hf.
          </p>
          <p>
            CCP hf. has granted permission to EVE Tools to use EVE Online and all associated logos and designs for promotional and information purposes on its website, but does not endorse, and is not in any way affiliated with, EVE Tools. CCP is in no way responsible for the content on or functioning of this website, nor can it be liable for any damage arising from the use of this website.
          </p>
          <p>
            All EVE Online game data is sourced from the EVE Swagger Interface (ESI) and the Static Data Export (SDE), provided by CCP Games under the Developer License Agreement.
          </p>
        </div>
      </section>
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-800 py-6 text-center text-xs text-slate-600">
      <p>EVE Tools v{{ APP_VERSION }}</p>
    </footer>
  </div>
</template>
