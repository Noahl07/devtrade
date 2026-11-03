// Scène 3D du héros : un "terrain" de chandeliers en fil de fer qui s'éloigne vers l'horizon.
// Chargée à la demande (import dynamique) par assets/site.js, jamais si l'utilisateur
// préfère moins d'animations ou si l'appareil est trop faible : l'illustration SVG reste alors affichée.
import {
    BufferGeometry,
    Color,
    Float32BufferAttribute,
    Fog,
    LineBasicMaterial,
    LineSegments,
    PerspectiveCamera,
    Scene,
    WebGLRenderer,
} from 'three';

const ROWS = 14;
const COLS = 64;
const SPACING_X = 0.62;
const SPACING_Z = 1.5;

// Générateur pseudo-aléatoire à graine fixe : la scène est identique à chaque visite.
function seeded(seed) {
    let s = seed;
    return () => {
        s = (s * 16807) % 2147483647;
        return (s - 1) / 2147483646;
    };
}

function gaussian(rand) {
    return Math.sqrt(-2 * Math.log(rand() || 1e-9)) * Math.cos(2 * Math.PI * rand());
}

function buildTerrain(colors) {
    const rand = seeded(7);
    const positions = [];
    const vertexColors = [];
    const push = (x1, y1, z1, x2, y2, z2, color) => {
        positions.push(x1, y1, z1, x2, y2, z2);
        vertexColors.push(color.r, color.g, color.b, color.r, color.g, color.b);
    };

    for (let row = 0; row < ROWS; row++) {
        const z = -row * SPACING_Z;
        let price = 0;
        for (let col = 0; col < COLS; col++) {
            const open = price;
            price += 0.06 + gaussian(rand) * 0.42;
            const close = price;
            const high = Math.max(open, close) + Math.abs(gaussian(rand)) * 0.25;
            const low = Math.min(open, close) - Math.abs(gaussian(rand)) * 0.25;
            const x = (col - COLS / 2) * SPACING_X + (row % 2) * SPACING_X * 0.5;
            const color = close >= open ? colors.up : colors.down;
            const top = Math.max(open, close) * 0.5;
            const bottom = Math.min(open, close) * 0.5 - 0.02;
            const w = 0.17;

            // Mèches
            push(x, high * 0.5, z, x, top, z, color);
            push(x, bottom, z, x, low * 0.5, z, color);
            // Corps (rectangle)
            push(x - w, top, z, x + w, top, z, color);
            push(x + w, top, z, x + w, bottom, z, color);
            push(x + w, bottom, z, x - w, bottom, z, color);
            push(x - w, bottom, z, x - w, top, z, color);
        }
    }

    const geometry = new BufferGeometry();
    geometry.setAttribute('position', new Float32BufferAttribute(positions, 3));
    geometry.setAttribute('color', new Float32BufferAttribute(vertexColors, 3));
    geometry.center();
    geometry.translate(0, 0, -ROWS * SPACING_Z * 0.15);

    return geometry;
}

/**
 * Monte la scène dans `container` et renvoie une fonction de nettoyage.
 * @param {HTMLElement} container
 * @param {{ onReady?: () => void }} options
 */
export function mountHeroScene(container, { onReady } = {}) {
    const styles = getComputedStyle(document.documentElement);
    const css = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
    const background = new Color(css('--ink-950', '#06080A'));

    const renderer = new WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setClearColor(background, 1);
    renderer.domElement.setAttribute('aria-hidden', 'true');
    renderer.domElement.className = 'hero-art__canvas';
    container.appendChild(renderer.domElement);

    const scene = new Scene();
    scene.fog = new Fog(background, 6, 26);

    const camera = new PerspectiveCamera(42, 1, 0.1, 80);
    camera.position.set(0, 3.2, 11);

    const geometry = buildTerrain({
        up: new Color(css('--gold-500', '#C8A45C')),
        down: new Color(css('--emerald-500', '#2E8B73')),
    });
    const material = new LineBasicMaterial({ vertexColors: true, transparent: true, opacity: 0.85, fog: true });
    const terrain = new LineSegments(geometry, material);
    terrain.rotation.x = -0.08;
    scene.add(terrain);

    // Taille
    const resize = () => {
        const { clientWidth: w, clientHeight: h } = container;
        if (!w || !h) {
            return;
        }
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    };
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container);
    resize();

    // Parallaxe souris (léger, amorti)
    const pointer = { x: 0, y: 0 };
    const onPointerMove = (event) => {
        pointer.x = (event.clientX / window.innerWidth) * 2 - 1;
        pointer.y = (event.clientY / window.innerHeight) * 2 - 1;
    };
    window.addEventListener('pointermove', onPointerMove, { passive: true });

    // Boucle : tourne seulement si visible et onglet actif
    let frame = 0;
    let visible = true;
    let ready = false;
    const start = performance.now();
    const look = { x: 0, y: 0.6 };

    const render = (now) => {
        const t = (now - start) / 1000;
        camera.position.x += (Math.sin(t * 0.045) * 1.6 + pointer.x * 0.9 - camera.position.x) * 0.03;
        camera.position.y += (3.2 + pointer.y * -0.4 - camera.position.y) * 0.03;
        terrain.position.x = -((t * 0.18) % (SPACING_X * 2));
        camera.lookAt(look.x, look.y, -4);
        renderer.render(scene, camera);
        if (!ready) {
            ready = true;
            onReady?.();
        }
    };

    const loop = (now) => {
        render(now);
        frame = requestAnimationFrame(loop);
    };

    const play = () => {
        if (!frame && visible && !document.hidden) {
            frame = requestAnimationFrame(loop);
        }
    };
    const pause = () => {
        cancelAnimationFrame(frame);
        frame = 0;
    };

    const intersection = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        visible ? play() : pause();
    });
    intersection.observe(container);

    const onVisibility = () => (document.hidden ? pause() : play());
    document.addEventListener('visibilitychange', onVisibility);

    play();

    // Nettoyage complet (navigation, bfcache)
    return function dispose() {
        pause();
        intersection.disconnect();
        resizeObserver.disconnect();
        window.removeEventListener('pointermove', onPointerMove);
        document.removeEventListener('visibilitychange', onVisibility);
        geometry.dispose();
        material.dispose();
        renderer.dispose();
        renderer.forceContextLoss();
        renderer.domElement.remove();
    };
}
