// Tailwind Configuration for need2talk
// This replaces the CDN config with local compiled CSS

// Colors configuration that mirrors our compiled CSS
window.tailwindColors = {
  purple: {
    500: '#9333ea',
    600: '#7c3aed', 
    700: '#6d28d9',
    400: '#a855f7'
  },
  pink: {
    500: '#ec4899',
    600: '#db2777',
    400: '#f472b6'
  }
};

// Add any custom utilities that aren't in the compiled CSS
if (typeof document !== 'undefined') {
  const customStyles = `
    <style>
    .animate-pulse-slow {
      animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    .animation-delay-200 {
      animation-delay: 200ms;
    }
    .animation-delay-400 {
      animation-delay: 400ms;
    }
    </style>
  `;
  document.head.insertAdjacentHTML('beforeend', customStyles);
}