/**
 * Chart.js Bundle for need2talk Enterprise
 * Self-hosted Charts - No external CDN dependencies
 */

import {
    Chart,
    CategoryScale,
    LinearScale,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    DoughnutController,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
} from 'chart.js';

// Register Chart.js components
Chart.register(
    CategoryScale,
    LinearScale,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    DoughnutController,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

// Export Chart globally for use in inline scripts
window.Chart = Chart;

// Set default Chart.js configuration for need2talk theme
Chart.defaults.color = '#94a3b8'; // slate-400
Chart.defaults.borderColor = '#334155'; // slate-700
Chart.defaults.backgroundColor = '#1e293b'; // slate-800
Chart.defaults.font.family = "'Inter', system-ui, -apple-system, sans-serif";
Chart.defaults.plugins.legend.display = false; // Hide legends by default

console.log('✅ Chart.js loaded (self-hosted v' + Chart.version + ')');
