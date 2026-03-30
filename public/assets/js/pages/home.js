/**
 * =================================================================
 * HOME PAGE - JAVASCRIPT FUNCTIONALITY
 * =================================================================
 * Sicuro per migliaia di utenti simultanei
 * Performance ottimizzate e anti-malicious
 * =================================================================
 */

'use strict';

// Home Page Controller
class HomePage {
    constructor() {
        this.isInitialized = false;
        this.stats = {
            users: 0,
            messages: 0,
            communities: 0,
            liveUsers: 0
        };
        this.animationFrame = null;
        this.observers = [];
        
        this.init();
    }

    /**
     * Initialize home page functionality
     */
    init() {
        if (this.isInitialized) return;
        
        this.bindEvents();
        this.setupAnimations();
        this.loadStats();
        this.setupParticles();
        this.isInitialized = true;
        
        Need2Talk.Logger.info('HomePage', 'Home page initialized successfully');
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // CTA Buttons
        document.addEventListener('click', this.handleCTAClick.bind(this));
        
        // Feature cards interactions
        const featureCards = document.querySelectorAll('.feature-card');
        featureCards.forEach(card => {
            card.addEventListener('click', this.handleFeatureClick.bind(this));
        });

        // Scroll animations
        this.setupScrollAnimations();
    }

    /**
     * Handle CTA button clicks
     */
    handleCTAClick(event) {
        const button = event.target.closest('.hero-btn');
        if (!button) return;

        event.preventDefault();
        
        // Add click animation
        button.style.transform = 'scale(0.95)';
        setTimeout(() => {
            button.style.transform = '';
        }, 150);

        const action = button.dataset.action;
        switch (action) {
            case 'register':
                this.navigateToRegister();
                break;
            case 'login':
                this.navigateToLogin();
                break;
            case 'learn-more':
                this.scrollToFeatures();
                break;
            default:
                window.location.href = button.href;
        }
    }

    /**
     * Navigate to register page
     */
    navigateToRegister() {
        window.location.href = '/auth/register';
    }

    /**
     * Navigate to login page  
     */
    navigateToLogin() {
        window.location.href = '/auth/login';
    }

    /**
     * Scroll to features section
     */
    scrollToFeatures() {
        const featuresSection = document.querySelector('.features-section');
        if (featuresSection) {
            featuresSection.scrollIntoView({ 
                behavior: 'smooth',
                block: 'start' 
            });
        }
    }

    /**
     * Handle feature card clicks
     */
    handleFeatureClick(event) {
        const card = event.currentTarget;
        const feature = card.dataset.feature;
        
        // Add interaction feedback
        card.classList.add('clicked');
        setTimeout(() => {
            card.classList.remove('clicked');
        }, 200);

        // Track feature interest
        this.trackFeatureInterest(feature);
    }

    /**
     * Setup scroll animations
     */
    setupScrollAnimations() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                }
            });
        }, observerOptions);

        // Observe animated elements
        const animatedElements = document.querySelectorAll(
            '.stat-card, .feature-card, .stats-title, .features-title'
        );
        
        animatedElements.forEach(el => {
            observer.observe(el);
        });

        this.observers.push(observer);
    }

    /**
     * Setup page animations
     */
    setupAnimations() {
        // Staggered animation for cards
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach((card, index) => {
            card.style.animationDelay = `${0.2 + index * 0.1}s`;
        });

        const featureCards = document.querySelectorAll('.feature-card');
        featureCards.forEach((card, index) => {
            card.style.animationDelay = `${0.4 + index * 0.1}s`;
        });
    }

    /**
     * Load and animate statistics
     */
    async loadStats() {
        try {
            // Simulate loading stats (replace with actual API call)
            const response = await this.fetchStats();
            this.animateStats(response);
        } catch (error) {
            Need2Talk.Logger.warn('HomePage', 'Could not load stats', error);
            // Use default values
            this.animateStats({
                users: 15000,
                messages: 250000,
                communities: 150,
                liveUsers: 1200
            });
        }
    }

    /**
     * Fetch stats from API
     */
    async fetchStats() {
        // Placeholder for actual API call
        return new Promise(resolve => {
            setTimeout(() => {
                resolve({
                    users: Math.floor(Math.random() * 20000) + 10000,
                    messages: Math.floor(Math.random() * 500000) + 200000,
                    communities: Math.floor(Math.random() * 300) + 100,
                    liveUsers: Math.floor(Math.random() * 2000) + 500
                });
            }, 1000);
        });
    }

    /**
     * Animate statistics counters
     */
    animateStats(targetStats) {
        const duration = 2000; // 2 seconds
        const startTime = Date.now();
        
        const animate = () => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function
            const easeOut = 1 - Math.pow(1 - progress, 3);
            
            // Update each stat
            Object.keys(targetStats).forEach(key => {
                const current = Math.floor(targetStats[key] * easeOut);
                const element = document.querySelector(`[data-stat="${key}"]`);
                if (element) {
                    element.textContent = this.formatNumber(current);
                }
            });
            
            if (progress < 1) {
                this.animationFrame = requestAnimationFrame(animate);
            }
        };
        
        animate();
    }

    /**
     * Format numbers with commas
     */
    formatNumber(num) {
        return num.toLocaleString();
    }

    /**
     * Setup particle system for visual enhancement
     */
    setupParticles() {
        const canvas = document.querySelector('#particles-canvas');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const particles = [];
        const particleCount = 50;
        
        // Set canvas size
        const resizeCanvas = () => {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        };
        
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        
        // Particle class
        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width;
                this.y = Math.random() * canvas.height;
                this.vx = (Math.random() - 0.5) * 0.5;
                this.vy = (Math.random() - 0.5) * 0.5;
                this.size = Math.random() * 2 + 1;
                this.opacity = Math.random() * 0.5 + 0.2;
            }
            
            update() {
                this.x += this.vx;
                this.y += this.vy;
                
                // Wrap around edges
                if (this.x < 0) this.x = canvas.width;
                if (this.x > canvas.width) this.x = 0;
                if (this.y < 0) this.y = canvas.height;
                if (this.y > canvas.height) this.y = 0;
            }
            
            draw() {
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(168, 85, 247, ${this.opacity})`;
                ctx.fill();
            }
        }
        
        // Create particles
        for (let i = 0; i < particleCount; i++) {
            particles.push(new Particle());
        }
        
        // Animation loop
        const animateParticles = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            particles.forEach(particle => {
                particle.update();
                particle.draw();
            });
            
            // Draw connections
            particles.forEach((particle, i) => {
                for (let j = i + 1; j < particles.length; j++) {
                    const other = particles[j];
                    const dx = particle.x - other.x;
                    const dy = particle.y - other.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    
                    if (distance < 100) {
                        ctx.beginPath();
                        ctx.moveTo(particle.x, particle.y);
                        ctx.lineTo(other.x, other.y);
                        ctx.strokeStyle = `rgba(168, 85, 247, ${0.1 * (1 - distance / 100)})`;
                        ctx.stroke();
                    }
                }
            });
            
            requestAnimationFrame(animateParticles);
        };
        
        animateParticles();
    }

    /**
     * Track feature interest for analytics
     */
    trackFeatureInterest(feature) {
        // Security: Only track allowed feature names
        const allowedFeatures = ['voice-chat', 'communities', 'privacy', 'moderation'];
        if (!allowedFeatures.includes(feature)) return;
        
        try {
            // Send to analytics (implement your tracking solution)
            Need2Talk.Logger.info('HomePage', `Feature interest tracked: ${feature}`);
        } catch (error) {
            Need2Talk.Logger.warn('HomePage', 'Analytics tracking failed', error);
        }
    }

    /**
     * Cleanup when leaving page
     */
    cleanup() {
        if (this.animationFrame) {
            cancelAnimationFrame(this.animationFrame);
        }
        
        this.observers.forEach(observer => {
            observer.disconnect();
        });
        
        this.observers = [];
        this.isInitialized = false;
        
        Need2Talk.Logger.info('HomePage', 'Home page cleaned up');
    }
}

// CSS injection for dynamic animations
const homePageStyles = `
    .stat-card {
        opacity: 0;
        transform: translateY(30px);
        transition: all 0.8s ease-out;
    }
    
    .stat-card.animate-in {
        opacity: 1;
        transform: translateY(0);
    }
    
    .feature-card {
        opacity: 0;
        transform: translateY(30px);
        transition: all 0.8s ease-out;
    }
    
    .feature-card.animate-in {
        opacity: 1;
        transform: translateY(0);
    }
    
    .feature-card.clicked {
        transform: scale(0.98);
    }
    
    #particles-canvas {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 0;
    }
    
    @media (prefers-reduced-motion: reduce) {
        .stat-card,
        .feature-card {
            transition: none;
        }
        
        #particles-canvas {
            display: none;
        }
    }
`;

// Inject styles
const styleSheet = document.createElement('style');
styleSheet.textContent = homePageStyles;
document.head.appendChild(styleSheet);

// Initialize when DOM is ready
let homePageInstance = null;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        homePageInstance = new HomePage();
    });
} else {
    homePageInstance = new HomePage();
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (homePageInstance) {
        homePageInstance.cleanup();
    }
});

// Export for potential module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = HomePage;
}

// Make available globally for debugging
window.HomePage = HomePage;