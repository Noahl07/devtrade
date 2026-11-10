// Animations pédagogiques d'orderflow : <div class="doc-anim doc-anim--TYPE [doc-anim-scenario--NOM]">.
// Types : heatmap (carnet d'ordres), footprint, gex, replay (rejeu de bougies), carnet (liquidité passive / agressive),
// bookmap (heatmap du carnet et points d'agression), gex-carte (gamma par strike et dans le temps). Le contenu d'origine du bloc (texte explicatif)
// sert de repli sans JavaScript. Les scénarios sont des illustrations construites, pas des données
// de marché réelles : chacun montre un mécanisme, avec un commentaire qui change à chaque phase.

const mouvementReduit = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const COULEURS = {
    fond: '#0f1513',
    grille: 'rgba(168,179,174,0.12)',
    texte: '#A8B3AE',
    clair: '#D9D3C3',
    achat: '#4FB38F',
    vente: '#D2655A',
    or: '#E2CB91',
    bleu: '#B9C6E6',
};

function aleatoire(graine) {
    let a = graine >>> 0;
    const u = () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    return { u, gauss: () => Math.sqrt(-2 * Math.log(1 - u())) * Math.cos(2 * Math.PI * u()) };
}

/** Interpolation linéaire dans une liste d'images clés [[t, valeur], …]. */
function cle(points, t) {
    if (t <= points[0][0]) {
        return points[0][1];
    }
    for (let i = 1; i < points.length; i++) {
        if (t <= points[i][0]) {
            const [t0, v0] = points[i - 1];
            const [t1, v1] = points[i];
            return v0 + ((v1 - v0) * (t - t0)) / (t1 - t0);
        }
    }
    return points[points.length - 1][1];
}

// ── Heatmap du carnet d'ordres ────────────────────────────────────────────────
// Chaque scénario : trajectoire du prix (images clés en niveaux), murs de liquidité, agressions
// (sens et taille des ordres au marché) et phases commentées. Le temps va de 0 à 1.
const NIVEAUX = 40;
const HEATMAP = {
    absorption: {
        nom: 'Absorption',
        prix: [[0, 26], [0.25, 20], [0.4, 15.6], [0.7, 15.4], [0.8, 18], [1, 25]],
        murs: [{ niveau: 15, cote: 'achat', taille: [[0, 2.5], [0.35, 3], [0.7, 3], [0.78, 1.5], [1, 1.2]], recharge: true }],
        agression: (t) => (t < 0.72 ? { sens: 'vente', force: t > 0.35 ? 1.6 : 0.9 } : { sens: 'achat', force: 1.1 }),
        phases: [
            [0, 'Le prix baisse vers une zone où un acheteur important a placé des ordres limites (bande lumineuse sous le prix).'],
            [0.35, 'Les vendeurs frappent ce niveau avec des ordres au marché de plus en plus gros (bulles rouges)…'],
            [0.5, '…mais la liquidité ne diminue pas : elle se recharge. Le prix ne passe pas. C\'est l\'absorption : l\'agresseur s\'épuise contre un passif plus fort.'],
            [0.72, 'Les vendeurs abandonnent, les acheteurs prennent l\'initiative : le prix repart, et les vendeurs piégés sous le niveau alimentent la hausse.'],
        ],
    },
    epuisement: {
        nom: 'Épuisement',
        prix: [[0, 10], [0.45, 26], [0.6, 28], [0.68, 28.3], [1, 18]],
        murs: [],
        agression: (t) => (t < 0.66 ? { sens: 'achat', force: Math.max(0.15, 1.5 - t * 2) } : { sens: 'vente', force: 1.2 }),
        phases: [
            [0, 'Une hausse portée par des achats au marché nombreux et gros (bulles vertes).'],
            [0.4, 'Le prix monte encore, mais les achats deviennent plus petits et plus rares : il y a de moins en moins d\'acheteurs prêts à payer plus cher.'],
            [0.6, 'Plus personne n\'achète au sommet : c\'est l\'épuisement. Aucun mur ne bloque le prix, ce sont les acheteurs qui manquent.'],
            [0.68, 'Les vendeurs n\'ont presque rien en face : quelques ventes suffisent à faire reculer nettement le prix.'],
        ],
    },
    iceberg: {
        nom: 'Iceberg',
        prix: [[0, 12], [0.3, 21.6], [0.7, 21.8], [1, 13]],
        murs: [{ niveau: 22, cote: 'vente', taille: [[0, 0.5], [1, 0.5]], recharge: true, iceberg: true }],
        // Iceberg tracker : pastille qui compte les recharges du niveau.
        marqueurs: [{ t: 0.34, niveau: 22, type: 'iceberg', compteur: [[0.34, 1], [0.7, 14], [1, 14]] }],
        agression: (t) => (t < 0.72 ? { sens: 'achat', force: t > 0.3 ? 1.5 : 1 } : { sens: 'vente', force: 1 }),
        phases: [
            [0, 'Le prix monte vers un niveau où le carnet n\'affiche qu\'une petite quantité à la vente.'],
            [0.3, 'Les acheteurs frappent ce niveau à répétition avec de gros volumes : bien plus que la quantité affichée…'],
            [0.45, '…et pourtant le niveau tient : la quantité visible se reconstitue après chaque achat. C\'est un ordre iceberg, dont seule la pointe est affichée.'],
            [0.56, 'Un « iceberg tracker » (pastille I) repère ce motif : des exécutions répétées au même prix, au-delà de la quantité affichée. Il compte les recharges pour estimer la taille cachée.'],
            [0.72, 'Les acheteurs ont dépensé leurs munitions sans passer : le prix se retourne.'],
        ],
    },
    stops: {
        nom: 'Chasse aux stops',
        prix: [[0, 15], [0.12, 22.6], [0.2, 17.5], [0.32, 22.8], [0.42, 17.8], [0.52, 22.7], [0.57, 23.6], [0.62, 30], [0.7, 31], [0.85, 27.5], [1, 28]],
        murs: [{ niveau: 23, cote: 'vente', taille: [[0, 1.3], [0.56, 1.3], [0.59, 0], [1, 0]] }],
        zones: [{ bas: 23.5, haut: 26.5, t0: 0, t1: 0.6, texte: 'Stops des vendeurs (au-dessus des sommets)' }],
        // Stop tracker : rafale d'achats au marché juste après la cassure des sommets.
        marqueurs: [{ t: 0.6, niveau: 27.5, type: 'stop', texte: 'Rafale de stops' }],
        agression: (t) => (t >= 0.56 && t < 0.66 ? { sens: 'achat', force: 2.6 } : t < 0.56 ? { sens: t * 20 % 2 < 1 ? 'achat' : 'vente', force: 0.7 } : { sens: 'vente', force: 0.9 }),
        phases: [
            [0, 'Le prix bute trois fois sous le même plafond : des sommets presque égaux.'],
            [0.3, 'Les vendeurs à découvert placent leurs stops (des ordres d\'achat) juste au-dessus de ces sommets : une réserve de liquidité invisible dans le carnet, mais connue de tous (zone en pointillés).'],
            [0.57, 'Le plafond cède : les premiers stops déclenchés deviennent des achats au marché, qui en déclenchent d\'autres. Un « stop tracker » repère cette rafale d\'achats agressifs en quelques secondes.'],
            [0.7, 'La rafale passée, il n\'y a plus de stops à déclencher : le prix ralentit et revient en partie. La suite dépend de ce que font les acheteurs ensuite, pas de la cassure elle-même.'],
        ],
    },
    spoofing: {
        nom: 'Spoofing',
        prix: [[0, 18], [0.55, 16.5], [0.62, 17], [0.75, 24], [1, 27]],
        murs: [{ niveau: 23, cote: 'vente', taille: [[0, 0], [0.1, 3.5], [0.6, 3.5], [0.62, 0], [1, 0]] }],
        agression: (t) => (t < 0.6 ? { sens: 'vente', force: 0.6 } : { sens: 'achat', force: 1.4 }),
        phases: [
            [0, 'Le prix évolue tranquillement.'],
            [0.1, 'Un énorme ordre de vente apparaît au-dessus du prix. Les autres participants le voient, prennent peur et vendent ou s\'écartent.'],
            [0.6, 'Le gros ordre disparaît d\'un coup, sans avoir été exécuté : il n\'était là que pour influencer.'],
            [0.64, 'Le chemin est libre : le prix monte. Le spoofing est une manipulation interdite par les régulateurs, mais il reste difficile à prouver en direct.'],
        ],
    },
    'spoofing-achat': {
        nom: 'Spoofing à l\'achat',
        prix: [[0, 20], [0.55, 21.6], [0.62, 21], [0.75, 15], [1, 12]],
        murs: [{ niveau: 17, cote: 'achat', taille: [[0, 0], [0.1, 3.5], [0.6, 3.5], [0.62, 0], [1, 0]] }],
        agression: (t) => (t < 0.6 ? { sens: 'achat', force: 0.6 } : { sens: 'vente', force: 1.4 }),
        phases: [
            [0, 'Le prix évolue tranquillement.'],
            [0.1, 'Un énorme ordre d\'achat apparaît sous le prix. Il rassure : les vendeurs hésitent, des acheteurs se placent juste au-dessus de lui.'],
            [0.6, 'Le gros ordre disparaît d\'un coup sans avoir été exécuté. Celui qui l\'avait placé vendait peut-être pendant ce temps aux acheteurs rassurés.'],
            [0.64, 'Plus de soutien : le prix chute. Le même mécanisme que le spoofing à la vente, à l\'envers.'],
        ],
    },
    cassure: {
        nom: 'Mur cassé',
        prix: [[0, 12], [0.3, 19.6], [0.62, 19.8], [0.7, 23], [1, 31]],
        murs: [{ niveau: 20, cote: 'vente', taille: [[0, 3.2], [0.3, 3.2], [0.62, 0.2], [0.66, 0], [1, 0]] }],
        agression: (t) => ({ sens: 'achat', force: t > 0.3 && t < 0.66 ? 1.7 : 1.1 }),
        phases: [
            [0, 'Le prix monte vers un gros mur de vente, bien visible dans le carnet.'],
            [0.3, 'Les acheteurs attaquent le mur : la bande lumineuse diminue à chaque vague d\'achats. Contrairement à l\'iceberg, la liquidité ne revient pas.'],
            [0.62, 'Le mur est consommé : il n\'y a presque plus rien au-dessus.'],
            [0.68, 'Le prix accélère : les vendeurs à découvert placent leurs stops au-dessus du mur, et leurs rachats forcés amplifient la hausse.'],
        ],
    },
};

class Heatmap {
    constructor(bloc, scenario) {
        this.bloc = bloc;
        this.scenario = HEATMAP[scenario] ? scenario : 'absorption';
        this.vitesse = 1;
        this.intervalle = 80;
        this.construit(Object.entries(HEATMAP).map(([k, v]) => [k, v.nom]));
        this.charge();
    }

    construit(choix) {
        this.bloc.replaceChildren();
        this.bloc.classList.add('is-live');
        const titre = document.createElement('p');
        titre.className = 'doc-anim__title';
        titre.textContent = 'Carnet d\'ordres animé (heatmap)';
        this.onglets = document.createElement('div');
        this.onglets.className = 'doc-sim__choice-options doc-anim__tabs';
        this.onglets.setAttribute('role', 'tablist');
        choix.forEach(([cleScenario, nom]) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'tab');
            b.dataset.cle = cleScenario;
            b.textContent = nom;
            b.addEventListener('click', () => {
                this.scenario = cleScenario;
                this.charge();
            });
            this.onglets.append(b);
        });
        this.canvas = document.createElement('canvas');
        this.canvas.className = 'doc-anim__canvas';
        this.canvas.setAttribute('role', 'img');
        this.commentaire = document.createElement('p');
        this.commentaire.className = 'doc-anim__text';
        this.commentaire.setAttribute('aria-live', 'polite');
        const commandes = controles(this);
        const legende = document.createElement('p');
        legende.className = 'doc-sim__legend';
        legende.textContent = 'Bandes lumineuses : ordres limites en attente (plus c\'est clair, plus il y a de volume). Ligne : dernier prix. Bulles : ordres au marché, vertes à l\'achat, rouges à la vente. Scénario construit à titre d\'illustration.';
        this.bloc.append(titre, this.onglets, this.canvas, commandes, this.commentaire, legende);
        new ResizeObserver(() => {
            this.sale = true;
        }).observe(this.canvas);
        // L'animation ne démarre que lorsque le lecteur arrive dessus.
        this.visible = false;
        if (typeof IntersectionObserver === 'function') {
            new IntersectionObserver(([entree]) => {
                this.visible = entree.isIntersecting;
            }, { threshold: 0.35 }).observe(this.canvas);
        } else {
            this.visible = true;
        }
    }

    charge() {
        const s = HEATMAP[this.scenario];
        this.onglets.querySelectorAll('button').forEach((b) => {
            const actif = b.dataset.cle === this.scenario;
            b.classList.toggle('is-active', actif);
            b.setAttribute('aria-selected', String(actif));
        });
        this.canvas.setAttribute('aria-label', `Animation du carnet d'ordres : scénario ${s.nom}`);
        // Pré-calcul de toute la séquence : colonnes de liquidité, prix et agressions.
        const r = aleatoire(this.scenario.length * 97 + 13);
        const pas = 220;
        this.pas = pas;
        this.colonnes = [];
        this.prix = [];
        this.bulles = [];
        const consomme = {};
        let bruit = 0;
        for (let i = 0; i < pas; i++) {
            const t = i / (pas - 1);
            bruit = bruit * 0.8 + r.gauss() * 0.25;
            const p = cle(s.prix, t) + bruit;
            this.prix.push(p);
            const col = new Float32Array(NIVEAUX);
            for (let n = 0; n < NIVEAUX; n++) {
                const distance = Math.abs(n - p);
                col[n] = distance < 0.6 ? 0 : Math.min(1, 0.06 + 0.32 * Math.exp(-distance / 5) * (0.5 + r.u()));
            }
            s.murs.forEach((m) => {
                let taille = cle(m.taille, t);
                const deja = consomme[m.niveau] || 0;
                if (!m.recharge) {
                    taille = Math.max(0, taille - deja);
                }
                const vivant = m.cote === 'achat' ? p >= m.niveau - 0.4 : p <= m.niveau + 0.4;
                if (vivant || m.recharge) {
                    col[m.niveau] = Math.min(m.iceberg ? 0.55 : 1, col[m.niveau] + taille * 0.3);
                    if (!m.iceberg) {
                        col[m.niveau + (m.cote === 'achat' ? -1 : 1)] += taille * 0.08;
                    }
                }
            });
            this.colonnes.push(col);
            const a = s.agression(t);
            if (r.u() < 0.55 * a.force) {
                this.bulles.push({ i, prix: p, sens: a.sens, taille: Math.max(0.2, a.force * (0.5 + r.u())) });
            }
        }
        this.i = 0;
        this.lecture = !mouvementReduit();
        this.majLecture?.();
        if (!this.lecture) {
            this.i = pas - 1;
        }
        this.demarre();
    }

    demarre() {
        cancelAnimationFrame(this.boucle);
        this.sale = true;
        let precedent = null;
        let dernier = -1;
        const tic = (t) => {
            const enMarche = this.lecture && this.visible !== false;
            if (enMarche && precedent !== null && t - precedent > (this.intervalle || 70) / this.vitesse) {
                this.i = Math.min(this.i + 1, this.pas - 1);
                precedent = t;
                if (this.i === this.pas - 1) {
                    this.lecture = false;
                    this.majLecture();
                }
            } else if (!enMarche || precedent === null) {
                precedent = t;
            }
            if (this.i !== dernier || this.sale) {
                dernier = this.i;
                this.sale = false;
                this.dessine();
            }
            this.boucle = requestAnimationFrame(tic);
        };
        this.boucle = requestAnimationFrame(tic);
    }

    dessine() {
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const H = Math.round(W * 0.5);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = COULEURS.fond;
        ctx.fillRect(0, 0, W, H);
        const largeurCol = W / this.pas;
        const hNiveau = H / NIVEAUX;
        const Y = (n) => H - (n + 0.5) * hNiveau;
        for (let c = 0; c <= this.i; c++) {
            const col = this.colonnes[c];
            for (let n = 0; n < NIVEAUX; n++) {
                const v = col[n];
                if (v < 0.05) {
                    continue;
                }
                const vente = n > this.prix[c];
                const l = Math.round(18 + v * 62);
                ctx.fillStyle = vente ? `hsl(8, ${35 + v * 40}%, ${l}%)` : `hsl(160, ${30 + v * 40}%, ${l}%)`;
                ctx.globalAlpha = 0.12 + 0.88 * v ** 1.4;
                ctx.fillRect(c * largeurCol, H - (n + 1) * hNiveau, largeurCol + 0.6, hNiveau + 0.4);
            }
        }
        ctx.globalAlpha = 1;
        this.bulles.forEach((b) => {
            if (b.i > this.i) {
                return;
            }
            ctx.beginPath();
            ctx.arc((b.i + 0.5) * largeurCol, Y(b.prix), 1.5 + b.taille * hNiveau * 0.38, 0, Math.PI * 2);
            ctx.fillStyle = b.sens === 'achat' ? 'rgba(79,179,143,0.45)' : 'rgba(232,154,144,0.45)';
            ctx.fill();
        });
        ctx.strokeStyle = COULEURS.clair;
        ctx.lineWidth = 2;
        ctx.beginPath();
        for (let c = 0; c <= this.i; c++) {
            const x = (c + 0.5) * largeurCol;
            const y = Y(this.prix[c]);
            if (c === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        }
        ctx.stroke();
        const s = HEATMAP[this.scenario];
        const t = this.i / (this.pas - 1);
        // Zones repérées (stops) et pastilles des « trackers ».
        ctx.font = '12px system-ui, sans-serif';
        ctx.textBaseline = 'alphabetic';
        (s.zones || []).forEach((z) => {
            if (t < z.t0) {
                return;
            }
            const x0 = z.t0 * (this.pas - 1) * largeurCol;
            const x1 = Math.min(t, z.t1) * (this.pas - 1) * largeurCol;
            const yHaut = H - (z.haut + 0.5) * hNiveau;
            const yBas = H - (z.bas + 0.5) * hNiveau;
            ctx.fillStyle = 'rgba(226,203,145,0.08)';
            ctx.fillRect(x0, yHaut, x1 - x0, yBas - yHaut);
            ctx.strokeStyle = 'rgba(226,203,145,0.7)';
            ctx.setLineDash([4, 4]);
            ctx.strokeRect(x0, yHaut, x1 - x0, yBas - yHaut);
            ctx.setLineDash([]);
            ctx.fillStyle = COULEURS.or;
            ctx.textAlign = 'left';
            ctx.fillText(z.texte, x0 + 6, yHaut - 5);
        });
        (s.marqueurs || []).forEach((m) => {
            if (t < m.t) {
                return;
            }
            const x = m.t * (this.pas - 1) * largeurCol;
            const y = Y(m.niveau);
            const couleur = m.type === 'iceberg' ? COULEURS.bleu : COULEURS.or;
            ctx.beginPath();
            ctx.arc(x, y, 9, 0, Math.PI * 2);
            ctx.fillStyle = COULEURS.fond;
            ctx.fill();
            ctx.strokeStyle = couleur;
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.fillStyle = couleur;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(m.type === 'iceberg' ? 'I' : 'S', x, y + 0.5);
            ctx.textBaseline = 'alphabetic';
            ctx.textAlign = x > W * 0.7 ? 'right' : 'left';
            const texte = m.compteur ? `Iceberg : ${Math.round(cle(m.compteur, t))} recharge(s)` : m.texte;
            ctx.fillText(texte, x + (x > W * 0.7 ? -14 : 14), y - 12);
        });
        const phase = [...s.phases].reverse().find(([debut]) => t >= debut);
        if (phase && this.commentaire.textContent !== phase[1]) {
            this.commentaire.textContent = phase[1];
        }
    }
}

// ── Footprint animé ──────────────────────────────────────────────────────────
// Chaque bougie : niveaux de prix avec [volume vendu au bid, volume acheté à l'ask].
const FOOTPRINT = {
    absorption: {
        nom: 'Absorption au creux',
        bougies: [
            { o: 9, c: 6, niveaux: { 9: [12, 10], 8: [30, 14], 7: [41, 15], 6: [38, 11] } },
            { o: 6, c: 4, niveaux: { 6: [25, 12], 5: [44, 16], 4: [52, 18] } },
            { o: 4, c: 4, niveaux: { 5: [20, 18], 4: [61, 22], 3: [148, 19], 2: [96, 6] }, note: 3 },
            { o: 4, c: 7, niveaux: { 3: [12, 40], 4: [14, 44], 5: [9, 51], 6: [8, 47], 7: [6, 30] } },
            { o: 7, c: 9, niveaux: { 7: [10, 35], 8: [12, 42], 9: [9, 28] } },
        ],
        phases: [
            [0, 'Le marché baisse : à chaque niveau, plus de volume vendu au bid (à gauche) qu\'acheté à l\'ask (à droite). Le delta est négatif.'],
            [2, 'Troisième bougie : un volume vendu énorme au bas (148 et 96 contrats) — mais le prix ne descend pas plus bas et la bougie clôture au milieu. Les ventes ont été absorbées par des acheteurs passifs.'],
            [3, 'Les acheteurs prennent l\'initiative : le delta devient nettement positif et le prix remonte. Les vendeurs du creux sont piégés.'],
        ],
    },
    epuisement: {
        nom: 'Épuisement au sommet',
        bougies: [
            { o: 3, c: 6, niveaux: { 3: [10, 30], 4: [12, 45], 5: [15, 52], 6: [11, 38] } },
            { o: 6, c: 8, niveaux: { 6: [14, 33], 7: [16, 28], 8: [12, 19] } },
            { o: 8, c: 9, niveaux: { 8: [15, 14], 9: [9, 6], 10: [2, 1] }, note: 10 },
            { o: 9, c: 6, niveaux: { 9: [30, 8], 8: [41, 10], 7: [38, 9], 6: [27, 7] } },
            { o: 6, c: 4, niveaux: { 6: [24, 10], 5: [36, 12], 4: [29, 9] } },
        ],
        phases: [
            [0, 'Une hausse soutenue : les acheteurs à l\'ask dominent à chaque niveau.'],
            [1, 'Les volumes achetés diminuent bougie après bougie, même si le prix monte encore.'],
            [2, 'Au plus haut, presque rien ne s\'échange (2 × 1) : il n\'y a plus d\'acheteurs pour payer plus cher. C\'est l\'épuisement, souvent signalé par une « enchère inachevée » qui se termine sur des volumes quasi nuls.'],
            [3, 'Les vendeurs reprennent la main sans résistance : le delta bascule et le prix redescend.'],
        ],
    },
    desequilibres: {
        nom: 'Déséquilibres empilés',
        bougies: [
            { o: 3, c: 4, niveaux: { 3: [18, 20], 4: [16, 19] } },
            { o: 4, c: 8, niveaux: { 4: [10, 34], 5: [8, 39], 6: [7, 44], 7: [9, 41], 8: [11, 25] }, note: 6 },
            { o: 8, c: 9, niveaux: { 7: [18, 20], 8: [22, 25], 9: [15, 21] } },
            { o: 9, c: 11, niveaux: { 9: [12, 30], 10: [10, 33], 11: [9, 22] } },
            { o: 11, c: 12, niveaux: { 11: [14, 24], 12: [11, 20] } },
        ],
        phases: [
            [0, 'Un marché équilibré : à chaque niveau, achats et ventes se valent.'],
            [1, 'Deuxième bougie : sur plusieurs niveaux consécutifs, les achats à l\'ask dépassent de plus de 3 fois les ventes du niveau juste en dessous (cases surlignées). Ce sont des déséquilibres empilés : un acheteur pressé.'],
            [2, 'Le prix revient tester la zone des déséquilibres sans la casser : elle sert souvent de support pour la suite du mouvement.'],
            [3, 'La tendance reprend.'],
        ],
    },
    divergence: {
        nom: 'Delta divergent',
        bougies: [
            { o: 3, c: 6, niveaux: { 3: [10, 30], 4: [12, 38], 5: [14, 40], 6: [10, 28] } },
            { o: 6, c: 8, niveaux: { 6: [18, 34], 7: [20, 36], 8: [16, 24] } },
            { o: 8, c: 9, niveaux: { 8: [25, 30], 9: [22, 26], 10: [14, 15] } },
            { o: 9, c: 10, niveaux: { 9: [30, 22], 10: [34, 20], 11: [26, 12] }, note: 11 },
            { o: 10, c: 7, niveaux: { 10: [40, 10], 9: [44, 12], 8: [38, 9], 7: [30, 8] } },
        ],
        phases: [
            [0, 'Une hausse avec un delta nettement positif : les acheteurs agressifs portent le mouvement.'],
            [1, 'Le prix monte encore, mais le delta de chaque bougie diminue : +90, puis +40.'],
            [2, 'Nouveau plus haut avec un delta presque nul (+10) : il faut de moins en moins d\'achats au marché pour monter… ou il y en a de moins en moins.'],
            [3, 'Nouveau plus haut, et pourtant delta négatif (−36) : ce sont les vendeurs qui frappent le plus, et des vendeurs passifs absorbent les derniers acheteurs. Le prix et le delta divergent.'],
            [4, 'Le retournement arrive. Une divergence n\'est pas un signal à elle seule : elle dit que la hausse perd son carburant.'],
        ],
    },
    iceberg: {
        nom: 'Iceberg en footprint',
        bougies: [
            { o: 9, c: 6, niveaux: { 9: [14, 10], 8: [28, 12], 7: [35, 14], 6: [40, 12] } },
            { o: 6, c: 5, niveaux: { 6: [30, 14], 5: [180, 16] }, note: 5 },
            { o: 5, c: 6, niveaux: { 6: [20, 22], 5: [195, 18], 4: [3, 1] }, note: 5 },
            { o: 6, c: 5, niveaux: { 6: [22, 16], 5: [170, 14] }, note: 5 },
            { o: 5, c: 8, niveaux: { 5: [10, 40], 6: [8, 45], 7: [7, 48], 8: [6, 30] } },
        ],
        phases: [
            [0, 'Le marché baisse normalement : plus de ventes que d\'achats à chaque niveau.'],
            [1, 'À ce prix, 180 lots sont vendus au marché… et le prix ne passe pas. Le carnet n\'affichait pourtant que quelques dizaines de lots à l\'achat.'],
            [2, 'Le même niveau encaisse encore 195 lots, puis 170 : un acheteur passif recharge son ordre à mesure qu\'il est exécuté. C\'est la signature d\'un iceberg à l\'achat, que les « iceberg trackers » détectent automatiquement.'],
            [4, 'Les vendeurs renoncent : le prix part du niveau défendu. Un iceberg montre où un gros participant veut acheter, pas jusqu\'où il le défendra.'],
        ],
    },
};

class Footprint {
    constructor(bloc, scenario) {
        this.bloc = bloc;
        this.scenario = FOOTPRINT[scenario] ? scenario : 'absorption';
        this.vitesse = 1;
        this.intervalle = 450;
        Heatmap.prototype.construit.call(this, Object.entries(FOOTPRINT).map(([k, v]) => [k, v.nom]));
        this.bloc.querySelector('.doc-anim__title').textContent = 'Footprint animé';
        this.bloc.querySelector('.doc-sim__legend').textContent = 'Chaque case : volume vendu au bid × volume acheté à l\'ask à ce prix. Cases surlignées : déséquilibre (un côté plus de 3 fois l\'autre, en diagonale). Sous chaque bougie : le delta (achats − ventes). Scénario construit à titre d\'illustration.';
        this.charge();
    }

    charge() {
        const s = FOOTPRINT[this.scenario];
        this.onglets.querySelectorAll('button').forEach((b) => {
            const actif = b.dataset.cle === this.scenario;
            b.classList.toggle('is-active', actif);
            b.setAttribute('aria-selected', String(actif));
        });
        this.canvas.setAttribute('aria-label', `Footprint animé : scénario ${s.nom}`);
        // Une étape = un niveau ajouté à une bougie ; on ajoute aussi une pause à la fin de chaque bougie.
        this.etapes = [];
        s.bougies.forEach((b, k) => {
            const niveaux = Object.keys(b.niveaux).map(Number);
            const ordre = b.c >= b.o ? niveaux.sort((x, y) => x - y) : niveaux.sort((x, y) => y - x);
            ordre.forEach((n, j) => this.etapes.push({ k, visibles: j + 1 }));
            this.etapes.push({ k, visibles: ordre.length }, { k, visibles: ordre.length });
        });
        this.pas = this.etapes.length;
        this.i = 0;
        this.lecture = !mouvementReduit();
        this.majLecture?.();
        if (!this.lecture) {
            this.i = this.pas - 1;
        }
        Heatmap.prototype.demarre.call(this);
    }

    dessine() {
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const s = FOOTPRINT[this.scenario];
        const H = Math.round(W * 0.58);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = COULEURS.fond;
        ctx.fillRect(0, 0, W, H);
        const tous = s.bougies.flatMap((b) => Object.keys(b.niveaux).map(Number));
        const nMin = Math.min(...tous) - 0.5;
        const nMax = Math.max(...tous) + 0.5;
        const basDelta = 34;
        const hNiveau = (H - basDelta - 14) / (nMax - nMin);
        const Y = (n) => 8 + (nMax - n) * hNiveau;
        const largeur = W / s.bougies.length;
        const etape = this.etapes[this.i];
        const taillePolice = Math.max(9, Math.min(13, hNiveau * 0.5, largeur / 9));
        ctx.font = `${taillePolice}px system-ui, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        s.bougies.forEach((b, k) => {
            if (k > etape.k) {
                return;
            }
            const niveaux = Object.keys(b.niveaux).map(Number);
            const ordre = b.c >= b.o ? [...niveaux].sort((x, y) => x - y) : [...niveaux].sort((x, y) => y - x);
            const visibles = k < etape.k ? ordre.length : etape.visibles;
            const montrer = new Set(ordre.slice(0, visibles));
            const x0 = k * largeur + largeur * 0.12;
            const lCase = largeur * 0.76;
            // Corps de la bougie, en fond.
            const hautCorps = Y(Math.max(b.o, b.c)) - hNiveau / 2;
            const basCorps = Y(Math.min(b.o, b.c)) + hNiveau / 2;
            ctx.fillStyle = b.c >= b.o ? 'rgba(79,179,143,0.10)' : 'rgba(210,101,90,0.10)';
            ctx.fillRect(x0, hautCorps, lCase, basCorps - hautCorps);
            let delta = 0;
            niveaux.forEach((n) => {
                if (!montrer.has(n)) {
                    return;
                }
                const [bid, ask] = b.niveaux[n];
                delta += ask - bid;
                // Déséquilibre diagonal : ask au niveau n contre bid au niveau n-1 (et inversement).
                const bidDessous = b.niveaux[n - 1]?.[0];
                const askDessus = b.niveaux[n + 1]?.[1];
                const desAchat = bidDessous !== undefined && ask >= 3 * Math.max(bidDessous, 1);
                const desVente = askDessus !== undefined && bid >= 3 * Math.max(askDessus, 1);
                const y = Y(n);
                ctx.strokeStyle = COULEURS.grille;
                ctx.strokeRect(x0, y - hNiveau / 2, lCase, hNiveau);
                if (b.note === n) {
                    ctx.fillStyle = 'rgba(226,203,145,0.18)';
                    ctx.fillRect(x0, y - hNiveau / 2, lCase, hNiveau);
                }
                ctx.fillStyle = desVente ? COULEURS.vente : COULEURS.texte;
                ctx.fillText(String(bid), x0 + lCase * 0.28, y);
                ctx.fillStyle = COULEURS.texte;
                ctx.fillText('×', x0 + lCase * 0.5, y);
                ctx.fillStyle = desAchat ? COULEURS.achat : COULEURS.texte;
                ctx.fillText(String(ask), x0 + lCase * 0.72, y);
            });
            ctx.fillStyle = delta >= 0 ? COULEURS.achat : COULEURS.vente;
            ctx.fillText(`Δ ${delta >= 0 ? '+' : ''}${delta}`, x0 + lCase / 2, H - basDelta / 2);
        });
        const phase = [...s.phases].reverse().find(([debut]) => etape.k >= debut);
        if (phase && this.commentaire.textContent !== phase[1]) {
            this.commentaire.textContent = phase[1];
        }
    }
}

// ── Gamma des teneurs de marché ──────────────────────────────────────────────
class Gex {
    constructor(bloc) {
        this.bloc = bloc;
        this.scenario = 'comparaison';
        this.vitesse = 1;
        this.intervalle = 60;
        this.graine = 3;
        Heatmap.prototype.construit.call(this, [['comparaison', 'Mêmes chocs, deux régimes']]);
        this.bloc.querySelector('.doc-anim__title').textContent = 'Gamma des teneurs de marché : freiner ou amplifier';
        this.bloc.querySelector('.doc-sim__legend').textContent = 'Les deux courbes subissent exactement les mêmes chocs aléatoires. Seule change la couverture des teneurs de marché : contre le mouvement en gamma positif, dans le sens du mouvement en gamma négatif. Modèle simplifié à titre d\'illustration.';
        this.onglets.style.display = 'none';
        const relance = document.createElement('button');
        relance.type = 'button';
        relance.className = 'doc-pas__btn';
        relance.textContent = 'Nouveaux chocs';
        relance.addEventListener('click', () => {
            this.graine += 1;
            this.charge();
        });
        this.bloc.querySelector('.doc-anim__controls').append(relance);
        this.charge();
    }

    charge() {
        const r = aleatoire(this.graine * 7717);
        const pas = 260;
        this.pas = pas;
        this.positif = [100];
        this.negatif = [100];
        let flux = 0;
        for (let i = 1; i < pas; i++) {
            const choc = r.gauss() * 0.35 + (i > 120 && i < 140 ? -0.45 : 0);
            const p = this.positif[i - 1];
            const n = this.negatif[i - 1];
            // Couverture : en gamma positif, les teneurs vendent la hausse et achètent la baisse
            // (rappel vers le niveau 100) ; en gamma négatif, ils suivent le mouvement.
            this.positif.push(p + choc - 0.12 * (p - 100));
            flux = flux * 0.5 + 0.16 * (n - this.negatif[Math.max(i - 2, 0)]);
            this.negatif.push(n + choc + flux);
        }
        const amplitude = (s) => Math.max(...s) - Math.min(...s);
        this.resume = `Écart entre plus haut et plus bas : ${amplitude(this.positif).toFixed(1).replace(".", ",")} en gamma positif, ${amplitude(this.negatif).toFixed(1).replace(".", ",")} en gamma négatif.`;
        this.i = 0;
        this.lecture = !mouvementReduit();
        this.majLecture?.();
        if (!this.lecture) {
            this.i = pas - 1;
        }
        Heatmap.prototype.demarre.call(this);
    }

    dessine() {
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const H = Math.round(W * 0.48);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = COULEURS.fond;
        ctx.fillRect(0, 0, W, H);
        const tous = [...this.positif, ...this.negatif];
        const yMin = Math.min(...tous) - 1;
        const yMax = Math.max(...tous) + 1;
        const X = (i) => 10 + (i / (this.pas - 1)) * (W - 20);
        const Y = (v) => H - 18 - ((v - yMin) / (yMax - yMin)) * (H - 36);
        ctx.strokeStyle = 'rgba(226,203,145,0.5)';
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(10, Y(100));
        ctx.lineTo(W - 10, Y(100));
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.font = '12px system-ui, sans-serif';
        ctx.fillStyle = COULEURS.or;
        ctx.textAlign = 'left';
        ctx.fillText('niveau de forte concentration de gamma', 14, Y(100) - 6);
        [[this.positif, COULEURS.bleu], [this.negatif, COULEURS.vente]].forEach(([serie, couleur]) => {
            ctx.strokeStyle = couleur;
            ctx.lineWidth = 2;
            ctx.beginPath();
            for (let i = 0; i <= this.i; i++) {
                if (i === 0) {
                    ctx.moveTo(X(i), Y(serie[i]));
                } else {
                    ctx.lineTo(X(i), Y(serie[i]));
                }
            }
            ctx.stroke();
        });
        ctx.fillStyle = COULEURS.bleu;
        ctx.fillText('gamma positif', X(this.i) - 90, Y(this.positif[this.i]) - 10);
        ctx.fillStyle = COULEURS.vente;
        ctx.fillText('gamma négatif', X(this.i) - 90, Y(this.negatif[this.i]) + 18);
        const t = this.i / (this.pas - 1);
        const texte = t < 0.45
            ? 'En gamma positif (bleu), les teneurs de marché vendent quand le prix monte et achètent quand il baisse : le prix reste collé au niveau. En gamma négatif (rouge), ils couvrent dans le sens du mouvement.'
            : t < 0.6
                ? 'Un choc baissier arrive : en gamma positif il est amorti ; en gamma négatif, les ventes de couverture s\'ajoutent à la baisse et l\'accélèrent.'
                : `Bilan. ${this.resume} Repérer le régime de gamma aide à choisir entre stratégies de retour à la moyenne et de suivi de mouvement.`;
        if (this.commentaire.textContent !== texte) {
            this.commentaire.textContent = texte;
        }
    }
}

// ── Rejeu de bougies : deviner la suite ──────────────────────────────────────
// Chaque scénario : trajectoire (images clés), niveau marqué, bougie où l'on s'arrête pour poser la
// question, bonne réponse et explication.
const REPLAY = {
    cassure: {
        nom: 'Cassure',
        prix: [[0, 100], [0.15, 104], [0.25, 101], [0.38, 104.2], [0.48, 101.4], [0.58, 104.6], [0.62, 105.6], [0.8, 110], [1, 112]],
        niveau: 104.6,
        arret: 0.62,
        reponse: 'hausse',
        explication: 'Le prix avait testé trois fois la résistance, avec des creux de plus en plus hauts : les vendeurs cédaient du terrain. La clôture nette au-dessus, avec une grande bougie, a déclenché les ordres d\'achat stop placés au-dessus du niveau. La cassure a tenu.',
    },
    fausse: {
        nom: 'Fausse cassure',
        prix: [[0, 101], [0.15, 104], [0.25, 101.2], [0.38, 104], [0.5, 101.5], [0.58, 103.8], [0.62, 105.3], [0.68, 102.8], [0.85, 100.2], [1, 98]],
        niveau: 104.1,
        arret: 0.62,
        reponse: 'baisse',
        explication: 'Le prix a dépassé la résistance… puis est retombé dans le range dès la bougie suivante. Ceux qui avaient acheté la cassure et ceux dont les stops ont été déclenchés au-dessus des sommets sont piégés : leurs ventes poussent le prix vers le bas du range. Une cassure sans suite rapide est souvent un balayage de liquidité.',
    },
    retest: {
        nom: 'Retour sur le niveau',
        prix: [[0, 96], [0.2, 100], [0.3, 98], [0.42, 100.1], [0.5, 102.5], [0.58, 103.2], [0.66, 100.4], [0.8, 104.5], [1, 107]],
        niveau: 100.1,
        arret: 0.66,
        reponse: 'hausse',
        explication: 'Après la cassure, le prix est revenu tester l\'ancienne résistance, qui a servi de support : les acheteurs qui avaient manqué la cassure en profitent, et ceux qui avaient vendu la résistance soldent leur position. Le retour sur le niveau offrait une entrée avec un stop proche.',
    },
    ict: {
        nom: 'Modèle ICT',
        prix: [[0, 104], [0.12, 102], [0.2, 103.2], [0.3, 101.5], [0.38, 102.8], [0.44, 101.55], [0.47, 100.6], [0.5, 102], [0.56, 104.4], [0.6, 104.8], [0.68, 103.1], [0.85, 106.5], [1, 108]],
        niveau: 101.5,
        libelle: 'plus bas égaux : liquidité vendeuse',
        zones: [
            { bas: 102.7, haut: 103.5, debut: 0.56, texte: 'FVG' },
            { bas: 103.2, haut: 103.2, debut: 0.58, texte: 'cassure de structure', ligne: true },
        ],
        arret: 0.68,
        reponse: 'hausse',
        explication: 'Les étapes du modèle : le prix passe sous les plus bas égaux et déclenche les stops des acheteurs (chasse de liquidité), repart violemment au-dessus du dernier sommet (cassure de structure), en laissant un déséquilibre (FVG). Le retour dans ce FVG est l\'entrée recherchée, stop sous le plus bas de la chasse. Le modèle échoue aussi souvent : si le prix clôture sous le FVG, l\'idée est invalidée.',
    },
};

class Replay {
    constructor(bloc, scenario) {
        this.bloc = bloc;
        this.scenario = REPLAY[scenario] ? scenario : 'cassure';
        this.vitesse = 1;
        this.intervalle = 420;
        Heatmap.prototype.construit.call(this, Object.entries(REPLAY).map(([k, v]) => [k, v.nom]));
        this.bloc.querySelector('.doc-anim__title').textContent = 'Rejeu : que va faire le prix ?';
        this.bloc.querySelector('.doc-sim__legend').textContent = 'Les bougies défilent une à une et s\'arrêtent au moment de décider. Choisissez, puis découvrez la suite. Scénarios construits à titre d\'illustration : dans la réalité, aucune figure ne réussit à tous les coups.';
        this.question = document.createElement('div');
        this.question.className = 'doc-anim__controls doc-anim__question';
        this.commentaire.after(this.question);
        this.charge();
    }

    charge() {
        const s = REPLAY[this.scenario];
        this.onglets.querySelectorAll('button').forEach((b) => {
            const actif = b.dataset.cle === this.scenario;
            b.classList.toggle('is-active', actif);
            b.setAttribute('aria-selected', String(actif));
        });
        this.canvas.setAttribute('aria-label', `Rejeu de bougies : scénario ${s.nom}`);
        const r = aleatoire(this.scenario.length * 131 + 7);
        const n = 48;
        this.bougies = [];
        let precedent = cle(s.prix, 0);
        for (let k = 0; k < n; k++) {
            const t = (k + 1) / n;
            const cible = cle(s.prix, t);
            const o = precedent;
            const c = cible + r.gauss() * 0.25;
            const h = Math.max(o, c) + Math.abs(r.gauss()) * 0.45;
            const l = Math.min(o, c) - Math.abs(r.gauss()) * 0.45;
            this.bougies.push({ o, h, l, c });
            precedent = c;
        }
        this.arret = Math.round(s.arret * n);
        this.pas = n;
        this.i = 0;
        this.repondu = false;
        this.question.replaceChildren();
        this.lecture = true;
        this.majLecture?.();
        if (mouvementReduit()) {
            this.i = this.arret - 1;
        }
        Heatmap.prototype.demarre.call(this);
    }

    pose() {
        this.lecture = false;
        this.majLecture();
        this.commentaire.textContent = 'À vous : à partir d\'ici, que va faire le prix dans les prochaines bougies ?';
        this.question.replaceChildren();
        [['hausse', 'Monter'], ['baisse', 'Baisser'], ['range', 'Rester dans la zone']].forEach(([cleRep, texte]) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'doc-pas__btn';
            b.textContent = texte;
            b.addEventListener('click', () => {
                const s = REPLAY[this.scenario];
                this.repondu = true;
                this.question.replaceChildren();
                this.verdict = `${cleRep === s.reponse ? 'Bien vu.' : 'Pas cette fois.'} ${s.explication}`;
                this.lecture = true;
                this.sale = true;
                this.majLecture();
            });
            this.question.append(b);
        });
    }

    dessine() {
        if (!this.repondu && this.i >= this.arret - 1 && this.lecture) {
            this.i = this.arret - 1;
            this.pose();
        }
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const H = Math.round(W * 0.5);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const s = REPLAY[this.scenario];
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = COULEURS.fond;
        ctx.fillRect(0, 0, W, H);
        const yMin = Math.min(...this.bougies.map((b) => b.l)) - 0.5;
        const yMax = Math.max(...this.bougies.map((b) => b.h)) + 0.5;
        const Y = (v) => 10 + ((yMax - v) / (yMax - yMin)) * (H - 20);
        const largeur = (W - 20) / this.pas;
        ctx.strokeStyle = 'rgba(226,203,145,0.55)';
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(10, Y(s.niveau));
        ctx.lineTo(W - 10, Y(s.niveau));
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.font = '12px system-ui, sans-serif';
        if (s.libelle) {
            ctx.fillStyle = COULEURS.or;
            ctx.textAlign = 'left';
            ctx.fillText(s.libelle, 14, Y(s.niveau) + 14);
        }
        (s.zones || []).forEach((z) => {
            const k0 = Math.round(z.debut * this.pas);
            if (this.i < k0) {
                return;
            }
            const x0 = 10 + k0 * largeur;
            ctx.textAlign = 'left';
            if (z.ligne) {
                ctx.strokeStyle = 'rgba(185,198,230,0.7)';
                ctx.beginPath();
                ctx.moveTo(x0 - 8 * largeur, Y(z.haut));
                ctx.lineTo(x0 + 2 * largeur, Y(z.haut));
                ctx.stroke();
                ctx.fillStyle = COULEURS.bleu;
                ctx.fillText(z.texte, x0 - 8 * largeur, Y(z.haut) - 6);
                return;
            }
            ctx.fillStyle = 'rgba(226,203,145,0.14)';
            ctx.fillRect(x0, Y(z.haut), W - 10 - x0, Y(z.bas) - Y(z.haut));
            ctx.fillStyle = COULEURS.or;
            ctx.fillText(z.texte, W - 50, Y(z.haut) + 14);
        });
        for (let k = 0; k <= this.i; k++) {
            const b = this.bougies[k];
            const x = 10 + (k + 0.5) * largeur;
            const couleur = b.c >= b.o ? COULEURS.achat : COULEURS.vente;
            ctx.strokeStyle = couleur;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(x, Y(b.h));
            ctx.lineTo(x, Y(b.l));
            ctx.stroke();
            ctx.fillStyle = couleur;
            const haut = Y(Math.max(b.o, b.c));
            ctx.fillRect(x - largeur * 0.32, haut, largeur * 0.64, Math.max(1.5, Y(Math.min(b.o, b.c)) - haut));
        }
        if (this.repondu) {
            ctx.strokeStyle = 'rgba(185,198,230,0.45)';
            ctx.beginPath();
            const x = 10 + this.arret * largeur;
            ctx.moveTo(x, 6);
            ctx.lineTo(x, H - 6);
            ctx.stroke();
            if (this.commentaire.textContent !== this.verdict) {
                this.commentaire.textContent = this.verdict;
            }
        } else if (this.i < this.arret - 1 && this.lecture) {
            const texte = 'Observez le comportement du prix autour du niveau en pointillés…';
            if (this.commentaire.textContent !== texte) {
                this.commentaire.textContent = texte;
            }
        }
    }
}

// ── Carnet d'ordres pas à pas : liquidité passive et agressive ────────────────
// Événements appliqués dans l'ordre : ordres limites (passifs), ordres au marché (agressifs), annulations.
const PAS_PRIX = 0.25;
const CARNET_DEPART = {
    bid: { 99.75: 12, 99.5: 18, 99.25: 25, 99: 15, 98.75: 30, 98.5: 22 },
    ask: { 100: 10, 100.25: 16, 100.5: 20, 100.75: 28, 101: 14, 101.25: 24 },
};
const CARNET_EVENEMENTS = [
    { texte: 'Le carnet d\'ordres : à gauche, les acheteurs qui attendent (bid) ; à droite, les vendeurs qui attendent (ask). Ces ordres limites forment la liquidité passive : ils attendent qu\'on vienne les chercher.' },
    { type: 'limite', cote: 'bid', prix: 99.75, taille: 20, texte: 'Un acheteur place 20 lots à 99,75 : ordre limite, passif. Rien ne s\'exécute, le carnet s\'épaissit et le prix ne bouge pas.' },
    { type: 'marche', cote: 'achat', taille: 6, texte: 'Un acheteur pressé envoie 6 lots au marché : il prend ce qui est offert à l\'ask (100,00). Ordre agressif : exécution immédiate, il paie l\'écart. Il reste 4 lots à 100,00.' },
    { type: 'marche', cote: 'achat', taille: 14, texte: '14 lots au marché : les 4 derniers à 100,00, puis 10 à 100,25. Le niveau 100,00 est vidé : le prix monte parce que l\'agressif a consommé plus que ce que le passif offrait.' },
    { type: 'annule', cote: 'ask', prix: 100.5, taille: 20, texte: 'Le vendeur qui attendait 20 lots à 100,50 retire son ordre. La liquidité passive peut disparaître sans aucune transaction : le chemin vers le haut se dégage.' },
    { type: 'limite', cote: 'ask', prix: 100.25, taille: 40, texte: 'Un gros vendeur passif place 40 lots à 100,25. Il ne fait pas baisser le prix : il le freine, en offrant beaucoup là où les acheteurs veulent acheter.' },
    { type: 'marche', cote: 'achat', taille: 30, texte: '30 lots achetés au marché, tous absorbés à 100,25, qui ne cède pas. Beaucoup d\'agressivité, aucun mouvement : c\'est de l\'absorption.' },
    { type: 'marche', cote: 'vente', taille: 50, texte: 'Un vendeur agressif envoie 50 lots au marché : il vide deux niveaux de bid d\'un coup (99,75 puis 99,50). Le prix chute de deux pas : c\'est un balayage.' },
    { texte: 'À retenir : le passif décide où le prix peut s\'arrêter, l\'agressif décide quand il bouge. Le prix ne change que lorsque des ordres au marché vident un niveau, ou que la liquidité est retirée.' },
];

class Carnet {
    constructor(bloc) {
        this.bloc = bloc;
        this.scenario = 'carnet';
        this.vitesse = 1;
        this.intervalle = 5200;
        Heatmap.prototype.construit.call(this, [['carnet', 'Carnet']]);
        this.bloc.querySelector('.doc-anim__title').textContent = 'Liquidité passive et agressive, ordre par ordre';
        this.bloc.querySelector('.doc-sim__legend').textContent = 'Barres vertes : ordres d\'achat en attente ; rouges : ordres de vente en attente. À droite, le ruban des transactions (time & sales). Chiffres orange : quantité consommée ou retirée à l\'étape en cours. Exemple construit à titre d\'illustration.';
        this.onglets.style.display = 'none';
        const suivant = document.createElement('button');
        suivant.type = 'button';
        suivant.className = 'doc-pas__btn';
        suivant.textContent = 'Étape suivante';
        suivant.addEventListener('click', () => {
            this.lecture = false;
            this.majLecture();
            this.i = Math.min(this.i + 1, this.pas - 1);
            this.sale = true;
        });
        this.bloc.querySelector('.doc-anim__controls').prepend(suivant);
        this.canvas.setAttribute('aria-label', 'Carnet d\'ordres animé : ordres limites, ordres au marché et annulations');
        this.pas = CARNET_EVENEMENTS.length;
        this.i = 0;
        this.lecture = !mouvementReduit();
        this.majLecture();
        Heatmap.prototype.demarre.call(this);
    }

    /** État du carnet après les événements 0..i, avec ce que l'étape i a consommé. */
    etat(i) {
        const livre = { bid: { ...CARNET_DEPART.bid }, ask: { ...CARNET_DEPART.ask } };
        const ruban = [];
        let touches = {};
        let dernier = null;
        for (let k = 0; k <= i; k++) {
            const e = CARNET_EVENEMENTS[k];
            touches = {};
            if (e.type === 'limite') {
                livre[e.cote][e.prix] = (livre[e.cote][e.prix] || 0) + e.taille;
                touches[`${e.cote}${e.prix}`] = `+${e.taille}`;
            } else if (e.type === 'annule') {
                livre[e.cote][e.prix] = Math.max(0, (livre[e.cote][e.prix] || 0) - e.taille);
                touches[`${e.cote}${e.prix}`] = `−${e.taille}`;
            } else if (e.type === 'marche') {
                const cote = e.cote === 'achat' ? 'ask' : 'bid';
                const prix = Object.keys(livre[cote]).map(Number).filter((p) => livre[cote][p] > 0)
                    .sort((a, b) => (cote === 'ask' ? a - b : b - a));
                let reste = e.taille;
                for (const p of prix) {
                    if (reste <= 0) {
                        break;
                    }
                    const pris = Math.min(reste, livre[cote][p]);
                    livre[cote][p] -= pris;
                    reste -= pris;
                    touches[`${cote}${p}`] = `−${pris}`;
                    ruban.unshift({ sens: e.cote, prix: p, taille: pris, etape: k });
                    dernier = p;
                }
            }
        }
        return { livre, ruban, touches, dernier };
    }

    dessine() {
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const H = Math.round(Math.max(W * 0.52, 300));
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = COULEURS.fond;
        ctx.fillRect(0, 0, W, H);
        const { livre, ruban, touches, dernier } = this.etat(this.i);
        const niveaux = [];
        for (let p = 101.25; p >= 98.5 - 1e-9; p -= PAS_PRIX) {
            niveaux.push(Math.round(p * 100) / 100);
        }
        const largeurCarnet = W * (W < 560 ? 1 : 0.68);
        const hLigne = (H - 30) / niveaux.length;
        const centre = largeurCarnet / 2;
        const lPrix = Math.min(70, largeurCarnet * 0.18);
        const lBarre = centre - lPrix / 2 - 10;
        const max = 50;
        const police = Math.max(10, Math.min(13, hLigne * 0.55));
        ctx.font = `${police}px system-ui, sans-serif`;
        ctx.textBaseline = 'middle';
        ctx.fillStyle = COULEURS.texte;
        ctx.textAlign = 'center';
        ctx.fillText('Achat (bid)', centre - lPrix / 2 - lBarre / 2, 12);
        ctx.fillText('Vente (ask)', centre + lPrix / 2 + lBarre / 2, 12);
        niveaux.forEach((p, k) => {
            const y = 26 + k * hLigne;
            const yc = y + hLigne / 2;
            if (dernier !== null && Math.abs(p - dernier) < 1e-9) {
                ctx.fillStyle = 'rgba(226,203,145,0.14)';
                ctx.fillRect(4, y, largeurCarnet - 8, hLigne);
            }
            ctx.fillStyle = COULEURS.clair;
            ctx.textAlign = 'center';
            ctx.fillText(p.toFixed(2).replace('.', ','), centre, yc);
            [['bid', -1, COULEURS.achat], ['ask', 1, COULEURS.vente]].forEach(([cote, sens, couleur]) => {
                const q = livre[cote][p] || 0;
                const touche = touches[`${cote}${p}`];
                const x0 = centre + sens * (lPrix / 2 + 4);
                const l = (Math.min(q, max) / max) * lBarre;
                if (q > 0) {
                    ctx.fillStyle = couleur;
                    ctx.globalAlpha = 0.55;
                    ctx.fillRect(sens < 0 ? x0 - l : x0, y + 2, l, hLigne - 4);
                    ctx.globalAlpha = 1;
                    ctx.fillStyle = COULEURS.clair;
                    ctx.textAlign = sens < 0 ? 'right' : 'left';
                    ctx.fillText(String(q), x0 + sens * (l + 6), yc);
                }
                if (touche) {
                    ctx.fillStyle = COULEURS.or;
                    ctx.textAlign = sens < 0 ? 'left' : 'right';
                    ctx.fillText(touche, sens < 0 ? 8 : largeurCarnet - 8, yc);
                }
            });
        });
        if (W >= 560) {
            const x = largeurCarnet + 16;
            ctx.textAlign = 'left';
            ctx.fillStyle = COULEURS.texte;
            ctx.fillText('Transactions', x, 12);
            ruban.slice(0, Math.floor((H - 30) / 22)).forEach((t, k) => {
                ctx.fillStyle = t.sens === 'achat' ? COULEURS.achat : COULEURS.vente;
                ctx.globalAlpha = t.etape === this.i ? 1 : 0.55;
                ctx.fillText(`${t.sens === 'achat' ? 'Achat' : 'Vente'} ${t.taille} @ ${t.prix.toFixed(2).replace('.', ',')}`, x, 36 + k * 22);
            });
            ctx.globalAlpha = 1;
        }
        const texte = CARNET_EVENEMENTS[this.i].texte;
        if (this.commentaire.textContent !== texte) {
            this.commentaire.textContent = texte;
        }
    }
}

// ── Heatmap façon Bookmap : liquidité passive (lignes) et agressive (points) ───
// Chaque ordre limite est une ligne horizontale qui dure tant qu'il attend ; elle s'arrête quand le prix
// le consomme. Les ordres au marché sont des points (vert achat, rouge vente) dont la taille suit le volume.
const BM_NIVEAUX = 64;
const BOOKMAP = {
    passif: {
        nom: 'Liquidité passive',
        prix: [[0, 30], [0.22, 25], [0.32, 23.4], [0.62, 23.3], [0.72, 27], [1, 31]],
        murs: [
            { niveau: 22, cote: 'bid', taille: 1, debut: 0.08, fin: 1, tient: true },
            { niveau: 36, cote: 'ask', taille: 0.75, debut: 0, fin: 1 },
        ],
        agression: (t) => (t < 0.2 ? { sens: 'mixte', force: 0.7 } : t < 0.62 ? { sens: 'vente', force: t > 0.3 ? 1.9 : 1.1 } : { sens: 'achat', force: 1.2 }),
        phases: [
            [0, 'Lecture type Bookmap : chaque ligne horizontale est un ordre limite qui attend (liquidité passive), plus claire quand le volume est gros. Les points sont les ordres au marché (liquidité agressive) : verts à l\'achat, rouges à la vente, plus gros quand le volume est gros.'],
            [0.12, 'Un gros acheteur passif s\'installe sous le prix (ligne jaune). Il ne fait rien bouger : il attend qu\'on vienne lui vendre.'],
            [0.3, 'Les vendeurs agressifs arrivent sur lui : gros points rouges, barres rouges en bas. Ils consomment… mais la ligne jaune ne disparaît pas. Le passif encaisse tout.'],
            [0.62, 'Les vendeurs s\'arrêtent, les achats reprennent et le prix repart. C\'est le passif qui a décidé où le prix s\'arrêtait. Si la ligne avait disparu avant d\'être touchée, c\'était peut-être un leurre (spoofing).'],
        ],
    },
    agressif: {
        nom: 'Liquidité agressive',
        prix: [[0, 22], [0.3, 23.6], [0.38, 24.2], [0.56, 35], [0.7, 37], [1, 36]],
        murs: [
            { niveau: 27, cote: 'ask', taille: 0.6, debut: 0, fin: 1 },
            { niveau: 30, cote: 'ask', taille: 0.8, debut: 0.05, fin: 1 },
            { niveau: 33, cote: 'ask', taille: 0.65, debut: 0.12, fin: 1 },
            { niveau: 19, cote: 'bid', taille: 0.55, debut: 0, fin: 1 },
        ],
        agression: (t) => (t >= 0.37 && t < 0.58 ? { sens: 'achat', force: 2.6 } : { sens: 'mixte', force: 0.75 }),
        phases: [
            [0, 'Lecture type Bookmap : lignes = ordres limites qui attendent (liquidité passive) ; points = ordres au marché (liquidité agressive), verts à l\'achat, rouges à la vente.'],
            [0.18, 'Au-dessus du prix, plusieurs couches de vendeurs passifs (lignes au-dessus de la courbe). Tant que personne ne les attaque, le prix reste en place.'],
            [0.37, 'Des acheteurs agressifs frappent en rafale : gros points verts, barres vertes en bas. Chaque couche de vendeurs est consommée : sa ligne s\'arrête net à l\'instant où le prix la traverse.'],
            [0.6, 'L\'agressif a déplacé le prix tant qu\'il consommait plus que ce que le passif offrait. Les rafales cessent : le prix se stabilise sous la couche suivante.'],
        ],
    },
};

/** Couleur d'intensité (bleu nuit → bleu → cyan → jaune), comme sur les heatmaps de carnet. */
function teinteBookmap(v) {
    const arrets = [[0, [12, 22, 48]], [0.35, [30, 90, 200]], [0.7, [70, 200, 230]], [1, [245, 232, 120]]];
    for (let k = 1; k < arrets.length; k++) {
        if (v <= arrets[k][0]) {
            const [a0, c0] = arrets[k - 1];
            const [a1, c1] = arrets[k];
            const f = (v - a0) / (a1 - a0);
            return c0.map((x, j) => Math.round(x + (c1[j] - x) * f));
        }
    }
    return arrets[arrets.length - 1][1];
}

class Bookmap {
    constructor(bloc, scenario) {
        this.bloc = bloc;
        this.scenario = BOOKMAP[scenario] ? scenario : 'passif';
        this.vitesse = 1;
        this.intervalle = 75;
        Heatmap.prototype.construit.call(this, Object.entries(BOOKMAP).map(([k, v]) => [k, v.nom]));
        this.bloc.classList.add('doc-anim--sombre');
        this.bloc.querySelector('.doc-anim__title').textContent = 'Heatmap du carnet et ordres agressifs';
        this.bloc.querySelector('.doc-sim__legend').textContent = 'Lignes : ordres limites en attente (bleu = peu, jaune = beaucoup). Courbe blanche : dernier prix. Points : ordres au marché (vert achat, rouge vente). À droite : profondeur du carnet à l\'instant présent (rouge : vendeurs, bleu : acheteurs). En bas : volume agressif par instant. Scénario construit à titre d\'illustration.';
        this.charge();
    }

    charge() {
        const s = BOOKMAP[this.scenario];
        this.onglets.querySelectorAll('button').forEach((b) => {
            const actif = b.dataset.cle === this.scenario;
            b.classList.toggle('is-active', actif);
            b.setAttribute('aria-selected', String(actif));
        });
        this.canvas.setAttribute('aria-label', `Heatmap du carnet façon Bookmap : ${s.nom}`);
        const r = aleatoire(this.scenario.length * 211 + 5);
        const pas = 240;
        this.pas = pas;
        this.prix = [];
        let bruit = 0;
        for (let i = 0; i < pas; i++) {
            const t = i / (pas - 1);
            bruit = bruit * 0.85 + r.gauss() * 0.22;
            let p = cle(s.prix, t) + bruit;
            s.murs.filter((m) => m.tient && t >= m.debut).forEach((m) => {
                p = m.cote === 'bid' ? Math.max(p, m.niveau + 0.7) : Math.min(p, m.niveau - 0.7);
            });
            this.prix.push(p);
        }
        // Ordres limites : [niveau, côté, taille, début, fin].
        const ordres = [];
        for (let i = 0; i < pas; i += 2) {
            for (let k = 0; k < 2; k++) {
                const ecart = Math.round(2 + r.u() * 18);
                const cote = r.u() < 0.5 ? 'ask' : 'bid';
                const niveau = Math.round(this.prix[i]) + (cote === 'ask' ? ecart : -ecart);
                if (niveau < 1 || niveau >= BM_NIVEAUX - 1) {
                    continue;
                }
                const gros = r.u() < 0.03;
                ordres.push({ niveau, cote, taille: gros ? 0.3 + r.u() * 0.15 : 0.04 + r.u() * 0.1, debut: i, fin: Math.min(pas, i + 20 + Math.floor(r.u() * (gros ? 200 : 110))) });
            }
        }
        s.murs.forEach((m) => ordres.push({ niveau: m.niveau, cote: m.cote, taille: m.taille, debut: Math.round(m.debut * (pas - 1)), fin: Math.round(m.fin * pas), tient: m.tient }));
        // Un ordre disparaît quand le prix le traverse (consommé), sauf un mur qui tient.
        ordres.forEach((o) => {
            if (o.tient) {
                return;
            }
            for (let i = o.debut; i < o.fin; i++) {
                if ((o.cote === 'ask' && this.prix[i] >= o.niveau) || (o.cote === 'bid' && this.prix[i] <= o.niveau)) {
                    o.fin = i;
                    break;
                }
            }
        });
        this.grille = Array.from({ length: pas }, () => new Float32Array(BM_NIVEAUX));
        this.cote = Array.from({ length: pas }, () => new Int8Array(BM_NIVEAUX));
        ordres.forEach((o) => {
            for (let i = o.debut; i < o.fin; i++) {
                this.grille[i][o.niveau] = Math.min(1, this.grille[i][o.niveau] + o.taille);
                this.cote[i][o.niveau] = o.cote === 'ask' ? 1 : -1;
            }
        });
        // Image de toute la séquence (une colonne par instant), dessinée ensuite par morceaux.
        this.image = document.createElement('canvas');
        this.image.width = pas;
        this.image.height = BM_NIVEAUX;
        const ctx = this.image.getContext('2d');
        const px = ctx.createImageData(pas, BM_NIVEAUX);
        for (let i = 0; i < pas; i++) {
            for (let n = 0; n < BM_NIVEAUX; n++) {
                const v = Math.min(1, this.grille[i][n] * 1.15);
                const [R, G, B] = teinteBookmap(v);
                const o = ((BM_NIVEAUX - 1 - n) * pas + i) * 4;
                px.data[o] = R;
                px.data[o + 1] = G;
                px.data[o + 2] = B;
                px.data[o + 3] = 255;
            }
        }
        ctx.putImageData(px, 0, 0);
        this.agressions = [];
        for (let i = 0; i < pas; i++) {
            const t = i / (pas - 1);
            const a = s.agression(t);
            const achat = a.sens === 'achat' ? r.u() < 0.85 : a.sens === 'vente' ? r.u() < 0.15 : r.u() < 0.5;
            const volume = r.u() < 0.5 * Math.min(1.6, a.force) ? a.force * (0.3 + r.u() ** 2 * 1.2) : 0;
            this.agressions.push({ achat, volume });
        }
        this.i = 0;
        this.lecture = !mouvementReduit();
        this.majLecture?.();
        if (!this.lecture) {
            this.i = pas - 1;
        }
        Heatmap.prototype.demarre.call(this);
    }

    dessine() {
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const H = Math.round(W * 0.6);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const s = BOOKMAP[this.scenario];
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#0a1226';
        ctx.fillRect(0, 0, W, H);
        const lProfil = Math.max(46, W * 0.12);
        const lCarte = W - lProfil - 6;
        const hBas = Math.round(H * 0.18);
        const hCarte = H - hBas - 8;
        const lCol = lCarte / this.pas;
        const hNiv = hCarte / BM_NIVEAUX;
        const Y = (n) => hCarte - (n + 0.5) * hNiv;
        // Heatmap jusqu'à l'instant présent (lissage : effet de lueur des lignes).
        ctx.imageSmoothingEnabled = true;
        const fin = this.i + 1;
        ctx.drawImage(this.image, 0, 0, fin, BM_NIVEAUX, 0, 0, fin * lCol, hCarte);
        // Courbe du prix.
        ctx.strokeStyle = 'rgba(255,255,255,0.92)';
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        for (let c = 0; c <= this.i; c++) {
            const x = (c + 0.5) * lCol;
            if (c === 0) {
                ctx.moveTo(x, Y(this.prix[c]));
            } else {
                ctx.lineTo(x, Y(this.prix[c]));
            }
        }
        ctx.stroke();
        // Points des ordres agressifs.
        for (let c = 0; c <= this.i; c++) {
            const a = this.agressions[c];
            if (!a.volume) {
                continue;
            }
            const rayon = 2 + a.volume * hNiv * 0.9;
            ctx.beginPath();
            ctx.arc((c + 0.5) * lCol, Y(this.prix[c]), rayon, 0, Math.PI * 2);
            ctx.fillStyle = a.achat ? 'rgba(46,190,96,0.85)' : 'rgba(232,64,58,0.85)';
            ctx.fill();
            ctx.strokeStyle = 'rgba(0,0,0,0.35)';
            ctx.lineWidth = 1;
            ctx.stroke();
        }
        // Profondeur du carnet à l'instant présent.
        const x0 = lCarte + 6;
        ctx.fillStyle = '#0d1630';
        ctx.fillRect(x0, 0, lProfil, hCarte);
        const col = this.grille[this.i];
        const p = this.prix[this.i];
        for (let n = 0; n < BM_NIVEAUX; n++) {
            if (col[n] < 0.04) {
                continue;
            }
            ctx.fillStyle = n > p ? '#d8433a' : '#2f6fe0';
            ctx.fillRect(x0, Y(n) - hNiv * 0.42, Math.min(1, col[n]) * (lProfil - 4), hNiv * 0.84);
        }
        // Volume agressif par instant (barres vertes vers le haut, rouges vers le bas).
        const yMilieu = hCarte + 8 + hBas / 2;
        ctx.strokeStyle = 'rgba(168,179,174,0.25)';
        ctx.beginPath();
        ctx.moveTo(0, yMilieu);
        ctx.lineTo(lCarte, yMilieu);
        ctx.stroke();
        for (let c = 0; c <= this.i; c++) {
            const a = this.agressions[c];
            if (!a.volume) {
                continue;
            }
            const h = Math.min(hBas / 2 - 2, a.volume * hBas * 0.22);
            ctx.fillStyle = a.achat ? '#2ebe60' : '#e8403a';
            ctx.fillRect(c * lCol, a.achat ? yMilieu - h : yMilieu, Math.max(1, lCol * 0.8), h);
        }
        ctx.fillStyle = 'rgba(168,179,174,0.8)';
        ctx.font = '11px system-ui, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('achats / ventes agressifs', 6, hCarte + 20);
        const t = this.i / (this.pas - 1);
        const phase = [...s.phases].reverse().find(([debut]) => t >= debut);
        if (phase && this.commentaire.textContent !== phase[1]) {
            this.commentaire.textContent = phase[1];
        }
    }
}

// ── Carte du gamma des teneurs de marché (strike × temps) ────────────────────
// Profil calculé avec le gamma de Black-Scholes sur des positions ouvertes fictives : appels concentrés
// en haut (mur des calls), puts en bas (mur des puts). Convention usuelle : gamma des calls compté
// positif, celui des puts négatif pour les teneurs de marché.
const GEX_STRIKES = Array.from({ length: 31 }, (_, k) => 5650 + k * 10);
const GEX_SCENARIOS = {
    aimant: {
        nom: 'Gamma positif : le prix est tenu',
        prix: [[0, 5795], [0.15, 5818], [0.3, 5786], [0.45, 5822], [0.6, 5792], [0.8, 5815], [1, 5805]],
        phases: [
            [0, 'Au-dessus du niveau de bascule (zero gamma), les teneurs de marché sont globalement en gamma positif : ils vendent quand le prix monte et achètent quand il baisse.'],
            [0.3, 'Résultat : le prix oscille dans un couloir étroit. Les bandes lumineuses montrent où le gamma est concentré : le mur des calls au-dessus joue souvent le rôle de plafond.'],
            [0.7, 'Les barres à droite donnent le gamma net par strike à l\'instant présent : bleu positif, rouge négatif. Plus elles sont longues, plus les couvertures pèsent sur le prix à ce niveau.'],
        ],
    },
    bascule: {
        nom: 'Passage sous zero gamma',
        prix: [[0, 5800], [0.25, 5785], [0.4, 5768], [0.5, 5752], [0.62, 5728], [0.75, 5712], [0.85, 5722], [1, 5706]],
        phases: [
            [0, 'Le prix part au-dessus du niveau de bascule : régime calme, couvertures contre le mouvement.'],
            [0.42, 'Il passe sous le zero gamma : les teneurs de marché sont maintenant en gamma négatif. Ils doivent vendre quand le prix baisse, ce qui accélère la baisse et élargit les oscillations.'],
            [0.72, 'Le mouvement ralentit près du mur des puts, où le gamma est le plus concentré. Ces niveaux sont des zones de réaction probables, pas des murs infranchissables : ils bougent chaque jour avec les positions.'],
        ],
    },
};

function gammaBS(S, K, sigma, T) {
    const d1 = (Math.log(S / K) + 0.5 * sigma * sigma * T) / (sigma * Math.sqrt(T));
    return Math.exp(-0.5 * d1 * d1) / (Math.sqrt(2 * Math.PI) * S * sigma * Math.sqrt(T));
}

function profilGex(S) {
    // Échéance très courte (type 0DTE) : le gamma est concentré près des strikes.
    const sigma = 0.16;
    const T = 1 / 365;
    return GEX_STRIKES.map((K) => {
        const calls = 18 * Math.exp(-(((K - 5850) / 28) ** 2)) + 6 * Math.exp(-(((K - 5800) / 50) ** 2));
        const puts = 20 * Math.exp(-(((K - 5700) / 26) ** 2)) + 7 * Math.exp(-(((K - 5750) / 45) ** 2));
        // Gamma en dollars pour 1 % de mouvement : OI × Γ × S² × 0,01.
        return (calls - puts) * gammaBS(S, K, sigma, T) * S * S * 0.01;
    });
}

class GexCarte {
    constructor(bloc, scenario) {
        this.bloc = bloc;
        this.scenario = GEX_SCENARIOS[scenario] ? scenario : 'aimant';
        this.vitesse = 1;
        this.intervalle = 70;
        Heatmap.prototype.construit.call(this, Object.entries(GEX_SCENARIOS).map(([k, v]) => [k, v.nom]));
        this.bloc.classList.add('doc-anim--sombre');
        this.bloc.querySelector('.doc-anim__title').textContent = 'Carte du gamma des teneurs de marché (strike × temps)';
        this.bloc.querySelector('.doc-sim__legend').textContent = 'Fond : concentration du gamma par strike au fil de la séance (plus c\'est lumineux, plus il est fort). Courbe : prix. À droite : gamma net par strike (bleu positif, rouge négatif). Positions fictives, gamma calculé avec Black-Scholes ; illustration, pas des données de marché.';
        // Niveau de bascule : prix où le gamma net total change de signe.
        let zero = null;
        let precedent = null;
        for (let S = 5650; S <= 5950; S += 1) {
            const total = profilGex(S).reduce((a, b) => a + b, 0);
            if (precedent !== null && Math.sign(total) !== Math.sign(precedent)) {
                zero = S;
            }
            precedent = total;
        }
        this.zero = zero ?? 5760;
        const ref = profilGex(5800);
        this.murCalls = GEX_STRIKES[ref.indexOf(Math.max(...ref))];
        this.murPuts = GEX_STRIKES[ref.indexOf(Math.min(...ref))];
        this.tuiles = document.createElement('dl');
        this.tuiles.className = 'doc-anim__tiles';
        this.canvas.after(this.tuiles);
        this.charge();
    }

    charge() {
        const s = GEX_SCENARIOS[this.scenario];
        this.onglets.querySelectorAll('button').forEach((b) => {
            const actif = b.dataset.cle === this.scenario;
            b.classList.toggle('is-active', actif);
            b.setAttribute('aria-selected', String(actif));
        });
        this.canvas.setAttribute('aria-label', `Carte du gamma des teneurs de marché : ${s.nom}`);
        const r = aleatoire(this.scenario.length * 389 + 3);
        const pas = 220;
        this.pas = pas;
        this.prix = [];
        this.profils = [];
        let ecart = 0;
        for (let i = 0; i < pas; i++) {
            const t = i / (pas - 1);
            const base = cle(s.prix, t);
            // Bruit plus fort sous le niveau de bascule (gamma négatif).
            const amplitude = base + ecart < this.zero ? 4.2 : 1.6;
            ecart = ecart * 0.8 + r.gauss() * amplitude;
            const p = base + ecart;
            this.prix.push(p);
            this.profils.push(profilGex(p));
        }
        this.maxAbs = Math.max(...this.profils.flat().map(Math.abs));
        this.i = 0;
        this.lecture = !mouvementReduit();
        this.majLecture?.();
        if (!this.lecture) {
            this.i = pas - 1;
        }
        Heatmap.prototype.demarre.call(this);
    }

    dessine() {
        const W = this.canvas.clientWidth;
        if (!W) {
            return;
        }
        const H = Math.round(W * 0.55);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(W * dpr)) {
            this.canvas.width = Math.round(W * dpr);
            this.canvas.height = Math.round(H * dpr);
            this.canvas.style.height = `${H}px`;
        }
        const s = GEX_SCENARIOS[this.scenario];
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#0a0d1a';
        ctx.fillRect(0, 0, W, H);
        const lBarres = Math.max(70, W * 0.2);
        const lEtiquettes = W < 600 ? 0 : 92;
        const lCarte = W - lBarres - lEtiquettes - 8;
        const kMin = GEX_STRIKES[0] - 5;
        const kMax = GEX_STRIKES[GEX_STRIKES.length - 1] + 5;
        const Y = (v) => ((kMax - v) / (kMax - kMin)) * (H - 10) + 5;
        const hK = (H - 10) / GEX_STRIKES.length;
        const lCol = lCarte / this.pas;
        // Fond : intensité du gamma par strike à chaque instant.
        for (let c = 0; c <= this.i; c += 1) {
            const profil = this.profils[c];
            profil.forEach((g, k) => {
                const v = (Math.abs(g) / this.maxAbs) ** 1.4;
                if (v < 0.03) {
                    return;
                }
                const l = 12 + v * 58;
                ctx.fillStyle = `hsla(${26 + v * 22}, 95%, ${l}%, ${0.25 + v * 0.75})`;
                ctx.fillRect(c * lCol, Y(GEX_STRIKES[k]) - hK / 2, lCol + 0.6, hK);
            });
        }
        // Niveaux remarquables.
        const niveaux = [[this.murCalls, `MUR CALLS ${this.murCalls}`, '#3ecf8e'], [this.zero, `ZERO Γ ${this.zero}`, '#f2c94c'], [this.murPuts, `MUR PUTS ${this.murPuts}`, '#eb5757']];
        ctx.font = '600 11px system-ui, sans-serif';
        niveaux.forEach(([niveau, texte, couleur]) => {
            ctx.strokeStyle = couleur;
            ctx.globalAlpha = 0.75;
            ctx.setLineDash([5, 4]);
            ctx.beginPath();
            ctx.moveTo(0, Y(niveau));
            ctx.lineTo(lCarte + 6, Y(niveau));
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.globalAlpha = 1;
            if (lEtiquettes) {
                ctx.fillStyle = couleur;
                ctx.fillRect(lCarte + 8, Y(niveau) - 9, lEtiquettes - 6, 18);
                ctx.fillStyle = '#0a0d1a';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(texte, lCarte + 8 + (lEtiquettes - 6) / 2, Y(niveau) + 0.5);
            }
        });
        ctx.textBaseline = 'alphabetic';
        // Prix.
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        for (let c = 0; c <= this.i; c++) {
            const x = (c + 0.5) * lCol;
            if (c === 0) {
                ctx.moveTo(x, Y(this.prix[c]));
            } else {
                ctx.lineTo(x, Y(this.prix[c]));
            }
        }
        ctx.stroke();
        const xp = (this.i + 0.5) * lCol;
        ctx.beginPath();
        ctx.arc(xp, Y(this.prix[this.i]), 4, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        // Gamma net par strike à l'instant présent.
        const x0 = W - lBarres;
        const xm = x0 + lBarres / 2;
        ctx.fillStyle = '#0e1224';
        ctx.fillRect(x0, 0, lBarres, H);
        this.profils[this.i].forEach((g, k) => {
            const l = (g / this.maxAbs) * (lBarres / 2 - 4);
            ctx.fillStyle = g >= 0 ? '#3b82f6' : '#ef4444';
            ctx.fillRect(g >= 0 ? xm : xm + l, Y(GEX_STRIKES[k]) - hK * 0.4, Math.abs(l), hK * 0.8);
        });
        ctx.strokeStyle = 'rgba(255,255,255,0.25)';
        ctx.beginPath();
        ctx.moveTo(xm, 0);
        ctx.lineTo(xm, H);
        ctx.stroke();
        // Tuiles de synthèse.
        const net = this.profils[this.i].reduce((a, b) => a + b, 0);
        const p = this.prix[this.i];
        const tuiles = [
            ['Prix', p.toFixed(1).replace('.', ',')],
            ['Gamma net', `${net >= 0 ? '+' : ''}${(net / this.maxAbs).toFixed(1).replace('.', ',')} u.`],
            ['Zero gamma', String(this.zero)],
            ['Mur des calls', String(this.murCalls)],
            ['Mur des puts', String(this.murPuts)],
            ['Régime', p >= this.zero ? 'gamma positif' : 'gamma négatif'],
        ];
        const cleTuiles = tuiles.map((x) => x.join(':')).join('|');
        if (this.cleTuiles !== cleTuiles) {
            this.cleTuiles = cleTuiles;
            this.tuiles.replaceChildren(...tuiles.flatMap(([k, v]) => {
                const div = document.createElement('div');
                const dt = document.createElement('dt');
                dt.textContent = k;
                const dd = document.createElement('dd');
                dd.textContent = v;
                if (k === 'Régime') {
                    dd.className = p >= this.zero ? 'is-positive' : 'is-negative';
                }
                div.append(dt, dd);
                return [div];
            }));
        }
        const t = this.i / (this.pas - 1);
        const phase = [...s.phases].reverse().find(([debut]) => t >= debut);
        if (phase && this.commentaire.textContent !== phase[1]) {
            this.commentaire.textContent = phase[1];
        }
    }
}

/** Boutons lecture/pause, recommencer et vitesse, communs aux animations. */
function controles(anim) {
    const zone = document.createElement('div');
    zone.className = 'doc-anim__controls';
    const lecture = document.createElement('button');
    lecture.type = 'button';
    lecture.className = 'doc-pas__btn';
    anim.majLecture = () => {
        lecture.textContent = anim.lecture ? 'Pause' : 'Lecture';
    };
    lecture.addEventListener('click', () => {
        if (!anim.lecture && anim.i >= anim.pas - 1) {
            anim.i = 0;
        }
        anim.lecture = !anim.lecture;
        anim.majLecture();
    });
    const debut = document.createElement('button');
    debut.type = 'button';
    debut.className = 'doc-pas__btn';
    debut.textContent = 'Recommencer';
    debut.addEventListener('click', () => {
        if (anim.charge && anim instanceof Replay) {
            anim.charge();
            return;
        }
        anim.i = 0;
        anim.lecture = true;
        anim.majLecture();
    });
    // Vitesse : trois choix explicites plutôt qu'un bouton qui change de valeur à chaque clic.
    const vitesses = document.createElement('div');
    vitesses.className = 'doc-sim__choice-options doc-anim__speed';
    vitesses.setAttribute('role', 'radiogroup');
    vitesses.setAttribute('aria-label', 'Vitesse de l\'animation');
    [[0.5, 'Lent'], [1, 'Normal'], [2, 'Rapide']].forEach(([valeur, texte]) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'radio');
        b.textContent = texte;
        const maj = () => {
            vitesses.querySelectorAll('button').forEach((x) => {
                x.classList.remove('is-active');
                x.setAttribute('aria-checked', 'false');
            });
            b.classList.add('is-active');
            b.setAttribute('aria-checked', 'true');
        };
        b.addEventListener('click', () => {
            anim.vitesse = valeur;
            maj();
        });
        if (valeur === 1) {
            maj();
        }
        vitesses.append(b);
    });
    zone.append(lecture, debut, vitesses);
    return zone;
}

export function initAnimations(racine = document) {
    racine.querySelectorAll('.doc-anim:not(.is-live)').forEach((bloc) => {
        const classes = [...bloc.classList];
        const type = classes.find((c) => c.startsWith('doc-anim--'))?.slice(10);
        const scenario = classes.find((c) => c.startsWith('doc-anim-scenario--'))?.slice(19);
        if (typeof ResizeObserver !== 'function') {
            return;
        }
        if (type === 'heatmap') {
            new Heatmap(bloc, scenario);
        } else if (type === 'footprint') {
            new Footprint(bloc, scenario);
        } else if (type === 'gex') {
            new Gex(bloc);
        } else if (type === 'replay') {
            new Replay(bloc, scenario);
        } else if (type === 'carnet') {
            new Carnet(bloc);
        } else if (type === 'bookmap') {
            new Bookmap(bloc, scenario);
        } else if (type === 'gex-carte') {
            new GexCarte(bloc, scenario);
        }
    });
}
