// Scène 3D de la page Shiro : un cadran de 24 heures incliné, un repère doré sur le fixing (00:55 UTC)
// et un flux de particules qui converge vers lui, comme les ordres vers le fixing.
// Montée à la demande (assets/js/device.js) ; l'illustration SVG reste affichée sinon.
import {
    AdditiveBlending,
    BufferAttribute,
    BufferGeometry,
    CanvasTexture,
    Color,
    Float32BufferAttribute,
    Fog,
    Group,
    LineBasicMaterial,
    LineLoop,
    LineSegments,
    PerspectiveCamera,
    Points,
    PointsMaterial,
    Scene,
    WebGLRenderer,
} from 'three';

const RADIUS = 4;
const PARTICLES = 1600;
const FIX_ANGLE = Math.PI / 2 - (55 / 1440) * Math.PI * 2; // 00:55 sur un cadran de 24 h, minuit en haut

function seeded(seed) {
    let s = seed;
    return () => {
        s = (s * 16807) % 2147483647;
        return (s - 1) / 2147483646;
    };
}

// Point rond et doux (sinon WebGL dessine des carrés)
function softDot() {
    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    const g = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.25, 'rgba(255,255,255,0.75)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, size, size);
    return new CanvasTexture(canvas);
}

function circle(radius, segments = 160) {
    const pts = [];
    for (let i = 0; i < segments; i++) {
        const a = (i / segments) * Math.PI * 2;
        pts.push(Math.cos(a) * radius, Math.sin(a) * radius, 0);
    }
    return new Float32BufferAttribute(pts, 3);
}

/**
 * @param {HTMLElement} container
 * @param {{ onReady?: () => void }} options
 * @returns {() => void} nettoyage
 */
export function mountShiroScene(container, { onReady } = {}) {
    const styles = getComputedStyle(document.documentElement);
    const css = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
    const bg = new Color(css('--ink-950', '#06080A'));
    const gold = new Color(css('--gold-500', '#C8A45C'));
    const goldLight = new Color(css('--gold-300', '#E2CB91'));
    const emerald = new Color(css('--emerald-500', '#2E8B73'));

    const renderer = new WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setClearColor(bg, 1);
    renderer.domElement.setAttribute('aria-hidden', 'true');
    container.appendChild(renderer.domElement);

    const scene = new Scene();
    scene.fog = new Fog(bg, 9, 22);
    const camera = new PerspectiveCamera(38, 1, 0.1, 60);
    camera.position.set(0, -1.2, 14);

    const dial = new Group();
    dial.rotation.x = -1.08; // cadran incliné vers l'horizon
    dial.position.set(2.4, 0.2, 0);
    scene.add(dial);

    const disposables = [];
    const track = (...items) => {
        disposables.push(...items);
        return items[0];
    };
    const dot = track(softDot());

    // Anneaux
    [[RADIUS, gold, 0.75], [RADIUS * 1.18, emerald, 0.35], [RADIUS * 0.8, emerald, 0.25], [RADIUS * 0.55, emerald, 0.12]].forEach(([r, color, opacity]) => {
        const geometry = track(new BufferGeometry());
        geometry.setAttribute('position', circle(r));
        const material = track(new LineBasicMaterial({ color, transparent: true, opacity }));
        dial.add(new LineLoop(geometry, material));
    });

    // Graduations : 96 quarts d'heure, heures plus longues, 0/6/12/18 h plus longues encore
    const ticks = [];
    for (let i = 0; i < 96; i++) {
        const a = (i / 96) * Math.PI * 2;
        const len = i % 24 === 0 ? 0.42 : i % 4 === 0 ? 0.24 : 0.1;
        const r1 = RADIUS * 1.03;
        ticks.push(Math.cos(a) * r1, Math.sin(a) * r1, 0, Math.cos(a) * (r1 + len), Math.sin(a) * (r1 + len), 0);
    }
    const tickGeometry = track(new BufferGeometry());
    tickGeometry.setAttribute('position', new Float32BufferAttribute(ticks, 3));
    const tickMaterial = track(new LineBasicMaterial({ color: gold, transparent: true, opacity: 0.55 }));
    dial.add(new LineSegments(tickGeometry, tickMaterial));

    // Repère du fixing (halo + point)
    const fx = Math.cos(FIX_ANGLE) * RADIUS;
    const fy = Math.sin(FIX_ANGLE) * RADIUS;
    const markerGeometry = track(new BufferGeometry());
    markerGeometry.setAttribute('position', new Float32BufferAttribute([fx, fy, 0.01], 3));
    const haloMaterial = track(new PointsMaterial({ map: dot, color: gold, size: 1.4, transparent: true, opacity: 0.35, blending: AdditiveBlending, depthWrite: false }));
    const coreMaterial = track(new PointsMaterial({ map: dot, color: goldLight, size: 0.45, transparent: true, opacity: 1, blending: AdditiveBlending, depthWrite: false }));
    dial.add(new Points(markerGeometry, haloMaterial));
    dial.add(new Points(markerGeometry, coreMaterial));

    // Rayon qui pointe le fixing depuis le centre
    const rayGeometry = track(new BufferGeometry());
    rayGeometry.setAttribute('position', new Float32BufferAttribute([0, 0, 0, fx, fy, 0], 3));
    const rayMaterial = track(new LineBasicMaterial({ color: gold, transparent: true, opacity: 0.25 }));
    dial.add(new LineSegments(rayGeometry, rayMaterial));

    // Flux de particules : chaque particule part de loin et rejoint le repère en spirale
    const rand = seeded(29);
    const start = new Float32Array(PARTICLES * 3); // angle de départ, rayon de départ, hauteur de départ
    const speed = new Float32Array(PARTICLES);
    const phase = new Float32Array(PARTICLES);
    for (let i = 0; i < PARTICLES; i++) {
        start[i * 3] = rand() * Math.PI * 2;
        start[i * 3 + 1] = RADIUS * (1.3 + rand() * 1.4);
        start[i * 3 + 2] = (rand() - 0.5) * 3;
        speed[i] = 0.035 + rand() * 0.07;
        phase[i] = rand();
    }
    const positions = new Float32Array(PARTICLES * 3);
    const colors = new Float32Array(PARTICLES * 3);
    const flowGeometry = track(new BufferGeometry());
    flowGeometry.setAttribute('position', new BufferAttribute(positions, 3));
    flowGeometry.setAttribute('color', new BufferAttribute(colors, 3));
    const flowMaterial = track(new PointsMaterial({
        map: dot, size: 0.11, vertexColors: true, transparent: true, opacity: 0.9, blending: AdditiveBlending, depthWrite: false,
    }));
    dial.add(new Points(flowGeometry, flowMaterial));
    const tmp = new Color();

    function updateFlow(time) {
        for (let i = 0; i < PARTICLES; i++) {
            const t = (phase[i] + time * speed[i]) % 1;
            const e = t * t * (3 - 2 * t); // lissage
            const a0 = start[i * 3];
            let delta = FIX_ANGLE - a0;
            delta = Math.atan2(Math.sin(delta), Math.cos(delta)); // plus court chemin
            const angle = a0 + delta * e + (1 - e) * 1.4; // légère spirale
            const r = start[i * 3 + 1] * (1 - e) + RADIUS * e;
            positions[i * 3] = Math.cos(angle) * r;
            positions[i * 3 + 1] = Math.sin(angle) * r;
            positions[i * 3 + 2] = start[i * 3 + 2] * (1 - e);
            tmp.copy(emerald).lerp(e > 0.65 ? goldLight : gold, Math.min(1, e * 1.3));
            const fade = Math.min(1, t * 6) * (1 - Math.max(0, (t - 0.94) / 0.06));
            colors[i * 3] = tmp.r * fade;
            colors[i * 3 + 1] = tmp.g * fade;
            colors[i * 3 + 2] = tmp.b * fade;
        }
        flowGeometry.attributes.position.needsUpdate = true;
        flowGeometry.attributes.color.needsUpdate = true;
    }

    // Taille
    const resize = () => {
        const { clientWidth: w, clientHeight: h } = container;
        if (!w || !h) {
            return;
        }
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        // Sur écran étroit, le cadran revient au centre et recule un peu
        dial.position.x = w < 760 ? 0.4 : 2.4;
        camera.position.z = w < 760 ? 17 : 14;
        camera.updateProjectionMatrix();
    };
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container);
    resize();

    // Souris (parallaxe amortie) et défilement (le cadran se redresse en descendant)
    const pointer = { x: 0, y: 0 };
    const onPointerMove = (event) => {
        pointer.x = (event.clientX / window.innerWidth) * 2 - 1;
        pointer.y = (event.clientY / window.innerHeight) * 2 - 1;
    };
    window.addEventListener('pointermove', onPointerMove, { passive: true });

    let frame = 0;
    let visible = true;
    let ready = false;
    const t0 = performance.now();

    const render = (now) => {
        const time = (now - t0) / 1000;
        const rect = container.getBoundingClientRect();
        const scroll = Math.min(1, Math.max(0, -rect.top / Math.max(rect.height, 1)));

        dial.rotation.z = time * 0.035; // le cadran tourne lentement, le repère avec lui
        dial.rotation.x += (-1.08 + scroll * 0.5 + pointer.y * 0.06 - dial.rotation.x) * 0.05;
        camera.position.x += (pointer.x * 0.6 - camera.position.x) * 0.04;
        camera.lookAt(dial.position.x * 0.6, 0, 0);

        const pulse = 0.5 + 0.5 * Math.sin(time * 2.2);
        haloMaterial.size = 2.4 + pulse * 1.2;
        haloMaterial.opacity = 0.4 + pulse * 0.25;

        updateFlow(time);
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

    return function dispose() {
        pause();
        intersection.disconnect();
        resizeObserver.disconnect();
        window.removeEventListener('pointermove', onPointerMove);
        document.removeEventListener('visibilitychange', onVisibility);
        disposables.forEach((item) => item.dispose());
        renderer.dispose();
        renderer.forceContextLoss();
        renderer.domElement.remove();
    };
}
