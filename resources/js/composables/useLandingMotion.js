import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Scroll motion for the landing page (Welcome.vue) — no animation library.
 *
 *  1. Scroll-triggered reveals   [data-reveal] (+ optional "fade" | "scale"
 *     value) fade/slide in once, the first time they enter the viewport.
 *     Stagger with  style="--reveal-delay: 120ms".
 *  2. Active section tracking    [data-section="id"] → `activeSection`, used
 *     to highlight the nav link of the section in view.
 *  3. Subtle parallax            [data-parallax] sections containing
 *     [data-parallax-layer="-0.06"] layers; layers drift slightly slower than
 *     the page. Only runs while the section is on screen.
 *  4. Off-screen pause           [data-parallax] sections get `.is-offscreen`
 *     when out of view so CSS can pause their looping animations.
 *
 * Design rules: only `transform` / `opacity` are animated, one passive scroll
 * listener (rAF-throttled, attached only while a parallax section is visible),
 * IntersectionObserver for everything else, native scrolling is never touched,
 * and `prefers-reduced-motion` turns the whole thing off (content is simply
 * shown).
 */
export function useLandingMotion(rootRef) {
    const activeSection = ref('');

    let revealObserver = null;
    let bandObserver = null;
    let visibilityObserver = null;
    let rafId = 0;
    let scrolling = false;
    let parallaxItems = [];
    const visibleParallax = new Set();

    const prefersReducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    const updateParallax = () => {
        rafId = 0;
        const viewportMiddle = window.innerHeight / 2;

        parallaxItems.forEach(({ section, layers }) => {
            if (!visibleParallax.has(section)) return;
            const rect = section.getBoundingClientRect();
            const offset = rect.top + rect.height / 2 - viewportMiddle;
            layers.forEach(({ el, factor }) => {
                el.style.transform = `translate3d(0, ${(offset * factor).toFixed(1)}px, 0)`;
            });
        });
    };

    const onScroll = () => {
        if (!rafId) rafId = requestAnimationFrame(updateParallax);
    };

    const syncScrollListener = () => {
        const shouldListen = visibleParallax.size > 0;
        if (shouldListen && !scrolling) {
            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll, { passive: true });
            scrolling = true;
            onScroll();
        } else if (!shouldListen && scrolling) {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
            scrolling = false;
        }
    };

    onMounted(() => {
        const root = rootRef.value;
        if (!root) return;

        const revealEls = Array.from(root.querySelectorAll('[data-reveal]'));
        const sections = Array.from(root.querySelectorAll('[data-section]'));
        const parallaxSections = Array.from(root.querySelectorAll('[data-parallax]'));

        const reduceMotion = prefersReducedMotion();
        const hasObserver = 'IntersectionObserver' in window;

        // --- Reveals ---------------------------------------------------
        // Elements are only hidden once `motion-ready` is on the root, so
        // without JS / reduced motion / no IntersectionObserver the page is
        // fully visible and nothing can get stuck invisible.
        if (!reduceMotion && hasObserver && revealEls.length) {
            revealObserver = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) return;
                        entry.target.classList.add('is-revealed');
                        revealObserver.unobserve(entry.target);
                    });
                },
                { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
            );
            root.classList.add('motion-ready');
            revealEls.forEach((el) => revealObserver.observe(el));
        }

        if (!hasObserver) return;

        // --- Active section (thin band across the middle of the screen) ---
        if (sections.length) {
            bandObserver = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) activeSection.value = entry.target.dataset.section;
                    });
                },
                { rootMargin: '-45% 0px -50% 0px', threshold: 0 },
            );
            sections.forEach((el) => bandObserver.observe(el));
        }

        // --- Parallax + off-screen pause -----------------------------------
        if (parallaxSections.length) {
            parallaxItems = parallaxSections.map((section) => ({
                section,
                layers: Array.from(section.querySelectorAll('[data-parallax-layer]')).map((el) => ({
                    el,
                    factor: parseFloat(el.dataset.parallaxLayer) || 0,
                })),
            }));

            visibilityObserver = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        entry.target.classList.toggle('is-offscreen', !entry.isIntersecting);
                        if (entry.isIntersecting) visibleParallax.add(entry.target);
                        else visibleParallax.delete(entry.target);
                    });
                    if (!reduceMotion) syncScrollListener();
                },
                { rootMargin: '10% 0px 10% 0px', threshold: 0 },
            );
            parallaxSections.forEach((el) => visibilityObserver.observe(el));
        }
    });

    onBeforeUnmount(() => {
        revealObserver?.disconnect();
        bandObserver?.disconnect();
        visibilityObserver?.disconnect();
        if (rafId) cancelAnimationFrame(rafId);
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('resize', onScroll);
        rootRef.value?.classList.remove('motion-ready');
    });

    return { activeSection };
}