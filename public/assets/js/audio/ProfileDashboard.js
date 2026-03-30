/**
 * ================================================================================
 * PROFILE DASHBOARD - PSYCHOLOGICAL "MIRROR OF THE SOUL"
 * ================================================================================
 *
 * PURPOSE:
 * Interactive emotional health dashboard based on clinical psychology research
 * Provides users with compassionate insights into their emotional patterns
 *
 * PSYCHOLOGY PRINCIPLES:
 * - Plutchik's Wheel of Emotions (8 primary emotions)
 * - Cognitive Behavioral Therapy (CBT) - Pattern recognition
 * - Mindfulness - Non-judgmental awareness
 * - Positive Psychology - Growth and wellbeing
 *
 * KEY FEATURES:
 * - Emotional Health Score (0-100) with compassionate interpretation
 * - Emotion Distribution Wheel (Plutchik-inspired)
 * - 30-day Mood Timeline with trend analysis
 * - AI-powered Compassionate Insights
 * - Crisis Support Resources (when needed)
 * - Audio Archive by Emotion
 *
 * DESIGN PHILOSOPHY:
 * ❌ Never: judge, diagnose, shame, force positivity
 * ✅ Always: validate, support, celebrate, suggest help gently
 *
 * @version 1.0.0 - Enterprise Galaxy
 * @author need2talk.it - AI-Orchestrated Development
 * ================================================================================
 */

class ProfileDashboard {
    constructor(containerId = 'profile-dashboard') {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            console.error('[ProfileDashboard] Container not found:', containerId);
            return;
        }

        this.apiEndpoint = '/api/emotional-health/dashboard';
        this.data = null;
        this.charts = {};
        this.isLoading = false;

        this.init();
    }

    /**
     * Initialize dashboard
     */
    async init() {
        try {
            // Show loading state
            this.renderLoading();

            // Fetch dashboard data
            await this.fetchDashboardData();

            // Render dashboard
            if (this.data) {
                if (this.data.empty) {
                    this.renderEmptyState();
                } else {
                    this.renderDashboard();
                }
            }

        } catch (error) {
            console.error('[ProfileDashboard] Initialization failed:', error);
            this.renderError(error.message);
        }
    }

    /**
     * Fetch dashboard data from API
     */
    async fetchDashboardData(days = 30) {
        try {
            this.isLoading = true;

            const response = await fetch(`${this.apiEndpoint}?days=${days}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                if (response.status === 401) {
                    throw new Error('Authentication required');
                }
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            this.data = await response.json();

            this.isLoading = false;
            return this.data;

        } catch (error) {
            this.isLoading = false;
            console.error('[ProfileDashboard] Failed to fetch data:', error);
            throw error;
        }
    }

    /**
     * Render loading state
     */
    renderLoading() {
        this.container.innerHTML = `
            <div class="dashboard-loading">
                <div class="loading-spinner"></div>
                <p>Sto analizzando il tuo benessere emotivo...</p>
            </div>
        `;
    }

    /**
     * Render empty state (no audio posts yet)
     * ENTERPRISE: Welcoming, not error-like. Encourages action without pressure.
     */
    renderEmptyState() {
        this.container.innerHTML = `
            <div class="dashboard-empty">
                <div class="empty-icon">🌱</div>
                <h2>La Tua Dashboard Emotiva Prende Vita con Te</h2>
                <p class="empty-message">
                    Questo spazio si popolerà automaticamente quando inizierai a condividere le tue emozioni.
                </p>
                <p class="empty-subtitle">
                    <strong>Come far crescere la tua dashboard:</strong><br>
                    📝 <strong>Diario Emotivo</strong> - Annota come ti senti ogni giorno, anche senza registrare audio<br>
                    🎙️ <strong>Registrazioni Audio</strong> - Condividi i tuoi pensieri selezionando un'emozione<br>
                    <br>
                    Più condividi, più la dashboard ti mostrerà pattern, tendenze e insight sul tuo benessere emotivo.
                </p>
                <button class="btn-start-recording" onclick="if(window.floatingRecorder){window.floatingRecorder.openModal()}else{console.error('FloatingRecorder not ready')}">
                    <span class="icon">🎙️</span>
                    Inizia Ora - Registra Audio
                </button>
            </div>
        `;
    }

    /**
     * Render complete dashboard with all components
     */
    renderDashboard() {
        const { health_score, distribution, timeline, insights, stats } = this.data;

        this.container.innerHTML = `
            <div class="dashboard-container">
                <!-- Health Score Section -->
                <section class="dashboard-section section-health-score">
                    ${this.renderHealthScore(health_score)}
                </section>

                <!-- Insights Section -->
                <section class="dashboard-section section-insights">
                    ${this.renderInsights(insights)}
                </section>

                <!-- Charts Row -->
                <div class="dashboard-charts-row">
                    <!-- Emotion Wheel -->
                    <section class="dashboard-section section-emotion-wheel">
                        ${this.renderEmotionWheel(distribution)}
                    </section>

                    <!-- Mood Timeline -->
                    <section class="dashboard-section section-mood-timeline">
                        ${this.renderMoodTimeline(timeline)}
                    </section>
                </div>

                <!-- Stats Cards -->
                <section class="dashboard-section section-stats">
                    ${this.renderStatsCards(stats)}
                </section>

                <!-- Evoked Emotions Section (ENTERPRISE GALAXY) -->
                <section class="dashboard-section section-evoked-emotions">
                    <div id="evoked-emotions-container">
                        <div class="loading-spinner">Caricamento emozioni evocate...</div>
                    </div>
                </section>
            </div>
        `;

        // Initialize interactive charts after DOM is ready
        setTimeout(() => {
            this.initializeCharts();
            this.loadEvokedEmotions(); // ENTERPRISE: Load async to not block main dashboard
        }, 100);
    }

    /**
     * Render Health Score Card
     * ENTERPRISE: Handle undefined/null values with safe fallbacks
     */
    renderHealthScore(healthScore) {
        const { total, interpretation, diversity, balance, stability, engagement } = healthScore;
        const { status, message, color, icon } = interpretation;

        // ENTERPRISE: Safe value extraction with fallbacks (prevent NaN in calculations)
        // Backend returns scores at ROOT level (not nested in breakdown)
        const diversityScore = diversity ?? 0;
        const balanceScore = balance ?? 0;
        const stabilityScore = stability ?? 0;
        const engagementScore = engagement ?? 0;

        // Show crisis resources if needed
        const resourcesHTML = interpretation.show_resources ? this.renderCrisisResources(interpretation.resources) : '';

        return `
            <div class="health-score-card" style="border-left: 4px solid ${color}">
                <div class="score-header">
                    <h2>
                        <span class="score-icon">${icon}</span>
                        ${status}
                    </h2>
                    <div class="score-value" style="color: ${color}">${total ?? 0}/100</div>
                </div>

                <p class="score-message">${message}</p>

                <div class="score-breakdown">
                    <div class="breakdown-item">
                        <span class="breakdown-label" title="Quante emozioni diverse esprimi? Più varietà = salute emotiva migliore (ideale: 7-8 emozioni diverse)">
                            Diversità Emotiva
                            <span class="info-icon">ℹ️</span>
                        </span>
                        <div class="breakdown-bar">
                            <div class="breakdown-fill" style="width: ${(diversityScore / 30 * 100).toFixed(1)}%; background: #8B5CF6"></div>
                        </div>
                        <span class="breakdown-value">${diversityScore}/30</span>
                        <span class="breakdown-explanation">Hai espresso ${healthScore.breakdown?.unique_emotions ?? 0} emozioni diverse</span>
                    </div>

                    <div class="breakdown-item">
                        <span class="breakdown-label" title="Equilibrio tra emozioni positive e negative. L'ideale è 60% positive, 40% negative (non 100% felici!)">
                            Equilibrio Emotivo
                            <span class="info-icon">ℹ️</span>
                        </span>
                        <div class="breakdown-bar">
                            <div class="breakdown-fill" style="width: ${(balanceScore / 40 * 100).toFixed(1)}%; background: #10B981"></div>
                        </div>
                        <span class="breakdown-value">${balanceScore}/40</span>
                        <span class="breakdown-explanation">${healthScore.breakdown?.positive_ratio ?? 0}% emozioni positive</span>
                    </div>

                    <div class="breakdown-item">
                        <span class="breakdown-label" title="Quanto sono stabili le tue emozioni? Cambi rapidi tra gioia e tristezza possono indicare stress">
                            Stabilità
                            <span class="info-icon">ℹ️</span>
                        </span>
                        <div class="breakdown-bar">
                            <div class="breakdown-fill" style="width: ${(stabilityScore / 20 * 100).toFixed(1)}%; background: #F59E0B"></div>
                        </div>
                        <span class="breakdown-value">${stabilityScore}/20</span>
                        <span class="breakdown-explanation">Volatilità: ${(healthScore.breakdown?.volatility ?? 0).toFixed(2)}</span>
                    </div>

                    <div class="breakdown-item">
                        <span class="breakdown-label" title="Quanto condividi le tue emozioni? Esprimersi regolarmente è un segno di benessere">
                            Engagement
                            <span class="info-icon">ℹ️</span>
                        </span>
                        <div class="breakdown-bar">
                            <div class="breakdown-fill" style="width: ${(engagementScore / 10 * 100).toFixed(1)}%; background: #3B82F6"></div>
                        </div>
                        <span class="breakdown-value">${engagementScore}/10</span>
                        <span class="breakdown-explanation">${healthScore.breakdown?.avg_posts_per_week ?? 0} audio/settimana</span>
                    </div>
                </div>

                <!-- TRANSPARENCY SECTION: How we calculate your score -->
                <details class="calculation-explanation">
                    <summary>🔍 Come calcoliamo il tuo punteggio?</summary>
                    <div class="explanation-content">
                        <p><strong>Il tuo Punteggio di Salute Emotiva (${total}/100)</strong> è basato su ricerche di psicologia clinica:</p>

                        <div class="metric-explanation">
                            <h4>1️⃣ Diversità Emotiva (max 30 punti)</h4>
                            <p>
                                <strong>Cosa misuriamo:</strong> Quante emozioni diverse esprimi nei tuoi audio.<br>
                                <strong>Perché è importante:</strong> La ricerca in psicologia mostra che <em>sopprimere le emozioni</em> è dannoso.
                                Una persona emotivamente sana esprime una <strong>varietà di emozioni</strong> (gioia, tristezza, rabbia, paura, ecc.).<br>
                                <strong>Il tuo risultato:</strong> ${healthScore.breakdown?.unique_emotions ?? 0} emozioni diverse = ${diversityScore} punti<br>
                                <strong>Come migliorare:</strong> Non nascondere le emozioni negative. Esprimere tristezza o rabbia è normale e sano!
                            </p>
                        </div>

                        <div class="metric-explanation">
                            <h4>2️⃣ Equilibrio Emotivo (max 40 punti)</h4>
                            <p>
                                <strong>Cosa misuriamo:</strong> Il rapporto tra emozioni positive e negative.<br>
                                <strong>Perché è importante:</strong> L'obiettivo NON è essere sempre felici!
                                La ricerca suggerisce che un rapporto <strong>60% positivo / 40% negativo</strong> è l'ideale per il benessere.<br>
                                <strong>Il tuo risultato:</strong> ${healthScore.breakdown?.positive_ratio ?? 0}% positive = ${balanceScore} punti<br>
                                <strong>Come migliorare:</strong> Se sei troppo in un estremo (sempre triste O sempre felice), prova a bilanciare.
                                Le emozioni negative hanno una funzione: ci aiutano a crescere.
                            </p>
                        </div>

                        <div class="metric-explanation">
                            <h4>3️⃣ Stabilità Emotiva (max 20 punti)</h4>
                            <p>
                                <strong>Cosa misuriamo:</strong> Quanto oscillano le tue emozioni tra post consecutivi.<br>
                                <strong>Perché è importante:</strong> Sbalzi emotivi estremi e rapidi (es. gioia → rabbia → tristezza in poche ore)
                                possono indicare stress o difficoltà.<br>
                                <strong>Il tuo risultato:</strong> Volatilità ${(healthScore.breakdown?.volatility ?? 0).toFixed(2)} = ${stabilityScore} punti<br>
                                <strong>Come migliorare:</strong> Se noti instabilità, considera tecniche di mindfulness o parlare con qualcuno di fiducia.
                            </p>
                        </div>

                        <div class="metric-explanation">
                            <h4>4️⃣ Engagement (max 10 punti)</h4>
                            <p>
                                <strong>Cosa misuriamo:</strong> Quanto spesso condividi le tue emozioni (audio/settimana).<br>
                                <strong>Perché è importante:</strong> Esprimere le emozioni regolarmente è un segno di benessere emotivo.
                                Reprimere o isolarsi può essere un campanello d'allarme.<br>
                                <strong>Il tuo risultato:</strong> ${healthScore.breakdown?.avg_posts_per_week ?? 0} audio/settimana = ${engagementScore} punti<br>
                                <strong>Come migliorare:</strong> Non serve pubblicare ogni giorno! Anche 2-3 volte a settimana è ottimo per rimanere in contatto con le tue emozioni.
                            </p>
                        </div>

                        <p class="disclaimer">
                            <strong>⚠️ Nota importante:</strong> Questo punteggio è uno <em>strumento di riflessione</em>,
                            non una diagnosi medica. Se stai vivendo difficoltà emotive persistenti,
                            considera di parlare con un professionista della salute mentale.
                        </p>
                    </div>
                </details>

                ${resourcesHTML}
            </div>
        `;
    }

    /**
     * Render Crisis Resources (shown for low scores)
     */
    renderCrisisResources(resources) {
        if (!resources || resources.length === 0) return '';

        const resourceItems = resources.map(resource => {
            if (resource.phone) {
                return `
                    <div class="resource-item">
                        <strong>${resource.name}</strong>
                        <div>
                            <a href="tel:${resource.phone.replace(/\s/g, '')}" class="resource-phone">
                                📞 ${resource.phone}
                            </a>
                            <span class="resource-hours">${resource.hours}</span>
                        </div>
                        <p class="resource-desc">${resource.description}</p>
                    </div>
                `;
            } else if (resource.url) {
                return `
                    <div class="resource-item">
                        <strong>${resource.name}</strong>
                        <div>
                            <a href="${resource.url}" target="_blank" class="resource-link">
                                🌐 Visita il sito
                            </a>
                        </div>
                        <p class="resource-desc">${resource.description}</p>
                    </div>
                `;
            }
        }).join('');

        return `
            <div class="crisis-resources">
                <h4>💙 Supporto Disponibile</h4>
                <p>Non sei solo. Parlare con qualcuno può fare la differenza.</p>
                <div class="resources-list">
                    ${resourceItems}
                </div>
            </div>
        `;
    }

    /**
     * Render AI-powered Insights
     * ENTERPRISE PSYCHOLOGY: Compassionate, evidence-based insights
     */
    renderInsights(insights) {
        if (!insights || insights.length === 0) {
            return '<p class="text-muted">Nessun insight disponibile</p>';
        }

        const insightItems = insights.map(insight => {
            const typeClass = insight.type === 'positive' ? 'insight-positive' :
                              insight.type === 'neutral' ? 'insight-neutral' :
                              'insight-support';

            return `
                <div class="insight-card ${typeClass}">
                    <div class="insight-icon">${insight.icon}</div>
                    <div class="insight-content">
                        <h4>${insight.title}</h4>
                        <p>${insight.message}</p>
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="insights-container">
                <div class="insights-header">
                    <h3>✨ I Tuoi Insight Emotivi</h3>
                    <p class="insights-subtitle">
                        Questi suggerimenti sono basati sui tuoi <strong>pattern emotivi</strong> degli ultimi 30 giorni.
                        Analizziamo la <em>varietà</em>, l'<em>equilibrio</em> e la <em>stabilità</em> delle tue emozioni
                        per offrirti riflessioni compassionate e scientificamente fondate.
                    </p>
                </div>
                <div class="insights-grid">
                    ${insightItems}
                </div>
                <details class="insights-methodology">
                    <summary>🧠 Come generiamo questi suggerimenti?</summary>
                    <div class="methodology-content">
                        <p>
                            I suggerimenti sono generati <strong>analizzando le statistiche</strong> delle tue
                            emozioni condivise. Si tratta di semplici regole automatiche, non di analisi cliniche.
                        </p>

                        <h4>📊 Cosa calcoliamo:</h4>
                        <ul>
                            <li><strong>Diversità emotiva:</strong> Quante emozioni diverse hai espresso</li>
                            <li><strong>Equilibrio positivo/negativo:</strong> Percentuale di emozioni positive vs negative</li>
                            <li><strong>Frequenza:</strong> Quanti post audio condividi nel tempo</li>
                            <li><strong>Trend:</strong> Se le emozioni cambiano nel tempo (più positive o negative)</li>
                        </ul>

                        <h4>💬 Il nostro approccio:</h4>
                        <ul>
                            <li><strong>Non-giudizio:</strong> Tutte le emozioni sono valide</li>
                            <li><strong>Linguaggio supportivo:</strong> Mai colpevolizzante</li>
                            <li><strong>Suggerimenti pratici:</strong> Spunti semplici di riflessione</li>
                        </ul>

                        <p class="disclaimer">
                            <strong>⚠️ Importante:</strong> Questi suggerimenti hanno <strong>scopo puramente informativo</strong>
                            e NON sostituiscono in alcun modo il supporto di un professionista della salute mentale.
                            Se senti il bisogno di parlare con qualcuno, consulta uno psicologo o il tuo medico.
                        </p>
                    </div>
                </details>
            </div>
        `;
    }

    /**
     * Render Emotion Distribution Wheel (Plutchik-inspired)
     */
    renderEmotionWheel(distribution) {
        // This will be enhanced with Chart.js canvas-based wheel
        // For now, render a simple list with visual bars

        const emotionItems = distribution.map(emotion => {
            // ENTERPRISE: Safe value extraction with fallbacks
            const percentage = emotion?.percentage ?? 0;
            const count = emotion?.count ?? 0;
            const nameIt = emotion?.name_it ?? 'Sconosciuta';
            const iconEmoji = emotion?.icon_emoji ?? '❓';
            const colorHex = emotion?.color_hex ?? '#9CA3AF';

            return `
                <div class="emotion-item">
                    <div class="emotion-header">
                        <span class="emotion-icon">${iconEmoji}</span>
                        <span class="emotion-name">${nameIt}</span>
                    </div>
                    <div class="emotion-bar">
                        <div class="emotion-fill"
                             style="width: ${percentage}%; background: ${colorHex}">
                        </div>
                    </div>
                    <div class="emotion-stats">
                        <span class="emotion-count">${count} audio</span>
                        <span class="emotion-percentage">${percentage.toFixed(1)}%</span>
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="emotion-wheel-container">
                <h3>🎨 La Tua Palette Emotiva</h3>
                <div class="emotion-wheel-placeholder">
                    <!-- Canvas for Chart.js wheel will be added here -->
                    <canvas id="emotion-wheel-chart" width="300" height="300"></canvas>
                </div>
                <div class="emotion-list">
                    ${emotionItems}
                </div>
            </div>
        `;
    }

    /**
     * Render Mood Timeline (30-day)
     */
    renderMoodTimeline(timeline) {
        const { trend } = timeline;

        const trendIcon = trend === 'improving' ? '📈' :
                         trend === 'declining' ? '📉' : '➡️';

        const trendMessage = trend === 'improving' ? 'In miglioramento' :
                            trend === 'declining' ? 'In declino' : 'Stabile';

        return `
            <div class="mood-timeline-container">
                <h3>📊 Andamento Emotivo (30 giorni)</h3>
                <div class="timeline-trend">
                    <span class="trend-icon">${trendIcon}</span>
                    <span class="trend-label">${trendMessage}</span>
                </div>

                <!-- Canvas for Chart.js line chart -->
                <div class="timeline-chart-wrapper">
                    <canvas id="mood-timeline-chart" width="600" height="300"></canvas>
                </div>
            </div>
        `;
    }

    /**
     * Render Stats Cards
     * ENTERPRISE: Handle undefined/null values with user-friendly fallbacks
     */
    renderStatsCards(stats) {
        // ENTERPRISE: Safe value extraction with fallbacks
        const totalPosts = stats?.total_posts ?? 0;
        const uniqueEmotions = stats?.unique_emotions ?? 0;
        const positiveRatio = stats?.positive_ratio ?? 0;
        const daysActive = stats?.days_active ?? 0;

        return `
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">🎙️</div>
                    <div class="stat-value">${totalPosts}</div>
                    <div class="stat-label">Audio Condivisi</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🌈</div>
                    <div class="stat-value">${uniqueEmotions}</div>
                    <div class="stat-label">Emozioni Diverse</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">✨</div>
                    <div class="stat-value">${positiveRatio}%</div>
                    <div class="stat-label">Emozioni Positive</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📅</div>
                    <div class="stat-value">${daysActive}</div>
                    <div class="stat-label">Giorni Attivi</div>
                </div>
            </div>
        `;
    }

    /**
     * Initialize Chart.js charts
     */
    initializeCharts() {
        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.warn('[ProfileDashboard] Chart.js not loaded. Charts will not render.');
            return;
        }

        // Initialize Emotion Wheel (Doughnut Chart)
        this.initEmotionWheelChart();

        // Initialize Mood Timeline (Line Chart)
        this.initMoodTimelineChart();
    }

    /**
     * Initialize Emotion Wheel Chart (Doughnut)
     */
    initEmotionWheelChart() {
        const canvas = document.getElementById('emotion-wheel-chart');
        if (!canvas) return;

        const { distribution } = this.data;

        const ctx = canvas.getContext('2d');
        this.charts.emotionWheel = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: distribution.map(e => e.name_it),
                datasets: [{
                    data: distribution.map(e => e.count),
                    backgroundColor: distribution.map(e => e.color_hex),
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false // We show custom legend below
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const emotion = distribution[context.dataIndex];
                                return `${emotion.name_it}: ${emotion.count} audio (${emotion.percentage.toFixed(1)}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Initialize Mood Timeline Chart (Line)
     * V12.2: Fixed - no fill overlap, distinct lines with points
     */
    initMoodTimelineChart() {
        const canvas = document.getElementById('mood-timeline-chart');
        if (!canvas) return;

        const { timeline } = this.data;

        const ctx = canvas.getContext('2d');
        this.charts.moodTimeline = new Chart(ctx, {
            type: 'line',
            data: {
                labels: timeline.dates,
                datasets: [
                    {
                        label: 'Positive',
                        data: timeline.positive_counts,
                        borderColor: '#10B981',
                        backgroundColor: '#10B981',
                        borderWidth: 3,
                        fill: false,  // No fill - clean line
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#10B981',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        order: 2,
                    },
                    {
                        label: 'Negative',
                        data: timeline.negative_counts,
                        borderColor: '#EF4444',
                        backgroundColor: '#EF4444',
                        borderWidth: 3,
                        fill: false,  // No fill - clean line
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#EF4444',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        order: 1,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: '#9CA3AF',
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 20,
                            font: { size: 12 },
                        },
                        title: {
                            display: true,
                            text: 'Clicca per escludere',
                            color: '#6B7280',
                            font: { size: 10, style: 'italic' },
                            padding: { top: 4 },
                        },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 24, 39, 0.95)',
                        titleColor: '#F3F4F6',
                        bodyColor: '#D1D5DB',
                        borderColor: 'rgba(139, 92, 246, 0.3)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                const label = context.dataset.label || '';
                                const value = context.parsed.y || 0;
                                return `${label}: ${value} emozioni`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#6B7280' },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        ticks: {
                            color: '#9CA3AF',
                            stepSize: 1,
                        }
                    }
                }
            }
        });
    }

    /**
     * Render error state
     */
    renderError(message) {
        console.error('[ProfileDashboard] Error:', message);

        this.container.innerHTML = `
            <div class="dashboard-error">
                <div class="error-icon">⚠️</div>
                <h2>Errore nel Caricamento</h2>
                <p class="error-message">${message}</p>
                <button class="btn-retry" onclick="location.reload()">
                    Riprova
                </button>
            </div>
        `;
    }

    /**
     * Load Evoked Emotions Analytics (ENTERPRISE GALAXY)
     *
     * Shows emotions that user's posts evoke in other users
     * Async load to not block main dashboard rendering
     * Uses caching on backend (30min TTL)
     */
    async loadEvokedEmotions(days = 30) {
        const container = document.getElementById('evoked-emotions-container');
        if (!container) return;

        try {
            const response = await fetch(`/api/profile/evoked-emotions?days=${days}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            // ENTERPRISE FIX: evoked_emotions is an object with emotion_distribution array
            if (data.success && data.evoked_emotions && data.evoked_emotions.emotion_distribution) {
                container.innerHTML = this.renderEvokedEmotions(
                    data.evoked_emotions.emotion_distribution,
                    data.evoked_emotions.period_days
                );
            } else {
                container.innerHTML = '<p class="text-muted">Nessun dato disponibile</p>';
            }

        } catch (error) {
            console.error('[ProfileDashboard] Failed to load evoked emotions:', error);
            container.innerHTML = '<p class="text-error">Errore nel caricamento</p>';
        }
    }

    /**
     * Render Evoked Emotions Section
     * Shows horizontal emotion bars with counts and percentages
     */
    renderEvokedEmotions(emotions, days) {
        if (!emotions || emotions.length === 0) {
            return `
                <div class="evoked-emotions-empty">
                    <h3>💫 Emozioni che Evochi</h3>
                    <p class="text-muted">Quando altri reagiranno ai tuoi audio, vedrai qui quali emozioni evochi in loro.</p>
                </div>
            `;
        }

        const emotionsHTML = emotions.map(emotion => {
            const percentage = parseFloat(emotion.percentage) || 0;
            const barColor = this.getEmotionColor(emotion.emotion_id);

            return `
                <div class="evoked-emotion-item">
                    <div class="emotion-label">
                        <span class="emotion-icon">${emotion.icon_emoji}</span>
                        <span class="emotion-name">${emotion.name_it}</span>
                    </div>
                    <div class="emotion-bar-container">
                        <div class="emotion-bar" style="width: ${percentage}%; background: ${barColor}"></div>
                    </div>
                    <div class="emotion-stats">
                        <span class="emotion-count">${emotion.count}</span>
                        <span class="emotion-percentage">${percentage.toFixed(1)}%</span>
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="evoked-emotions-section">
                <div class="section-header">
                    <h3>💫 Emozioni che Evochi negli Altri</h3>
                    <p class="section-subtitle">Ultimi ${days} giorni • Basato su ${emotions.reduce((sum, e) => sum + e.count, 0)} reazioni</p>
                </div>
                <div class="evoked-emotions-list">
                    ${emotionsHTML}
                </div>
            </div>
        `;
    }

    /**
     * Get color for emotion ID
     * ENTERPRISE: Consistent color scheme across dashboard
     */
    getEmotionColor(emotionId) {
        const colors = {
            1: '#FBBF24', // Gioia - Yellow
            2: '#F97316', // Meraviglia - Orange
            3: '#EF4444', // Amore - Red
            4: '#10B981', // Gratitudine - Green
            5: '#3B82F6', // Speranza - Blue
            6: '#60A5FA', // Tristezza - Light Blue
            7: '#DC2626', // Rabbia - Dark Red
            8: '#A855F7', // Ansia - Purple
            9: '#6B7280', // Paura - Gray
            10: '#4B5563'  // Solitudine - Dark Gray
        };
        return colors[emotionId] || '#8B5CF6';
    }

    /**
     * Destroy dashboard (cleanup)
     */
    destroy() {
        // Destroy Chart.js instances
        Object.values(this.charts).forEach(chart => {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });

        this.charts = {};
        this.data = null;
    }
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProfileDashboard;
}

// Auto-initialize if DOM is ready and container exists
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('profile-dashboard')) {
            window.profileDashboard = new ProfileDashboard();
        }
    });
} else {
    if (document.getElementById('profile-dashboard')) {
        window.profileDashboard = new ProfileDashboard();
    }
}
