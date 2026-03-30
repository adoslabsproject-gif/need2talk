/**
 * EmotionalAnalytics.js - ENTERPRISE GALAXY V13.0
 *
 * EVIDENCE-BASED Emotional Analytics Dashboard
 *
 * SCIENTIFIC FOUNDATION:
 * 1. Russell's Circumplex Model (1980) - Valence + Arousal
 * 2. Emotional Granularity (Barrett, 2017)
 * 3. Emotions as Information (Schwarz & Clore, 1983)
 *
 * NO "Health Score" - not scientifically validated
 * NO value judgments - descriptive only
 *
 * @package need2talk/Lightning
 * @version 13.0.0
 */

(function() {
    'use strict';

    if (window.EmotionalAnalytics) {
        console.warn('[EmotionalAnalytics] Already initialized');
        return;
    }

    class EmotionalAnalytics {
        constructor() {
            this.container = null;
            this.data = null;
            this.charts = {};
            this.isLoading = false;
            this.chartJsLoaded = false;
        }

        async init() {
            this.container = document.getElementById('emotions-analytics-container');
            if (!this.container) {
                console.warn('[EmotionalAnalytics] Container not found');
                return;
            }

            this.showLoading();
            await this.loadChartJs();
            await this.fetchData();
            this.render();
        }

        async loadChartJs() {
            if (window.Chart) {
                this.chartJsLoaded = true;
                return;
            }

            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                script.onload = () => {
                    this.chartJsLoaded = true;
                    resolve();
                };
                script.onerror = () => reject(new Error('Chart.js load failed'));
                document.head.appendChild(script);
            });
        }

        async fetchData() {
            try {
                this.isLoading = true;
                const response = await fetch('/api/profile/emotional-analytics', {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });

                const json = await response.json();
                if (!json.success) throw new Error(json.message || 'API error');

                this.data = json.data;
                this.cacheInfo = json.cache_info;
            } catch (error) {
                console.error('[EmotionalAnalytics] Fetch failed:', error);
                this.data = null;
            } finally {
                this.isLoading = false;
            }
        }

        showLoading() {
            this.container.innerHTML = `
                <div class="flex items-center justify-center py-16">
                    <div class="animate-spin rounded-full h-12 w-12 border-4 border-purple-500 border-t-transparent"></div>
                    <span class="ml-4 text-gray-400">Caricamento analisi emozionale...</span>
                </div>
            `;
        }

        render() {
            if (!this.data) {
                this.renderError();
                return;
            }

            const { evoked_emotions, expressed_emotions, journal_emotions,
                    circumplex_position, granularity, mood_timeline,
                    patterns, circumplex_wheel, insights, scientific_basis } = this.data;

            // Check if there's enough data
            const hasData = (evoked_emotions?.total || 0) +
                           (expressed_emotions?.total || 0) +
                           (journal_emotions?.total || 0) > 0;

            if (!hasData) {
                this.renderEmptyState();
                return;
            }

            this.container.innerHTML = `
                <div class="emotional-analytics-dashboard space-y-6">
                    <!-- Cache Info Banner -->
                    ${this.renderCacheInfoBanner()}

                    <!-- Circumplex Position (Russell Model) -->
                    <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                        ${this.renderCircumplexPosition(circumplex_position)}
                    </section>

                    <!-- Emotional Granularity (Barrett) -->
                    <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                        ${this.renderGranularity(granularity)}
                    </section>

                    <!-- Two Column: Evoked + Expressed -->
                    <div class="grid md:grid-cols-2 gap-6">
                        <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                            ${this.renderEmotionSection(evoked_emotions, 'Emozioni Evocate', '🎵', 'Cosa provano gli altri ascoltandoti', 'purple')}
                        </section>
                        <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                            ${this.renderEmotionSection(expressed_emotions, 'Emozioni Espresse', '💭', 'Cosa esprimi agli altri', 'pink')}
                        </section>
                    </div>

                    <!-- Journal Emotions (if available) -->
                    ${journal_emotions?.total > 0 ? `
                    <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                        ${this.renderEmotionSection(journal_emotions, 'Emozioni dal Diario', '📔', 'Le tue riflessioni personali', 'indigo')}
                    </section>
                    ` : ''}

                    <!-- Circumplex Wheel -->
                    <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                        ${this.renderCircumplexWheel(circumplex_wheel)}
                    </section>

                    <!-- Valence-Arousal Timeline -->
                    <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                        ${this.renderMoodTimeline(mood_timeline)}
                    </section>

                    <!-- Patterns (Month Comparison) -->
                    ${patterns?.interpretation ? `
                    <section class="bg-gray-800/50 rounded-2xl p-6 border border-gray-700/50">
                        ${this.renderPatterns(patterns)}
                    </section>
                    ` : ''}

                    <!-- Insights -->
                    ${insights && insights.length > 0 ? `
                    <section class="bg-gradient-to-br from-purple-900/30 to-indigo-900/30 rounded-2xl p-6 border border-purple-500/30">
                        ${this.renderInsights(insights)}
                    </section>
                    ` : ''}

                    <!-- Scientific Disclaimer -->
                    <section class="bg-gray-900/50 rounded-xl p-4 border border-gray-800">
                        ${this.renderDisclaimer(scientific_basis)}
                    </section>
                </div>
            `;

            requestAnimationFrame(() => this.initCharts());
        }

        renderCacheInfoBanner() {
            const info = this.cacheInfo || {};
            return `
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-sm text-gray-400 bg-gray-900/50 rounded-lg px-4 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Aggiornamento automatico ogni 5 min</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs sm:text-sm">
                        <span class="text-gray-500">Ultimo:</span>
                        <span class="font-medium text-purple-400">${info.cached_at || '--:--'}</span>
                    </div>
                </div>
            `;
        }

        /**
         * Render Russell's Circumplex Position
         * ENTERPRISE V13.3: Clear readable design with prominent quadrants
         */
        renderCircumplexPosition(position) {
            if (!position) return '<div class="text-gray-400">Dati non disponibili</div>';

            const { valence, arousal, quadrant_info, interpretation } = position;
            const color = quadrant_info?.color || '#8B5CF6';

            // Determine which quadrant the user is in
            const quadrantName = quadrant_info?.name_it || 'Neutro';

            return `
                <div class="space-y-4">
                    <h3 class="text-lg sm:text-xl font-bold text-white flex flex-wrap items-center gap-1 sm:gap-2">
                        <span>📊</span>
                        <span>Mappa Emotiva</span>
                    </h3>

                    <!-- Main content: Quadrant grid + interpretation side by side on desktop -->
                    <div class="grid md:grid-cols-2 gap-4">

                        <!-- 2x2 Quadrant Grid - CLEAR VISUAL -->
                        <div class="grid grid-cols-2 gap-2">
                            <!-- Top-Left: Rabbia (high arousal, negative valence) -->
                            <div class="relative rounded-xl p-4 text-center transition-all ${valence < 0 && arousal > 0 ? 'bg-red-600/40 ring-2 ring-red-500' : 'bg-red-900/20'}">
                                <div class="text-3xl mb-2">😠</div>
                                <div class="text-sm font-medium ${valence < 0 && arousal > 0 ? 'text-red-300' : 'text-red-400/70'}">Rabbia</div>
                                <div class="text-xs text-gray-500 mt-1">Attivazione -</div>
                                ${valence < 0 && arousal > 0 ? '<div class="absolute top-2 right-2 w-3 h-3 bg-red-400 rounded-full animate-pulse"></div>' : ''}
                            </div>

                            <!-- Top-Right: Gioia (high arousal, positive valence) -->
                            <div class="relative rounded-xl p-4 text-center transition-all ${valence >= 0 && arousal > 0 ? 'bg-yellow-600/40 ring-2 ring-yellow-500' : 'bg-yellow-900/20'}">
                                <div class="text-3xl mb-2">😊</div>
                                <div class="text-sm font-medium ${valence >= 0 && arousal > 0 ? 'text-yellow-300' : 'text-yellow-400/70'}">Gioia</div>
                                <div class="text-xs text-gray-500 mt-1">Attivazione +</div>
                                ${valence >= 0 && arousal > 0 ? '<div class="absolute top-2 right-2 w-3 h-3 bg-yellow-400 rounded-full animate-pulse"></div>' : ''}
                            </div>

                            <!-- Bottom-Left: Tristezza (low arousal, negative valence) -->
                            <div class="relative rounded-xl p-4 text-center transition-all ${valence < 0 && arousal <= 0 ? 'bg-blue-600/40 ring-2 ring-blue-500' : 'bg-blue-900/20'}">
                                <div class="text-3xl mb-2">😢</div>
                                <div class="text-sm font-medium ${valence < 0 && arousal <= 0 ? 'text-blue-300' : 'text-blue-400/70'}">Tristezza</div>
                                <div class="text-xs text-gray-500 mt-1">Calma -</div>
                                ${valence < 0 && arousal <= 0 ? '<div class="absolute top-2 right-2 w-3 h-3 bg-blue-400 rounded-full animate-pulse"></div>' : ''}
                            </div>

                            <!-- Bottom-Right: Serenità (low arousal, positive valence) -->
                            <div class="relative rounded-xl p-4 text-center transition-all ${valence >= 0 && arousal <= 0 ? 'bg-green-600/40 ring-2 ring-green-500' : 'bg-green-900/20'}">
                                <div class="text-3xl mb-2">😌</div>
                                <div class="text-sm font-medium ${valence >= 0 && arousal <= 0 ? 'text-green-300' : 'text-green-400/70'}">Serenità</div>
                                <div class="text-xs text-gray-500 mt-1">Calma +</div>
                                ${valence >= 0 && arousal <= 0 ? '<div class="absolute top-2 right-2 w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>' : ''}
                            </div>
                        </div>

                        <!-- Right side: Interpretation + Values -->
                        <div class="space-y-3">
                            <!-- Current state highlight -->
                            <div class="bg-gray-800/50 rounded-xl p-4" style="border-left: 4px solid ${color}">
                                <div class="text-xs text-gray-400 uppercase mb-1">Il tuo stato attuale</div>
                                <div class="text-xl font-bold" style="color: ${color}">${quadrantName}</div>
                                <p class="text-sm text-gray-300 mt-2">${quadrant_info?.description_it || ''}</p>
                            </div>

                            <!-- Numeric values -->
                            <div class="grid grid-cols-2 gap-2">
                                <div class="bg-gray-800/50 rounded-lg p-3 text-center">
                                    <div class="text-xs text-gray-400">Piacevolezza</div>
                                    <div class="text-lg font-bold ${valence >= 0 ? 'text-green-400' : 'text-blue-400'}">
                                        ${valence >= 0 ? '+' : ''}${valence}
                                    </div>
                                </div>
                                <div class="bg-gray-800/50 rounded-lg p-3 text-center">
                                    <div class="text-xs text-gray-400">Energia</div>
                                    <div class="text-lg font-bold ${arousal >= 0 ? 'text-orange-400' : 'text-indigo-400'}">
                                        ${arousal >= 0 ? '+' : ''}${arousal}
                                    </div>
                                </div>
                            </div>

                            <p class="text-xs text-gray-500 italic">${interpretation}</p>
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Render Emotional Granularity (Barrett)
         */
        renderGranularity(granularity) {
            if (!granularity) return '';

            const { unique_emotions, max_possible, score, level_it, description, scientific_note } = granularity;

            const getColor = (level) => {
                switch(level) {
                    case 'high': return '#10B981';
                    case 'moderate': return '#F59E0B';
                    default: return '#6366F1';
                }
            };
            const color = getColor(granularity.level);

            return `
                <div class="space-y-4">
                    <h3 class="text-base sm:text-lg font-bold text-white flex flex-wrap items-center gap-1 sm:gap-2">
                        <span>🎯</span>
                        <span>Granularità Emotiva</span>
                        <span class="text-xs sm:text-sm font-normal text-gray-400 w-full sm:w-auto">(Barrett, 2017)</span>
                    </h3>

                    <div class="grid md:grid-cols-2 gap-6">
                        <!-- Score Visual -->
                        <div class="flex items-center justify-center">
                            <div class="relative w-40 h-40">
                                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                                    <circle cx="50" cy="50" r="45" fill="none" stroke="#374151" stroke-width="8"/>
                                    <circle cx="50" cy="50" r="45" fill="none" stroke="${color}" stroke-width="8"
                                            stroke-dasharray="${score * 2.83} 283"
                                            stroke-linecap="round"/>
                                </svg>
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <div class="text-3xl font-bold text-white">${unique_emotions}</div>
                                    <div class="text-sm text-gray-400">su ${max_possible}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="space-y-3">
                            <div class="bg-gray-900/50 rounded-lg p-4" style="border-left: 4px solid ${color}">
                                <div class="font-semibold text-lg" style="color: ${color}">${level_it}</div>
                                <p class="text-gray-300 mt-2">${description}</p>
                            </div>
                            <div class="text-xs text-gray-500 italic">
                                📚 ${scientific_note}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Render emotion section (evoked, expressed, journal)
         */
        renderEmotionSection(emotions, title, icon, subtitle, colorName) {
            if (!emotions) return '';

            const { total, distribution, predominant } = emotions;
            const colors = {
                purple: { primary: '#A855F7', bg: 'purple-400' },
                pink: { primary: '#EC4899', bg: 'pink-400' },
                indigo: { primary: '#6366F1', bg: 'indigo-400' },
            };
            const colorSet = colors[colorName] || colors.purple;

            return `
                <div class="space-y-4">
                    <h3 class="text-sm sm:text-lg font-bold text-white flex flex-wrap items-center gap-1 sm:gap-2">
                        <span class="text-xl sm:text-2xl">${icon}</span>
                        <span class="truncate">${title}</span>
                        <span class="text-xs sm:text-sm font-normal text-gray-400 w-full sm:w-auto truncate">${subtitle}</span>
                    </h3>

                    <div class="text-center py-4">
                        <div class="text-4xl font-bold text-${colorSet.bg}">${total}</div>
                        <div class="text-sm text-gray-400">registrazioni</div>
                    </div>

                    ${predominant ? `
                    <div class="bg-gray-900/50 rounded-lg p-3 text-center">
                        <div class="text-3xl">${predominant.icon || '✨'}</div>
                        <div class="font-semibold text-white">${predominant.name_it || 'N/A'}</div>
                        <div class="text-sm text-gray-400">${predominant.percentage || 0}%</div>
                    </div>
                    ` : ''}

                    <!-- Distribution Chart -->
                    <div class="mt-4">
                        <canvas id="ea-${colorName}-chart" height="180"></canvas>
                    </div>
                </div>
            `;
        }

        /**
         * Render Circumplex Wheel
         */
        renderCircumplexWheel(wheelData) {
            if (!wheelData || wheelData.length === 0) return '';

            return `
                <div class="space-y-4">
                    <h3 class="text-base sm:text-lg font-bold text-white flex items-center gap-1 sm:gap-2">
                        <span class="text-xl sm:text-2xl">🎨</span>
                        <span>Ruota delle Emozioni</span>
                    </h3>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div class="relative" style="height: 300px;">
                            <canvas id="ea-circumplex-wheel-chart"></canvas>
                        </div>

                        <div class="grid grid-cols-2 gap-1 sm:gap-2">
                            ${wheelData.map(e => `
                                <div class="flex items-center gap-1 sm:gap-2 bg-gray-900/50 rounded-lg px-2 sm:px-3 py-1.5 sm:py-2 ${e.total_count === 0 ? 'opacity-40' : ''}">
                                    <span class="text-base sm:text-xl flex-shrink-0">${e.icon}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs sm:text-sm font-medium text-white truncate">${e.name_it}</div>
                                        <div class="text-xs text-gray-400">${e.total_count}</div>
                                    </div>
                                    <div class="w-2 h-2 sm:w-3 sm:h-3 rounded-full flex-shrink-0" style="background-color: ${e.color}"></div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Render Mood Timeline (Valence + Arousal)
         */
        renderMoodTimeline(timeline) {
            if (!timeline) return '';

            return `
                <div class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <h3 class="text-base sm:text-lg font-bold text-white flex flex-wrap items-center gap-1 sm:gap-2">
                            <span class="text-xl sm:text-2xl">📈</span>
                            <span>Timeline Emotiva</span>
                            <span class="text-xs sm:text-sm font-normal text-gray-400">(30 giorni)</span>
                        </h3>
                        <div class="flex gap-3 sm:gap-4 text-xs sm:text-sm">
                            <span class="flex items-center gap-1">
                                <span class="w-2 h-2 sm:w-3 sm:h-3 rounded-full bg-green-400"></span>
                                Valence
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="w-2 h-2 sm:w-3 sm:h-3 rounded-full bg-orange-400"></span>
                                Arousal
                            </span>
                        </div>
                    </div>

                    <div class="relative" style="height: 250px;">
                        <canvas id="ea-mood-timeline-chart"></canvas>
                    </div>

                    <div class="text-xs text-gray-500 text-center">
                        Valence: piacevole (+) / spiacevole (-) • Arousal: alta energia (+) / bassa energia (-)
                    </div>
                </div>
            `;
        }

        /**
         * Render Patterns (month comparison)
         */
        renderPatterns(patterns) {
            if (!patterns) return '';

            const { this_month, last_month, changes, interpretation } = patterns;

            const formatChange = (val) => {
                if (val === null) return '--';
                return (val > 0 ? '+' : '') + val;
            };

            return `
                <div class="space-y-4">
                    <h3 class="text-base sm:text-lg font-bold text-white flex items-center gap-1 sm:gap-2">
                        <span class="text-xl sm:text-2xl">🔄</span>
                        <span>Pattern Mensile</span>
                    </h3>

                    <div class="grid grid-cols-2 gap-2 sm:gap-4">
                        <div class="bg-gray-900/50 rounded-lg p-3 sm:p-4 text-center">
                            <div class="text-xs text-gray-400 uppercase mb-1 sm:mb-2">Questo Mese</div>
                            <div class="text-xl sm:text-2xl font-bold text-purple-400">${this_month?.entry_count || 0}</div>
                            <div class="text-xs text-gray-500">registrazioni</div>
                        </div>
                        <div class="bg-gray-900/50 rounded-lg p-3 sm:p-4 text-center">
                            <div class="text-xs text-gray-400 uppercase mb-1 sm:mb-2">Mese Scorso</div>
                            <div class="text-xl sm:text-2xl font-bold text-gray-400">${last_month?.entry_count || 0}</div>
                            <div class="text-xs text-gray-500">registrazioni</div>
                        </div>
                    </div>

                    ${interpretation ? `
                    <div class="bg-gray-900/50 rounded-lg p-4 text-center">
                        <p class="text-gray-300">${interpretation}</p>
                    </div>
                    ` : ''}
                </div>
            `;
        }

        /**
         * Render Insights (with scientific basis)
         */
        renderInsights(insights) {
            if (!insights || insights.length === 0) return '';

            return `
                <div class="space-y-4">
                    <h3 class="text-base sm:text-lg font-bold text-white flex items-center gap-1 sm:gap-2">
                        <span class="text-xl sm:text-2xl">💡</span>
                        <span>Osservazioni</span>
                    </h3>

                    <div class="grid md:grid-cols-2 gap-3 sm:gap-4">
                        ${insights.map(insight => `
                            <div class="bg-gray-900/50 rounded-xl p-3 sm:p-4 border-l-4 border-purple-500">
                                <div class="flex items-start gap-2 sm:gap-3">
                                    <span class="text-2xl sm:text-3xl flex-shrink-0">${insight.icon || '✨'}</span>
                                    <div class="min-w-0">
                                        <h4 class="font-semibold text-white text-sm sm:text-base">${insight.title}</h4>
                                        <p class="text-xs sm:text-sm text-gray-300 mt-1">${insight.message}</p>
                                        ${insight.scientific_basis ? `
                                        <p class="text-xs text-gray-500 mt-2 italic hidden sm:block">📚 ${insight.scientific_basis}</p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        /**
         * Render Scientific Disclaimer
         */
        renderDisclaimer(scientificBasis) {
            return `
                <div class="text-center space-y-2">
                    <div class="text-xs text-gray-500">
                        ⚠️ ${scientificBasis?.disclaimer || 'Questa è un\'analisi descrittiva dei pattern emotivi. Non costituisce diagnosi o consiglio medico.'}
                    </div>
                    <div class="text-xs text-gray-600">
                        Basato su: Russell Circumplex Model (1980) • Barrett Emotional Granularity (2017)
                    </div>
                </div>
            `;
        }

        renderEmptyState() {
            this.container.innerHTML = `
                <div class="text-center py-16">
                    <div class="text-6xl mb-4">🎭</div>
                    <h3 class="text-xl font-bold text-white mb-2">Nessun Dato Ancora</h3>
                    <p class="text-gray-400 mb-6 max-w-md mx-auto">
                        Inizia a interagire con la community o usa il diario emotivo
                        per vedere la tua analisi.
                    </p>
                    <a href="/feed" class="btn-primary">Esplora il Feed</a>
                </div>
            `;
        }

        renderError() {
            this.container.innerHTML = `
                <div class="text-center py-16">
                    <div class="text-6xl mb-4">😔</div>
                    <h3 class="text-xl font-bold text-white mb-2">Errore nel Caricamento</h3>
                    <p class="text-gray-400 mb-6">Non è stato possibile caricare i dati.</p>
                    <button onclick="window.EmotionalAnalytics.refresh()" class="btn-secondary">Riprova</button>
                </div>
            `;
        }

        /**
         * Initialize all charts
         */
        initCharts() {
            if (!this.chartJsLoaded || !window.Chart) return;

            // Distribution charts
            this.initDistributionChart('ea-purple-chart', this.data?.evoked_emotions?.distribution || []);
            this.initDistributionChart('ea-pink-chart', this.data?.expressed_emotions?.distribution || []);
            this.initDistributionChart('ea-indigo-chart', this.data?.journal_emotions?.distribution || []);

            // Circumplex wheel
            this.initCircumplexWheelChart();

            // Timeline (Valence + Arousal)
            this.initMoodTimelineChart();
        }

        initDistributionChart(canvasId, distribution) {
            const canvas = document.getElementById(canvasId);
            if (!canvas || distribution.length === 0) return;

            if (this.charts[canvasId]) this.charts[canvasId].destroy();

            this.charts[canvasId] = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: distribution.map(e => e.name_it),
                    datasets: [{
                        data: distribution.map(e => e.count),
                        backgroundColor: distribution.map(e => e.color + '80'),
                        borderColor: distribution.map(e => e.color),
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.1)' }, ticks: { color: '#9CA3AF' } },
                        y: { grid: { display: false }, ticks: { color: '#D1D5DB' } },
                    },
                },
            });
        }

        initCircumplexWheelChart() {
            const canvas = document.getElementById('ea-circumplex-wheel-chart');
            const wheelData = this.data?.circumplex_wheel;
            if (!canvas || !wheelData) return;

            if (this.charts['ea-circumplex-wheel-chart']) {
                this.charts['ea-circumplex-wheel-chart'].destroy();
            }

            this.charts['ea-circumplex-wheel-chart'] = new Chart(canvas, {
                type: 'polarArea',
                data: {
                    labels: wheelData.map(e => e.name_it),
                    datasets: [{
                        data: wheelData.map(e => e.total_count),
                        backgroundColor: wheelData.map(e => e.color + '80'),
                        borderColor: wheelData.map(e => e.color),
                        borderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        r: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.1)' }, ticks: { display: false } },
                    },
                },
            });
        }

        initMoodTimelineChart() {
            const canvas = document.getElementById('ea-mood-timeline-chart');
            const timeline = this.data?.mood_timeline;
            if (!canvas || !timeline) return;

            if (this.charts['ea-mood-timeline-chart']) {
                this.charts['ea-mood-timeline-chart'].destroy();
            }

            const labels = timeline.timeline?.map(d => d.label) || [];
            const valenceData = timeline.timeline?.map(d => d.valence) || [];
            const arousalData = timeline.timeline?.map(d => d.arousal) || [];

            this.charts['ea-mood-timeline-chart'] = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Valence',
                            data: valenceData,
                            borderColor: '#10B981',
                            backgroundColor: '#10B981',
                            borderWidth: 3,
                            fill: false,
                            tension: 0.4,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#10B981',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            spanGaps: true,
                        },
                        {
                            label: 'Arousal',
                            data: arousalData,
                            borderColor: '#F97316',
                            backgroundColor: '#F97316',
                            borderWidth: 3,
                            fill: false,
                            tension: 0.4,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#F97316',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            spanGaps: true,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#9CA3AF', usePointStyle: true, pointStyle: 'circle', padding: 20 },
                        },
                        tooltip: {
                            backgroundColor: 'rgba(17, 24, 39, 0.95)',
                            titleColor: '#F3F4F6',
                            bodyColor: '#D1D5DB',
                            callbacks: {
                                label: function(ctx) {
                                    if (ctx.parsed.y === null) return `${ctx.dataset.label}: nessun dato`;
                                    return `${ctx.dataset.label}: ${ctx.parsed.y}`;
                                }
                            }
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#6B7280', maxTicksLimit: 10 } },
                        y: {
                            min: -1,
                            max: 1,
                            grid: { color: 'rgba(255,255,255,0.1)' },
                            ticks: { color: '#9CA3AF', stepSize: 0.5 },
                        },
                    },
                },
            });
        }

        async refresh() {
            this.showLoading();
            try {
                const response = await fetch('/api/profile/emotional-analytics?refresh=1', {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });
                const json = await response.json();
                if (json.success) {
                    this.data = json.data;
                    this.cacheInfo = json.cache_info;
                }
            } catch (error) {
                console.error('[EmotionalAnalytics] Refresh failed:', error);
            }
            this.render();
        }
    }

    const emotionalAnalytics = new EmotionalAnalytics();
    window.EmotionalAnalytics = emotionalAnalytics;
})();
