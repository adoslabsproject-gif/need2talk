/**
 * =============================================================================
 * JOURNAL CALENDAR SIDEBAR - ENTERPRISE GALAXY+ (Vertical Day List)
 * =============================================================================
 *
 * Classic vertical calendar list with month/year navigation.
 * Shows ALL days of the month in a vertical scrollable list.
 * Format: "1 Lunedì", "2 Martedì", etc.
 *
 * FEATURES:
 * - Month/Year navigation (← →)
 * - Navigation limits: registration date → current month
 * - ALL days of month in vertical list
 * - Days with entries show emotion indicators
 * - Click day to filter timeline
 * - Today highlighted
 * - Trash access
 *
 * @package need2talk/Lightning
 * @version 5.0.0 - Vertical Day List Calendar
 */

(function() {
    'use strict';

    if (window.JournalCalendarSidebar) {
        console.warn('[JournalCalendarSidebar] Already initialized');
        return;
    }

    /**
     * 10 Emotions from database (emotions table)
     */
    const EMOTION_CONFIG = {
        1: { name: 'Gioia', emoji: '😊', color: '#FFD700' },
        2: { name: 'Meraviglia', emoji: '🎉', color: '#FF6B35' },
        3: { name: 'Amore', emoji: '❤️', color: '#FF1493' },
        4: { name: 'Gratitudine', emoji: '🙏', color: '#32CD32' },
        5: { name: 'Speranza', emoji: '🌟', color: '#87CEEB' },
        6: { name: 'Tristezza', emoji: '😢', color: '#4682B4' },
        7: { name: 'Rabbia', emoji: '😠', color: '#DC143C' },
        8: { name: 'Ansia', emoji: '😰', color: '#FF8C00' },
        9: { name: 'Paura', emoji: '😨', color: '#8B008B' },
        10: { name: 'Solitudine', emoji: '😔', color: '#696969' }
    };

    /**
     * Italian weekday names (full)
     */
    const WEEKDAYS_IT = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];

    /**
     * Italian month names
     */
    const MONTHS_IT = [
        'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
        'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'
    ];

    class JournalCalendarSidebar {
        constructor(containerId = 'journal-calendar-sidebar') {
            this.container = document.getElementById(containerId);

            this.apiEndpoints = {
                calendarData: '/api/journal/calendar-emotions'
            };

            // Current view
            this.today = new Date();
            this.today.setHours(0, 0, 0, 0);

            this.currentMonth = this.today.getMonth();
            this.currentYear = this.today.getFullYear();

            // Navigation limits
            this.minDate = null;
            this.maxDate = this.today;

            // Data
            this.calendarData = new Map();
            this.selectedDate = null;
            this.trashCount = 0;

            // Cache
            this.CACHE_KEY = 'journal_calendar_cache';
            this.CACHE_TTL = 5 * 60 * 1000;

            // Callbacks
            this.onDateSelect = null;
            this.onTrashClick = null;
        }

        async init(parentContainer, onDateSelect, onTrashClick) {
            this.onDateSelect = onDateSelect;
            this.onTrashClick = onTrashClick;

            if (!this.container) {
                this.container = document.createElement('div');
                this.container.id = 'journal-calendar-sidebar';
                this.container.className = 'journal-calendar-sidebar';
                parentContainer.prepend(this.container);
            }

            // Get registration date
            const regDate = this.container.dataset.userRegistration;
            if (regDate) {
                this.minDate = new Date(regDate);
                this.minDate.setHours(0, 0, 0, 0);
            } else {
                this.minDate = new Date(this.today);
                this.minDate.setFullYear(this.minDate.getFullYear() - 1);
            }

            this.renderSkeleton();
            await this.loadCalendarData();
            this.render();
            this.attachEventListeners();
        }

        async loadCalendarData() {
            const cached = this.getFromCache();
            if (cached) {
                this.calendarData = new Map(Object.entries(cached.data));
                this.trashCount = cached.trashCount || 0;
                return;
            }

            try {
                const response = await api.get(this.apiEndpoints.calendarData);
                if (response.success && response.calendar_data) {
                    this.calendarData.clear();
                    for (const [date, data] of Object.entries(response.calendar_data)) {
                        this.calendarData.set(date, {
                            emotions: data.emotions || [],
                            entryCount: data.entry_count || 0
                        });
                    }
                    this.trashCount = response.trash_count || 0;
                    this.saveToCache(response.calendar_data, this.trashCount);
                }
            } catch (error) {
                console.error('[JournalCalendarSidebar] Load error:', error);
            }
        }

        renderSkeleton() {
            this.container.innerHTML = `
                <div class="calendar-loading">
                    <div class="calendar-loading-spinner"></div>
                    <span>Caricamento...</span>
                </div>
            `;
        }

        render() {
            const canGoPrev = this.canNavigatePrev();
            const canGoNext = this.canNavigateNext();

            this.container.innerHTML = `
                <!-- Month/Year Navigation -->
                <div class="calendar-nav-header">
                    <button class="calendar-nav-btn ${canGoPrev ? '' : 'disabled'}"
                            data-action="prev-month" ${canGoPrev ? '' : 'disabled'}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <div class="calendar-month-year">
                        <span class="month-name">${MONTHS_IT[this.currentMonth]}</span>
                        <span class="year">${this.currentYear}</span>
                    </div>
                    <button class="calendar-nav-btn ${canGoNext ? '' : 'disabled'}"
                            data-action="next-month" ${canGoNext ? '' : 'disabled'}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <!-- Days List (Vertical) -->
                <div class="calendar-days-list">
                    ${this.renderDaysList()}
                </div>

                <!-- Today Button -->
                <div class="calendar-actions">
                    <button class="calendar-today-btn" data-action="today">
                        📍 Vai a Oggi
                    </button>
                </div>

                <!-- Trash -->
                <div class="calendar-trash-section">
                    <button class="calendar-trash-btn" data-action="trash">
                        🗑️ Cestino ${this.trashCount > 0 ? `<span class="trash-badge">${this.trashCount}</span>` : ''}
                    </button>
                </div>
            `;
        }

        /**
         * Render vertical list of ALL days in month
         * Format: "1 Lunedì" with emotions if present
         */
        renderDaysList() {
            const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
            let html = '';

            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(this.currentYear, this.currentMonth, day);
                const dateStr = this.formatDateStr(this.currentYear, this.currentMonth, day);
                const weekdayName = WEEKDAYS_IT[date.getDay()];

                const dayData = this.calendarData.get(dateStr);
                const hasEntries = dayData && dayData.entryCount > 0;
                const isToday = this.isSameDay(date, this.today);
                const isSelected = dateStr === this.selectedDate;
                const isFuture = date > this.today;
                const isPastMin = this.minDate && date < this.minDate;
                const isDisabled = isFuture || isPastMin;

                // Classes
                const classes = ['calendar-day-row'];
                if (hasEntries) classes.push('has-entries');
                if (isToday) classes.push('is-today');
                if (isSelected) classes.push('is-selected');
                if (isDisabled) classes.push('is-disabled');

                // Emotion dots
                let emotionDots = '';
                if (hasEntries && dayData.emotions) {
                    const topEmotions = dayData.emotions.slice(0, 3);
                    emotionDots = topEmotions.map(emo => {
                        const cfg = EMOTION_CONFIG[emo.emotion_id];
                        return cfg ? `<span class="emotion-dot" style="background:${cfg.color}" title="${cfg.name}"></span>` : '';
                    }).join('');
                }

                html += `
                    <button class="${classes.join(' ')}"
                            data-date="${dateStr}"
                            data-action="select-date"
                            ${isDisabled ? 'disabled' : ''}>
                        <span class="day-number">${day}</span>
                        <span class="day-name">${weekdayName}</span>
                        ${isToday ? '<span class="today-badge">OGGI</span>' : ''}
                        ${emotionDots ? `<span class="day-emotions">${emotionDots}</span>` : ''}
                        ${hasEntries ? `<span class="entry-count">${dayData.entryCount}</span>` : ''}
                    </button>
                `;
            }

            return html;
        }

        canNavigatePrev() {
            if (!this.minDate) return true;
            const prevMonth = new Date(this.currentYear, this.currentMonth - 1, 1);
            return prevMonth >= new Date(this.minDate.getFullYear(), this.minDate.getMonth(), 1);
        }

        canNavigateNext() {
            const nextMonth = new Date(this.currentYear, this.currentMonth + 1, 1);
            return nextMonth <= new Date(this.today.getFullYear(), this.today.getMonth(), 1);
        }

        goToPrevMonth() {
            if (!this.canNavigatePrev()) return;
            this.currentMonth--;
            if (this.currentMonth < 0) {
                this.currentMonth = 11;
                this.currentYear--;
            }
            this.render();
            this.attachEventListeners();
        }

        goToNextMonth() {
            if (!this.canNavigateNext()) return;
            this.currentMonth++;
            if (this.currentMonth > 11) {
                this.currentMonth = 0;
                this.currentYear++;
            }
            this.render();
            this.attachEventListeners();
        }

        goToToday() {
            this.currentMonth = this.today.getMonth();
            this.currentYear = this.today.getFullYear();
            this.selectedDate = this.formatDateStr(this.today.getFullYear(), this.today.getMonth(), this.today.getDate());
            this.render();
            this.attachEventListeners();
            if (this.onDateSelect) this.onDateSelect(this.selectedDate);
        }

        isSameDay(d1, d2) {
            return d1.getFullYear() === d2.getFullYear() &&
                   d1.getMonth() === d2.getMonth() &&
                   d1.getDate() === d2.getDate();
        }

        formatDateStr(year, month, day) {
            return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        }

        attachEventListeners() {
            // Navigation
            this.container.querySelector('[data-action="prev-month"]')?.addEventListener('click', () => this.goToPrevMonth());
            this.container.querySelector('[data-action="next-month"]')?.addEventListener('click', () => this.goToNextMonth());
            this.container.querySelector('[data-action="today"]')?.addEventListener('click', () => this.goToToday());

            // Trash
            const trashBtn = this.container.querySelector('[data-action="trash"]');
            if (trashBtn && this.onTrashClick) {
                trashBtn.addEventListener('click', () => this.onTrashClick());
            }

            // Day selection
            this.container.querySelectorAll('[data-action="select-date"]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const dateStr = btn.dataset.date;
                    this.selectDate(dateStr);
                });
            });
        }

        selectDate(dateStr) {
            const prev = this.container.querySelector('.calendar-day-row.is-selected');
            if (prev) prev.classList.remove('is-selected');

            const curr = this.container.querySelector(`[data-date="${dateStr}"]`);
            if (curr) curr.classList.add('is-selected');

            this.selectedDate = dateStr;
            if (this.onDateSelect) this.onDateSelect(dateStr);
        }

        async refresh() {
            this.clearCache();
            await this.loadCalendarData();
            this.render();
            this.attachEventListeners();
        }

        getFromCache() {
            try {
                const cached = localStorage.getItem(this.CACHE_KEY);
                if (!cached) return null;
                const { data, trashCount, timestamp } = JSON.parse(cached);
                if (Date.now() - timestamp > this.CACHE_TTL) {
                    localStorage.removeItem(this.CACHE_KEY);
                    return null;
                }
                return { data, trashCount };
            } catch (e) { return null; }
        }

        saveToCache(data, trashCount) {
            try {
                localStorage.setItem(this.CACHE_KEY, JSON.stringify({ data, trashCount, timestamp: Date.now() }));
            } catch (e) {}
        }

        clearCache() {
            localStorage.removeItem(this.CACHE_KEY);
        }
    }

    window.JournalCalendarSidebar = JournalCalendarSidebar;
})();
