// Détection partagée : faut-il lancer une scène 3D sur cet appareil ?

export const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function canUseWebGL() {
    try {
        const canvas = document.createElement('canvas');
        return Boolean(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch {
        return false;
    }
}

export function isLowPowerDevice() {
    if (navigator.connection?.saveData) {
        return true;
    }
    // Connexion lente : ~140 Ko compressés de three.js ne valent pas l'attente.
    if (['slow-2g', '2g', '3g'].includes(navigator.connection?.effectiveType)) {
        return true;
    }
    if (navigator.deviceMemory && navigator.deviceMemory < 4) {
        return true;
    }
    const smallScreen = window.matchMedia('(max-width: 760px)').matches;
    return smallScreen && (navigator.hardwareConcurrency || 8) <= 4;
}

/** Vrai si une scène 3D peut tourner : mouvement autorisé, appareil capable, WebGL disponible. */
export const allow3D = () => !reducedMotion() && !isLowPowerDevice() && canUseWebGL();

/**
 * Charge et monte une scène 3D quand `container` devient visible ; la libère quand on quitte la page.
 * @param {HTMLElement} container
 * @param {() => Promise<{mount: (el: HTMLElement, opts: object) => () => void}>} load
 */
export function lazyScene(container, load) {
    let dispose = null;
    const observer = new IntersectionObserver(async ([entry]) => {
        if (!entry.isIntersecting || dispose) {
            return;
        }
        observer.disconnect();
        // Attendre que le navigateur soit inactif : la 3D ne passe jamais avant le contenu.
        await new Promise((resolve) => ('requestIdleCallback' in window ? requestIdleCallback(resolve, { timeout: 2500 }) : setTimeout(resolve, 600)));
        try {
            const { mount } = await load();
            dispose = mount(container, { onReady: () => container.classList.add('is-3d') });
        } catch (error) {
            // L'illustration SVG reste affichée : aucune fonctionnalité perdue.
            console.warn('Scène 3D indisponible :', error);
        }
    });
    observer.observe(container);

    window.addEventListener('pagehide', () => {
        dispose?.();
        dispose = null;
        container.classList.remove('is-3d');
    });
}
