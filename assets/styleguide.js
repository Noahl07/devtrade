// Styleguide (dev uniquement) : contrastes WCAG calculés en direct sur les tokens.
import './styles/ds.css';

// Paires texte/fond à contrôler : [libellé, variable texte, variable fond, seuil]
const PAIRS = [
    ['Texte principal / fond', '--text', '--bg', 4.5],
    ['Texte principal / carte', '--text', '--surface', 4.5],
    ['Texte secondaire / fond', '--text-2', '--bg', 4.5],
    ['Texte secondaire / carte', '--text-2', '--surface', 4.5],
    ['Or / fond', '--accent', '--bg', 4.5],
    ['Or clair / carte', '--accent-strong', '--surface', 4.5],
    ['Texte sur bouton or', '--on-accent', '--accent', 4.5],
    ['Positif / carte', '--positive', '--surface', 4.5],
    ['Négatif / carte', '--negative', '--surface', 4.5],
    ['Émeraude / carte (grand texte, graphique)', '--brand', '--surface', 3],
    ['Bordure de champ / carte', '--border-strong', '--surface', 3],
    ['Bordure décorative / fond (non soumise)', '--border', '--bg', 1],
];

function luminance(rgb) {
    const [r, g, b] = rgb.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a, b) {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

// Résout une variable (qui peut pointer vers une autre) en couleur hexadécimale.
function resolve(name) {
    const probe = document.createElement('span');
    probe.style.color = `var(${name})`;
    document.body.appendChild(probe);
    const rgb = getComputedStyle(probe).color.match(/\d+/g).slice(0, 3).map(Number);
    probe.remove();
    return rgb.map((c) => c / 255);
}

function renderContrast() {
    const tbody = document.querySelector('[data-contrast-rows]');
    if (!tbody) {
        return;
    }
    tbody.replaceChildren(
        ...PAIRS.map(([label, fg, bg, min]) => {
            const value = ratio(resolve(fg), resolve(bg));
            const ok = value >= min;
            const tr = document.createElement('tr');
            const cells = [
                label,
                `${fg} / ${bg}`,
                value.toFixed(2) + ':1',
                min === 1 ? 'décoratif' : `${min}:1`,
                min === 1 ? '—' : ok ? 'Conforme' : 'Non conforme',
            ];
            cells.forEach((text, i) => {
                const td = document.createElement('td');
                td.textContent = text;
                if (i === 2 || i === 3) {
                    td.className = 'num';
                }
                if (i === 4 && min !== 1) {
                    td.className = ok ? 'is-positive' : 'is-negative';
                }
                tr.appendChild(td);
            });
            return tr;
        }),
    );
}


renderContrast();
