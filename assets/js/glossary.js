// Glossaire en infobulles : la première occurrence de chaque terme technique d'un article devient
// une infobulle accessible (survol, focus clavier ou toucher). Le texte reste intact sans JavaScript.

const TERMES = [
    ['FVG', 'Fair Value Gap : déséquilibre laissé par une bougie impulsive, quand les mèches de la bougie précédente et de la suivante ne se chevauchent pas.', true],
    ['BOS', 'Break of Structure : le prix casse le sommet (ou le creux) précédent dans le sens de la tendance.', true],
    ['CHoCH', 'Change of Character : première cassure de structure dans le sens opposé à la tendance, premier signe de retournement.', true],
    ['order block', 'Dernière bougie de sens opposé avant un mouvement impulsif ; zone où l\'on attend une réaction du prix.'],
    ['kill zone', 'Plage horaire où ICT juge les mouvements les plus probables, autour des ouvertures de Londres et de New York.'],
    ['CVD', 'Cumulative Volume Delta : cumul, bougie après bougie, des achats agressifs moins les ventes agressives.', true],
    ['footprint', 'Graphique qui détaille dans chaque bougie le volume échangé à chaque prix, côté acheteurs agressifs et côté vendeurs agressifs.'],
    ['heatmap', 'Historique du carnet d\'ordres en couleurs : plus c\'est clair, plus il y avait d\'ordres limites en attente à ce prix.'],
    ['iceberg', 'Ordre limite dont seule une petite partie est affichée dans le carnet, et qui se recharge à mesure qu\'il est exécuté.'],
    ['spoofing', 'Placer de gros ordres sans intention de les exécuter pour tromper les autres participants. Interdit par les régulateurs.'],
    ['absorption', 'Des ordres agressifs sont encaissés par des ordres passifs plus forts : beaucoup de volume, mais le prix n\'avance pas.'],
    ['POC', 'Point of Control : le prix où le plus grand volume s\'est échangé sur la période.', true],
    ['VWAP', 'Prix moyen pondéré par les volumes depuis le début de la séance (ou depuis un point d\'ancrage).', true],
    ['teneur de marché', 'Participant qui affiche en permanence un prix d\'achat et un prix de vente, et se rémunère sur l\'écart entre les deux.'],
    ['spread', 'Écart entre le meilleur prix proposé à l\'achat et le meilleur prix proposé à la vente.'],
    ['glissement', 'Écart entre le prix attendu d\'un ordre et le prix auquel il est réellement exécuté.'],
    ['drawdown', 'Baisse du capital depuis son plus haut, en pourcentage, avant qu\'un nouveau plus haut soit atteint.'],
    ['effet de levier', 'Rapport entre la taille de la position et l\'argent réellement engagé : il amplifie les gains comme les pertes.'],
    ['espérance', 'Gain moyen attendu par trade, compte tenu du taux de réussite et du rapport entre gain moyen et perte moyenne.'],
    ['critère de Kelly', 'Fraction du capital à risquer qui maximise la croissance à long terme, à condition de connaître exactement ses probabilités.'],
    ['VaR', 'Value at Risk : perte qui ne devrait pas être dépassée, avec une probabilité donnée (par exemple 95 %), sur un horizon donné.', true],
    ['expected shortfall', 'Perte moyenne dans les cas où la VaR est dépassée : elle mesure la gravité des mauvais jours, pas seulement leur seuil.'],
    ['ratio de Sharpe', 'Rendement au-delà du taux sans risque, divisé par la volatilité : le rendement obtenu par unité de risque.'],
    ['volatilité implicite', 'Volatilité déduite du prix des options : ce que le marché anticipe comme amplitude des mouvements à venir.'],
    ['gamma', 'Vitesse à laquelle le delta d\'une option change quand le sous-jacent bouge.'],
    ['GEX', 'Gamma Exposure : estimation du gamma détenu par les teneurs de marché, qui détermine le sens de leurs couvertures.', true],
    ['backtest', 'Test d\'une stratégie sur des données passées, pour estimer comment elle se serait comportée.'],
    ['walk-forward', 'Méthode de test qui optimise une stratégie sur une période, la vérifie sur la suivante, puis recommence en décalant les fenêtres.'],
    ['surapprentissage', 'Stratégie trop ajustée au passé : elle a appris le bruit des données plutôt qu\'un avantage réel, et échoue ensuite.'],
    ['PFU', 'Prélèvement forfaitaire unique : 31,4 % en 2026 (12,8 % d\'impôt et 18,6 % de prélèvements sociaux).', true],
    ['CFD', 'Contrat sur différence : produit à effet de levier qui paie l\'écart de prix d\'un actif sans le détenir.', true],
    ['ETF', 'Fonds coté en bourse qui réplique un indice ; il s\'achète et se vend comme une action.', true],
    ['prop firm', 'Société qui finance des traders après une évaluation payante (challenge), et partage avec eux les gains réalisés.'],
    ['spring', 'Dans la méthode Wyckoff, passage sous le support d\'un range suivi d\'une réintégration rapide : un piège pour les vendeurs.'],
];

const EXCLUS = 'h1, h2, h3, h4, a, code, pre, button, figure, figcaption, .doc-sim, .doc-anim, .doc-quiz, .glossaire, .doc-label, summary';
const MAX = 24;

function motif([terme, , sigle]) {
    const echappe = terme.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/ /g, '\\s+');
    return new RegExp(`(?<![\\p{L}\\p{N}-])${echappe}s?(?![\\p{L}\\p{N}])`, sigle ? 'u' : 'iu');
}

export function initGlossaire(corps) {
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.glossaire')) {
            document.querySelectorAll('.glossaire.is-open').forEach((x) => x.classList.remove('is-open'));
        }
    });
    // Pas sur l'article-glossaire lui-même : chaque terme y est déjà défini.
    if (!corps || window.location.pathname.includes('glossaire')) {
        return;
    }
    const restants = TERMES.map((t) => ({ terme: t, re: motif(t) }));
    let poses = 0;
    let compteur = 0;
    const marcheur = document.createTreeWalker(corps, NodeFilter.SHOW_TEXT, {
        acceptNode: (n) => (n.nodeValue.trim().length < 3 || n.parentElement.closest(EXCLUS) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
    });
    const noeuds = [];
    while (marcheur.nextNode()) {
        noeuds.push(marcheur.currentNode);
    }
    for (const noeud of noeuds) {
        if (poses >= MAX || !restants.length) {
            break;
        }
        // Premier terme trouvé dans ce nœud (le plus tôt dans le texte).
        let meilleur = null;
        restants.forEach((r) => {
            const m = r.re.exec(noeud.nodeValue);
            if (m && (!meilleur || m.index < meilleur.m.index)) {
                meilleur = { r, m };
            }
        });
        if (!meilleur) {
            continue;
        }
        const { r, m } = meilleur;
        const apres = noeud.splitText(m.index);
        apres.splitText(m[0].length);
        compteur += 1;
        const id = `glossaire-${compteur}`;
        const mot = document.createElement('span');
        mot.className = 'glossaire';
        mot.tabIndex = 0;
        mot.setAttribute('aria-describedby', id);
        mot.textContent = apres.nodeValue;
        const bulle = document.createElement('span');
        bulle.className = 'glossaire__bulle';
        bulle.id = id;
        bulle.setAttribute('role', 'tooltip');
        bulle.textContent = r.terme[1];
        mot.append(bulle);
        // Garde la bulle dans l'écran : décalage horizontal calculé à l'affichage.
        const place = () => {
            bulle.style.setProperty('--decalage', '0px');
            const b = bulle.getBoundingClientRect();
            const marge = 12;
            const decalage = b.left < marge ? marge - b.left : b.right > window.innerWidth - marge ? window.innerWidth - marge - b.right : 0;
            bulle.style.setProperty('--decalage', `${Math.round(decalage)}px`);
        };
        mot.addEventListener('mouseenter', place);
        mot.addEventListener('focus', place);
        mot.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                mot.classList.remove('is-open');
                mot.blur();
            }
        });
        // Toucher : ouvre ou ferme la bulle (le survol n'existe pas sur mobile).
        mot.addEventListener('click', () => {
            const ouvrir = !mot.classList.contains('is-open');
            document.querySelectorAll('.glossaire.is-open').forEach((x) => x.classList.remove('is-open'));
            if (ouvrir) {
                mot.classList.add('is-open');
                place();
            }
        });
        apres.replaceWith(mot);
        restants.splice(restants.indexOf(r), 1);
        poses += 1;
        // Le reste du nœud peut contenir un autre terme : on le remet dans la file.
        if (mot.nextSibling?.nodeType === Node.TEXT_NODE) {
            noeuds.splice(noeuds.indexOf(noeud) + 1, 0, mot.nextSibling);
        }
    }
}
