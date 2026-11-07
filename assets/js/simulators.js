// Simulateurs interactifs des articles : <div class="doc-sim doc-sim--TYPE"> … </div>.
// Le contenu d'origine du bloc (un exemple chiffré statique) sert de repli sans JavaScript ; ce module
// le remplace par des curseurs, des résultats et, selon le type, un graphique ou un bouton « Relancer ».

const nf = (v, d = 0) => v.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d });
const euros = (v) => `${nf(v)} €`;
const pct = (v, d = 1) => `${nf(v * 100, d)} %`;

/** Générateur pseudo-aléatoire reproductible (mulberry32) et tirage gaussien. */
function aleatoire(graine) {
    let a = graine >>> 0;
    const u = () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    const gauss = () => Math.sqrt(-2 * Math.log(1 - u())) * Math.cos(2 * Math.PI * u());
    return { u, gauss };
}

/** Graphique en lignes minimal sur canvas : séries [{ points: [[x, y]], couleur, epaisseur }]. */
function trace(canvas, series, { ymin, ymax, ligneZero = null, etiquette = (v) => nf(v) } = {}) {
    const largeur = canvas.clientWidth;
    const hauteur = Math.round(largeur * 0.42);
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = largeur * dpr;
    canvas.height = hauteur * dpr;
    canvas.style.height = `${hauteur}px`;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, largeur, hauteur);
    const tous = series.flatMap((s) => s.points);
    const xmin = Math.min(...tous.map((p) => p[0]));
    const xmax = Math.max(...tous.map((p) => p[0]));
    const y0 = ymin ?? Math.min(...tous.map((p) => p[1]));
    const y1 = ymax ?? Math.max(...tous.map((p) => p[1]));
    const g = 56;
    const d = 12;
    const h = 12;
    const b = 22;
    const X = (x) => g + ((x - xmin) / (xmax - xmin || 1)) * (largeur - g - d);
    const Y = (y) => hauteur - b - ((y - y0) / (y1 - y0 || 1)) * (hauteur - h - b);
    ctx.font = '11px system-ui, sans-serif';
    ctx.fillStyle = '#A8B3AE';
    ctx.strokeStyle = 'rgba(168,179,174,0.18)';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const v = y0 + ((y1 - y0) * i) / 4;
        ctx.beginPath();
        ctx.moveTo(g, Y(v));
        ctx.lineTo(largeur - d, Y(v));
        ctx.stroke();
        ctx.textAlign = 'right';
        ctx.fillText(etiquette(v), g - 6, Y(v) + 4);
    }
    if (ligneZero !== null) {
        ctx.strokeStyle = 'rgba(200,106,92,0.8)';
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(g, Y(ligneZero));
        ctx.lineTo(largeur - d, Y(ligneZero));
        ctx.stroke();
        ctx.setLineDash([]);
    }
    series.forEach((s) => {
        ctx.strokeStyle = s.couleur;
        ctx.lineWidth = s.epaisseur || 1.5;
        ctx.beginPath();
        s.points.forEach(([x, y], i) => (i ? ctx.lineTo(X(x), Y(y)) : ctx.moveTo(X(x), Y(y))));
        ctx.stroke();
    });
}

// ── Définition des simulateurs ────────────────────────────────────────────────
// curseurs : [clé, libellé, min, max, pas, défaut, format]
const SIMULATEURS = {
    'taille-position': {
        titre: 'Calculateur de taille de position',
        curseurs: [
            ['capital', 'Capital du compte', 1000, 100000, 500, 10000, euros],
            ['risque', 'Risque par trade', 0.0025, 0.03, 0.0025, 0.01, (v) => pct(v, 2)],
            ['stop', 'Distance du stop (en points)', 5, 200, 1, 40, (v) => `${nf(v)} points`],
            ['valeur', 'Valeur d\'un point pour 1 unité', 0.1, 50, 0.1, 1, (v) => `${nf(v, 1)} €`],
        ],
        calcule: ({ capital, risque, stop, valeur }) => {
            const montant = capital * risque;
            const quantite = montant / (stop * valeur);
            return [
                ['Montant risqué si le stop est touché', euros(montant)],
                ['Quantité à prendre', `${nf(quantite, 2)} unités`],
                ['Pertes consécutives pour perdre 20 % du compte', nf(Math.ceil(Math.log(0.8) / Math.log(1 - risque)))],
            ];
        },
    },
    'seuil-rentabilite': {
        titre: 'Ratio gain/perte, taux de réussite et espérance',
        curseurs: [
            ['ratio', 'Gain moyen / perte moyenne (R)', 0.5, 5, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['reussite', 'Taux de réussite', 0.1, 0.8, 0.01, 0.4, (v) => pct(v, 0)],
            ['couts', 'Coûts par trade (en R)', 0, 0.3, 0.01, 0.05, (v) => `${nf(v, 2)} R`],
        ],
        calcule: ({ ratio, reussite, couts }) => {
            const esperance = reussite * ratio - (1 - reussite) - couts;
            const seuil = (1 + couts) / (1 + ratio);
            return [
                ['Taux de réussite minimal pour être rentable', pct(seuil)],
                ['Espérance par trade', `${esperance >= 0 ? '+' : ''}${nf(esperance, 2)} R`],
                ['Sur 100 trades', `${esperance >= 0 ? '+' : ''}${nf(esperance * 100, 0)} R en moyenne`],
            ];
        },
    },
    ruine: {
        graphique: true,
        titre: 'Simulateur de drawdown (500 comptes simulés)',
        relance: true,
        curseurs: [
            ['reussite', 'Taux de réussite', 0.2, 0.7, 0.01, 0.4, (v) => pct(v, 0)],
            ['ratio', 'Gain moyen (R)', 0.5, 4, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['risque', 'Risque par trade', 0.0025, 0.1, 0.0025, 0.01, (v) => pct(v, 2)],
            ['trades', 'Nombre de trades', 50, 500, 10, 200, (v) => nf(v)],
        ],
        calcule: ({ reussite, ratio, risque, trades }, { graine, canvas }) => {
            const r = aleatoire(graine);
            const N = 500;
            let touche30 = 0;
            const finaux = [];
            const exemples = [];
            for (let k = 0; k < N; k++) {
                let c = 1;
                let pic = 1;
                let pire = 0;
                const pts = [[0, 100]];
                for (let t = 1; t <= trades; t++) {
                    c *= r.u() < reussite ? 1 + risque * ratio : 1 - risque;
                    pic = Math.max(pic, c);
                    pire = Math.min(pire, c / pic - 1);
                    if (k < 12) {
                        pts.push([t, c * 100]);
                    }
                }
                touche30 += pire <= -0.3;
                finaux.push(c);
                if (k < 12) {
                    exemples.push(pts);
                }
            }
            finaux.sort((a, b) => a - b);
            trace(canvas, exemples.map((p) => ({ points: p, couleur: 'rgba(185,198,230,0.55)' })), { ligneZero: 100, etiquette: (v) => nf(v) });
            return [
                ['Comptes passés par une baisse de 30 % ou plus', pct(touche30 / N, 0)],
                ['Capital final médian (départ 100)', nf(finaux[N / 2] * 100)],
                ['Comptes finissant en perte', pct(finaux.filter((v) => v < 1).length / N, 0)],
            ];
        },
    },
    'interets-composes': {
        graphique: true,
        titre: 'Simulateur d\'intérêts composés',
        curseurs: [
            ['versement', 'Versement mensuel', 0, 2000, 25, 200, euros],
            ['rendement', 'Rendement annuel brut', 0, 0.1, 0.0025, 0.06, (v) => pct(v)],
            ['frais', 'Frais annuels', 0, 0.025, 0.001, 0.002, (v) => pct(v)],
            ['annees', 'Durée', 1, 45, 1, 25, (v) => `${nf(v)} ans`],
        ],
        calcule: ({ versement, rendement, frais, annees }, { canvas }) => {
            const courbe = (taux) => {
                const pts = [[0, 0]];
                let c = 0;
                for (let m = 1; m <= annees * 12; m++) {
                    c = c * (1 + taux) ** (1 / 12) + versement;
                    if (m % 12 === 0) {
                        pts.push([m / 12, c]);
                    }
                }
                return pts;
            };
            const net = courbe(rendement - frais);
            const verse = net.map(([x]) => [x, versement * 12 * x]);
            const sansFrais = courbe(rendement);
            trace(canvas, [
                { points: verse, couleur: '#A8B3AE' },
                { points: sansFrais, couleur: 'rgba(79,157,126,0.5)' },
                { points: net, couleur: '#E2CB91', epaisseur: 2.2 },
            ], { ymin: 0, etiquette: (v) => `${nf(v / 1000)} k€` });
            const fin = net[net.length - 1][1];
            const total = versement * 12 * annees;
            return [
                ['Total versé', euros(total)],
                ['Capital final (après frais)', euros(fin)],
                ['Coût des frais sur la période', euros(sansFrais[sansFrais.length - 1][1] - fin)],
            ];
        },
        legende: 'Gris : versements cumulés. Vert : sans frais. Or : après frais.',
    },
    kelly: {
        graphique: true,
        titre: 'Croissance selon la fraction risquée (critère de Kelly)',
        curseurs: [
            ['reussite', 'Taux de réussite', 0.2, 0.8, 0.01, 0.4, (v) => pct(v, 0)],
            ['ratio', 'Gain moyen (R)', 0.5, 5, 0.1, 2, (v) => `${nf(v, 1)} R`],
        ],
        calcule: ({ reussite, ratio }, { canvas }) => {
            const g = (f) => reussite * Math.log(1 + ratio * f) + (1 - reussite) * Math.log(1 - f);
            const fs = (reussite * (ratio + 1) - 1) / ratio;
            const pts = [];
            for (let f = 0; f <= 0.6; f += 0.005) {
                pts.push([f * 100, Math.max(g(f) * 100, -5)]);
            }
            trace(canvas, [{ points: pts, couleur: '#E2CB91', epaisseur: 2.2 }], { ligneZero: 0, etiquette: (v) => `${nf(v, 1)} %` });
            return [
                ['Fraction de Kelly', fs > 0 ? pct(fs) : 'aucune (espérance négative)'],
                ['Demi-Kelly, plus prudent', fs > 0 ? pct(fs / 2) : '—'],
                ['Croissance par trade à Kelly', fs > 0 ? pct(g(fs), 2) : '—'],
            ];
        },
        legende: 'Axe horizontal : fraction du capital risquée par trade (0 à 60 %). Sous la ligne rouge, le capital décroît.',
    },
    'marche-aleatoire': {
        graphique: true,
        titre: 'Dix trajectoires de pur hasard',
        relance: true,
        curseurs: [
            ['vol', 'Volatilité annuelle', 0.05, 0.6, 0.01, 0.2, (v) => pct(v, 0)],
            ['tendance', 'Rendement moyen annuel', -0.1, 0.15, 0.01, 0.07, (v) => pct(v, 0)],
            ['annees', 'Durée', 1, 10, 1, 3, (v) => `${nf(v)} ans`],
        ],
        calcule: ({ vol, tendance, annees }, { graine, canvas }) => {
            const r = aleatoire(graine);
            const jours = annees * 252;
            const series = [];
            let finissentPlusBas = 0;
            for (let k = 0; k < 10; k++) {
                let p = 100;
                const pts = [[0, p]];
                for (let t = 1; t <= jours; t++) {
                    p *= Math.exp((tendance - vol * vol / 2) / 252 + (vol / Math.sqrt(252)) * r.gauss());
                    pts.push([t / 252, p]);
                }
                finissentPlusBas += p < 100;
                series.push({ points: pts, couleur: `hsla(${30 + k * 26}, 45%, 70%, 0.75)` });
            }
            trace(canvas, series, { ligneZero: 100 });
            return [
                ['Trajectoires finissant sous leur point de départ', `${finissentPlusBas} sur 10`],
                ['À retenir', 'tendances, sommets et « supports » apparaissent ici sans aucune cause'],
            ];
        },
    },
    'series-pertes': {
        graphique: true,
        titre: 'Séries de pertes d\'une stratégie gagnante',
        relance: true,
        curseurs: [
            ['reussite', 'Taux de réussite', 0.25, 0.75, 0.01, 0.4, (v) => pct(v, 0)],
            ['ratio', 'Gain moyen (R)', 0.5, 4, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['trades', 'Nombre de trades', 50, 500, 10, 200, (v) => nf(v)],
        ],
        calcule: ({ reussite, ratio, trades }, { graine, canvas }) => {
            const r = aleatoire(graine);
            let cumul = 0;
            let serie = 0;
            let pire = 0;
            const pts = [[0, 0]];
            for (let t = 1; t <= trades; t++) {
                if (r.u() < reussite) {
                    cumul += ratio;
                    serie = 0;
                } else {
                    cumul -= 1;
                    serie += 1;
                    pire = Math.max(pire, serie);
                }
                pts.push([t, cumul]);
            }
            trace(canvas, [{ points: pts, couleur: '#E2CB91', epaisseur: 2 }], { ligneZero: 0, etiquette: (v) => `${nf(v)} R` });
            const esperance = reussite * ratio - (1 - reussite);
            return [
                ['Plus longue série de pertes de ce tirage', `${pire} d'affilée`],
                ['Résultat de ce tirage', `${cumul >= 0 ? '+' : ''}${nf(cumul, 1)} R`],
                ['Résultat attendu en moyenne', `${esperance >= 0 ? '+' : ''}${nf(esperance * trades, 0)} R`],
            ];
        },
    },
};

class Simulateur {
    constructor(bloc, type, def) {
        this.def = def;
        this.valeurs = {};
        this.graine = 1;
        bloc.replaceChildren();
        bloc.classList.add('is-live');

        const titre = document.createElement('p');
        titre.className = 'doc-sim__title';
        titre.textContent = def.titre;
        const grille = document.createElement('div');
        grille.className = 'doc-sim__controls';
        def.curseurs.forEach(([cle, libelle, min, max, pas, defaut, format]) => {
            const id = `sim-${type}-${cle}-${Math.random().toString(36).slice(2, 7)}`;
            const ligne = document.createElement('div');
            ligne.className = 'doc-sim__control';
            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = libelle;
            const sortie = document.createElement('output');
            sortie.htmlFor = id;
            const curseur = document.createElement('input');
            Object.assign(curseur, { type: 'range', id, min, max, step: pas, value: defaut });
            this.valeurs[cle] = defaut;
            const maj = () => {
                this.valeurs[cle] = Number(curseur.value);
                sortie.textContent = format(this.valeurs[cle]);
            };
            curseur.addEventListener('input', () => {
                maj();
                this.demande();
            });
            maj();
            ligne.append(label, sortie, curseur);
            grille.append(ligne);
        });
        this.resultats = document.createElement('dl');
        this.resultats.className = 'doc-sim__results';
        this.resultats.setAttribute('aria-live', 'polite');
        bloc.append(titre, grille, this.resultats);
        if (def.graphique) {
            this.canvas = document.createElement('canvas');
            this.canvas.className = 'doc-sim__chart';
            this.canvas.setAttribute('role', 'img');
            this.canvas.setAttribute('aria-label', `Graphique : ${def.titre}`);
            bloc.append(this.canvas);
            new ResizeObserver(() => this.demande()).observe(this.canvas);
        }
        if (def.legende) {
            const l = document.createElement('p');
            l.className = 'doc-sim__legend';
            l.textContent = def.legende;
            bloc.append(l);
        }
        if (def.relance) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'doc-pas__btn';
            b.textContent = 'Relancer le tirage';
            b.addEventListener('click', () => {
                this.graine += 1;
                this.demande();
            });
            bloc.append(b);
        }
        this.demande();
    }

    demande() {
        if (this.attente) {
            return;
        }
        this.attente = true;
        requestAnimationFrame(() => {
            this.attente = false;
            const lignes = this.def.calcule(this.valeurs, { graine: this.graine * 7919, canvas: this.canvas });
            this.resultats.replaceChildren(
                ...lignes.flatMap(([cle, valeur]) => {
                    const dt = document.createElement('dt');
                    dt.textContent = cle;
                    const dd = document.createElement('dd');
                    dd.textContent = valeur;
                    return [dt, dd];
                }),
            );
        });
    }
}

export function initSimulateurs(racine = document) {
    racine.querySelectorAll('.doc-sim:not(.is-live)').forEach((bloc) => {
        const type = [...bloc.classList].find((c) => c.startsWith('doc-sim--'))?.slice(9);
        if (type && SIMULATEURS[type] && typeof ResizeObserver === 'function') {
            new Simulateur(bloc, type, SIMULATEURS[type]);
        }
    });
}
