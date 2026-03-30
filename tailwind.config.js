/** 
 * Tailwind CSS Configuration - Enterprise need2talk
 * 
 * Optimized for 100k+ concurrent users with:
 * - Intelligent content scanning
 * - Performance-first utilities
 * - Component-driven architecture
 * - Critical path optimization
 * - Real-time monitoring integration
 * 
 * @type {import('tailwindcss').Config} 
 */
module.exports = {
  content: [
    "./app/Views/**/*.php",
    "./public/**/*.js",
    "./public/**/*.html",
    "./public/assets/js/**/*.js",
    // CSRF.js integration
    "./public/assets/js/core/csrf.js",
    // Enterprise components
    "./app/Components/**/*.php",
    // Moderation Portal CSS components
    "./src/components/moderation-portal.css"
  ],

  // Safelist for dynamically generated classes
  // Note: These classes are generated dynamically in JS template strings
  safelist: [
    // ============================================================================
    // DM CHAT HEIGHT (dm.php) - ENTERPRISE V10.40 (2025-12-05)
    // ============================================================================
    'h-[calc(100dvh-5rem)]',
    'sm:h-[calc(100vh-5rem)]',

    // ============================================================================
    // LIGHTBOX LAYOUT (PhotoLightbox.js) - ENTERPRISE V8.0 (2025-12-01)
    // ============================================================================
    // Desktop layout: photo LEFT 55%, sidebar RIGHT 45%
    // CRITICAL: Must use string format for arbitrary values (not regex)
    'lg:flex-row',
    'lg:w-[55%]',
    'lg:w-[45%]',
    'lg:h-full',
    'lg:h-[92vh]',
    'lg:w-[96vw]',
    'lg:min-w-[400px]',
    'lg:overflow-hidden',
    'lg:max-h-[92vh]',
    'max-h-[90vh]',
    'max-h-[92vh]',
    // Mobile layout
    'flex-col',
    'overflow-y-auto',
    // Lightbox close button
    'lightbox-close-global',

    // Core layout
    'sr-only',
    'peer',
    'relative',
    'inline-flex',
    'items-center',
    'cursor-pointer',
    'opacity-60',

    // Toggle switch dimensions
    'w-10',
    'w-11',
    'h-5',
    'h-6',

    // Background colors
    'bg-gray-600',
    'bg-gray-700/50',
    'bg-gray-600/30',
    'bg-gray-800/30',
    'bg-white',

    // Gradients
    'bg-gradient-to-r',
    'from-purple-600',
    'to-pink-600',
    'from-red-900/20',
    'to-purple-900/20',
    'from-red-900/10',
    'to-purple-900/10',

    // Borders
    'border',
    'border-gray-300',
    'border-gray-500/30',
    'border-gray-600/50',
    'border-gray-700/50',
    'border-red-500/20',
    'border-red-500/30',
    'border-red-500/50',
    'border-purple-500/20',
    'border-purple-500/30',
    'border-purple-500/50',
    'border-blue-500/30',
    'border-blue-500/50',
    'border-orange-500/50',
    'border-white',

    // Badge backgrounds
    'bg-blue-500/20',
    'bg-blue-900/20',
    'bg-orange-500/20',
    'bg-purple-900/20',
    'bg-red-900/20',

    // Text colors
    'text-blue-300',
    'text-orange-300',
    'text-red-200',
    'text-red-300',
    'text-red-400',
    'text-purple-300',
    'text-purple-400',
    'text-white',
    'text-gray-300',
    'text-gray-400',

    // Rounded
    'rounded-full',
    'rounded-lg',

    // Focus states
    'peer-focus:outline-none',
    'peer-focus:ring-4',
    'peer-focus:ring-purple-800',

    // Checked states
    'peer-checked:after:translate-x-full',
    'peer-checked:after:border-white',
    'peer-checked:bg-gradient-to-r',
    'peer-checked:from-purple-600',
    'peer-checked:to-pink-600',

    // After pseudo-element
    'after:content-[\'\']',
    'after:absolute',
    'after:top-[1px]',
    'after:top-[2px]',
    'after:left-[1px]',
    'after:left-[2px]',
    'after:bg-white',
    'after:border-gray-300',
    'after:border',
    'after:rounded-full',
    'after:h-4',
    'after:h-5',
    'after:w-4',
    'after:w-5',
    'after:transition-all',

    // Transitions
    'transition-colors',
    'transition-transform',

    // Hover states
    'hover:bg-gray-700',
    'hover:bg-gray-600',
    'hover:text-white',
    'hover:border-purple-500/50'
  ],

  // Dark mode support via class
  darkMode: 'class',

  // Enterprise CSS compatibility mode
  corePlugins: {
    // Ensure @apply works properly
    applyComplexClasses: true
  },
  theme: {
    colors: {
      transparent: 'transparent',
      current: 'currentColor',
      white: '#ffffff',
      black: '#000000',
      gray: {
        50: '#f9fafb',
        100: '#f3f4f6',
        200: '#e5e7eb',
        300: '#d1d5db',
        400: '#9ca3af',
        500: '#6b7280',
        600: '#4b5563',
        700: '#374151',
        800: '#1f2937',
        850: '#1e293b',  // ENTERPRISE: Warmer dark background
        900: '#111827',
        950: '#0f172a',  // ENTERPRISE: Deep navy (old bg)
      },
      red: {
        50: '#fef2f2',
        100: '#fee2e2',
        200: '#fecaca',
        300: '#fca5a5',
        400: '#f87171',
        500: '#ef4444',
        600: '#dc2626',
        700: '#b91c1c',
        800: '#991b1b',
        900: '#7f1d1d',
      },
      yellow: {
        50: '#fffbeb',
        100: '#fef3c7',
        200: '#fde68a',
        300: '#fcd34d',
        400: '#fbbf24',
        500: '#f59e0b',
        600: '#d97706',
        700: '#b45309',
        800: '#92400e',
        900: '#78350f',
      },
      green: {
        50: '#ecfdf5',
        100: '#d1fae5',
        200: '#a7f3d0',
        300: '#6ee7b7',
        400: '#34d399',
        500: '#10b981',
        600: '#059669',
        700: '#047857',
        800: '#065f46',
        900: '#064e3b',
      },
      blue: {
        50: '#eff6ff',
        100: '#dbeafe',
        200: '#bfdbfe',
        300: '#93c5fd',
        400: '#60a5fa',
        500: '#3b82f6',
        600: '#2563eb',
        700: '#1d4ed8',
        800: '#1e40af',
        900: '#1e3a8a',
      },
      purple: {
        50: '#faf5ff',
        100: '#f3e8ff',
        200: '#e9d5ff',
        300: '#d8b4fe',
        400: '#c084fc',
        500: '#a78bfa',  // ENTERPRISE: Softer purple (was #a855f7)
        600: '#9333ea',
        700: '#7c3aed',
        800: '#6d28d9',
        900: '#581c87',
      },
      pink: {
        50: '#fdf2f8',
        100: '#fce7f3',
        200: '#fbcfe8',
        300: '#f9a8d4',
        400: '#f472b6',
        500: '#ec4899',
        600: '#db2777',
        700: '#be185d',
        800: '#9d174d',
        900: '#831843',
      },
      // ENTERPRISE PSYCHOLOGY: Teal/Cyan for audio social platform
      // Psychology: Calming, creative, audio waves, trustworthy
      teal: {
        50: '#f0fdfa',
        100: '#ccfbf1',
        200: '#99f6e4',
        300: '#5eead4',
        400: '#2dd4bf',
        500: '#14b8a6',
        600: '#0d9488',
        700: '#0f766e',
        800: '#115e59',
        900: '#134e4a',
      },
    },

    // Enterprise theme extensions
    extend: {
      // need2talk MIDNIGHT AURORA (v4.1 - 2025-01-13)
      // ENTERPRISE: Flattened palette for Tailwind utility class generation
      // Dark, mysterious, modern - Psychology: Creativity, audio waves, premium feel
      colors: {
        // 🌑 DARK FOUNDATION (Nero/Grigi scuri)
        'brand-midnight': '#0F0F23',    // Nero bluastro (background principale)
        'brand-charcoal': '#1A1A2E',    // Charcoal scuro (cards, containers)
        'brand-slate': '#16213E',       // Slate blu scuro (headers, sidebars)

        // 💜 AURORA LIGHTS (Viola/Purple accents) - WCAG AAA CONTRAST (9:1+)
        'accent-violet': '#E5D4FF',      // Lavanda ultra chiara (CTA primari) - 9.7:1 contrast
        'accent-purple': '#F0E6FF',      // Lavanda ancora più chiara (hover states) - 11.2:1 contrast
        'accent-lavender': '#F5EFFF',    // Lavanda ultra ultra chiara (highlights) - 13:1 contrast

        // 🌊 COOL ACCENTS (Cyan/Teal - audio waves vibe)
        'cool-cyan': '#00F5FF',          // Cyan brillante (success, notifications)
        'cool-teal': '#00D9FF',          // Teal elettrico (info, active states)
        'cool-ice': '#7DF9FF',           // Ice blue (subtle highlights)

        // ⚡ ENERGY (Warm accents per CTA importanti) - DARK for white text contrast
        'energy-pink': '#6D28D9',        // Purple scuro (white text 5.5:1 contrast) - era rosso fluo
        'energy-magenta': '#7C3AED',     // Purple scuro medio (white text 4.8:1 contrast)

        // 🤍 NEUTRALS (Testo e struttura) - PURE WHITE for maximum clarity
        'neutral-white': '#FFFFFF',      // Pure white (testo primario) - BRIGHT
        'neutral-silver': '#F0F0F0',     // Silver chiaro (testo secondario) - upgraded from E5E5E5
        'neutral-gray': '#A0A0A0',       // Gray (placeholders, disabled)
        'neutral-darkGray': '#4A4A4A',   // Dark gray (borders su dark bg)
      },
      
      // Enterprise typography
      fontSize: {
        'xs': '0.75rem',
        'sm': '0.875rem',
        'base': '1rem',
        'lg': '1.125rem',
        'xl': '1.25rem',
        '2xl': '1.5rem',
        '3xl': '1.875rem',
        '4xl': '2.25rem',
        '5xl': '3rem',
        '6xl': '3.75rem',
        '7xl': '4.5rem',
        '8xl': '6rem',
        '9xl': '8rem',
        '10xl': '10rem',
        '11xl': '13rem',      
        '12xl': '35rem',      // 560px - original
        'ultra': '15.625rem', // 250px - originale +10px
        'mega': '35.625rem'   // 570px - originale +10px
      },
      
      // Performance optimized spacing
      spacing: {
        '72': '18rem',
        '84': '21rem',
        '96': '24rem'
      },
      
      // Animation for real-time features
      animation: {
        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'bounce-subtle': 'bounce 2s infinite',
        'fade-in': 'fadeIn 0.5s ease-in-out',
        'slide-up': 'slideUp 0.3s ease-out'
      },
      
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' }
        },
        slideUp: {
          '0%': { transform: 'translateY(10px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' }
        }
      }
    }
  },
  
  // Enterprise plugins for advanced features
  plugins: [
    // REMOVED: @tailwindcss/forms (unused - custom forms instead)
    // REMOVED: @tailwindcss/typography (unused - no blog/content)
    // REMOVED: @tailwindcss/aspect-ratio (unused)

    // Custom plugin for need2talk specific utilities
    function({ addUtilities, addComponents, addBase }) {
      // FIX: Select dropdown styling (readable contrast)
      addBase({
        // Select element itself - always white text (even when focused/active)
        'select': {
          color: '#ffffff !important',  // Force white text always
        },
        'select:focus': {
          color: '#ffffff !important',  // Keep white when dropdown open
        },
        // Dropdown options
        'select option': {
          backgroundColor: '#1a1a2e',  // brand-midnight (dark)
          color: '#ffffff',             // white text
        },
        'select option:checked, select option:hover, select option:focus': {
          backgroundColor: '#ffffff',   // white background when selected
          color: '#000000',             // black text (readable on white)
        },
      });

      // Enterprise button components - Midnight Aurora PREMIUM
      addComponents({
        '.btn-primary': {
          '@apply relative overflow-hidden bg-gradient-to-r from-accent-violet to-accent-purple hover:from-accent-purple hover:to-accent-lavender text-neutral-white font-bold py-3 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5': {},
          'box-shadow': '0 10px 25px -5px rgba(123, 44, 191, 0.5), 0 0 20px rgba(123, 44, 191, 0.3)',
          '&:hover': {
            'box-shadow': '0 15px 35px -5px rgba(157, 78, 221, 0.6), 0 0 30px rgba(199, 125, 255, 0.4)',
          },
          '&::before': {
            'content': '""',
            'position': 'absolute',
            'top': '0',
            'left': '-100%',
            'width': '100%',
            'height': '100%',
            'background': 'linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent)',
            'transition': 'left 0.5s',
          },
          '&:hover::before': {
            'left': '100%',
          }
        },
        '.btn-secondary': {
          '@apply relative bg-gradient-to-r from-cool-teal to-cool-cyan hover:from-cool-cyan hover:to-cool-ice text-brand-midnight font-bold py-3 px-6 rounded-xl transition-all duration-300 transform hover:scale-105': {},
          'box-shadow': '0 8px 20px -5px rgba(0, 217, 255, 0.4), 0 0 15px rgba(0, 245, 255, 0.2)',
          '&:hover': {
            'box-shadow': '0 12px 30px -5px rgba(0, 245, 255, 0.6), 0 0 25px rgba(125, 249, 255, 0.4)',
          }
        },
        '.btn-danger': {
          '@apply relative bg-gradient-to-r from-energy-pink to-energy-magenta hover:from-energy-magenta hover:to-energy-pink text-neutral-white font-bold py-3 px-6 rounded-xl transition-all duration-300 transform hover:scale-105': {},
          'box-shadow': '0 8px 20px -5px rgba(255, 0, 110, 0.5), 0 0 15px rgba(255, 0, 110, 0.3)',
          '&:hover': {
            'box-shadow': '0 12px 30px -5px rgba(233, 30, 140, 0.6), 0 0 25px rgba(255, 0, 110, 0.4)',
          }
        },
        '.card': {
          '@apply bg-gradient-to-br from-brand-charcoal to-brand-slate rounded-xl shadow-2xl p-6 border border-accent-violet/20 text-neutral-white backdrop-blur-xl': {},
          'box-shadow': '0 10px 40px -10px rgba(123, 44, 191, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.05)',
        },
        '.card-dark': {
          '@apply bg-gradient-to-br from-brand-midnight to-brand-charcoal rounded-xl shadow-2xl p-6 border border-cool-cyan/20 text-neutral-white backdrop-blur-xl': {},
          'box-shadow': '0 10px 40px -10px rgba(0, 245, 255, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.03)',
        }
      });
      
      // Enterprise utilities for performance
      addUtilities({
        '.gpu-accelerate': {
          'transform': 'translateZ(0)',
          'backface-visibility': 'hidden',
          'perspective': '1000'
        },
        '.smooth-scroll': {
          'scroll-behavior': 'smooth'
        },
        '.text-shadow-sm': {
          'text-shadow': '0 1px 2px rgba(0, 0, 0, 0.05)'
        },
        '.text-shadow': {
          'text-shadow': '0 1px 3px rgba(0, 0, 0, 0.1)'
        }
      });
    }
  ],
  
  // Performance optimizations for 100k+ users
  experimental: {
    optimizeUniversalDefaults: true
  },
  
  // Enterprise-grade configuration
  future: {
    hoverOnlyWhenSupported: true,
    respectDefaultRingColorOpacity: true
  }
};