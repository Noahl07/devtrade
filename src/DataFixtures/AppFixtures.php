<?php

namespace App\DataFixtures;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $this->loadDocumentation($manager);
        $manager->flush();
    }

    private function loadDocumentation(ObjectManager $manager): void
    {
        // ══════════════════════════════════════════════════════
        // NIVEAU 0 — DÉBUTANT ABSOLU (gratuit, sans inscription)
        // ══════════════════════════════════════════════════════

        $cat0 = $this->createCategory(
            manager: $manager,
            name: 'Débuter en trading',
            slug: 'debuter-en-trading',
            description: 'Les bases absolues pour comprendre les marchés financiers avant de trader.',
            icon: '🎓',
            position: 1,
            accessLevel: 'free'
        );

        $articles0 = [
            ['Qu\'est-ce que le trading ?', 'quest-ce-que-le-trading', 'free', 8,
             'Définition du trading, différence entre investissement et spéculation, les différents marchés (Forex, actions, indices, crypto, matières premières) et comment fonctionne l\'achat/vente d\'actifs financiers.'],
            ['Comprendre les marchés financiers', 'comprendre-les-marches-financiers', 'free', 10,
             'Structure des marchés, participants (retail, institutions, market makers), heures de trading, sessions mondiales (Asie, Londres, New York) et leur importance.'],
            ['Lire un graphique en chandeliers japonais', 'lire-graphique-chandeliers', 'free', 12,
             'Anatomie d\'une bougie, ouverture/clôture/haut/bas, bougies haussières et baissières, patterns de base (Doji, Hammer, Engulfing) et leur signification.'],
            ['Les types d\'ordres de trading', 'types-ordres-trading', 'free', 9,
             'Ordre au marché, ordre limite, ordre stop, stop-loss, take-profit — comprendre chaque type d\'ordre et quand les utiliser.'],
            ['Gestion du risque — les fondamentaux', 'gestion-risque-fondamentaux', 'free', 11,
             'Pourquoi 90% des traders perdent, le concept de risk/reward, ne jamais risquer plus de 1-2% par trade, calculer la taille de position. Les règles d\'or avant de placer un seul trade.'],
            ['Choisir son broker et sa plateforme', 'choisir-broker-plateforme', 'free', 7,
             'Critères de sélection d\'un broker fiable, différence entre CFD et marché réel, spreads et commissions, introduction à TradingView, MetaTrader et Bookmap.'],
            ['Psychologie du trader débutant', 'psychologie-trader-debutant', 'free', 10,
             'Les pièges cognitifs du débutant, FOMO, revenge trading, surtrading — comprendre pourquoi la psychologie est plus importante que la stratégie.'],
        ];

        foreach ($articles0 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat0, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 1 — ANALYSE TECHNIQUE CLASSIQUE (gratuit, inscription requise)
        // ══════════════════════════════════════════════════════

        $cat1 = $this->createCategory(
            manager: $manager,
            name: 'Analyse technique classique',
            slug: 'analyse-technique-classique',
            description: 'Les outils fondamentaux de l\'analyse technique — le socle de toute stratégie de trading.',
            icon: '📈',
            position: 2,
            accessLevel: 'free'
        );

        $articles1 = [
            ['Tendances, supports et résistances', 'tendances-supports-resistances', 'free', 13,
             'Définir une tendance haussière, baissière et latérale. Identifier les niveaux clés de support et résistance. Comment les zones deviennent-elles support puis résistance ? Zones vs lignes.'],
            ['Moyennes mobiles — MA, EMA, VWAP', 'moyennes-mobiles-ma-ema-vwap', 'free', 12,
             'Simple vs exponentielle, les croissements (Golden Cross / Death Cross), VWAP comme outil institutionnel, utilisation pratique en trend-following et mean-reversion.'],
            ['RSI, MACD et oscillateurs', 'rsi-macd-oscillateurs', 'free', 11,
             'Lecture du RSI (surachat/survente), divergences RSI, MACD et ses signaux, Stochastique — utilisation correcte des oscillateurs sans les sur-trader.'],
            ['Volumes — la clé ignorée', 'volumes-cle-ignoree', 'free', 10,
             'Volume comme confirmation de mouvement, accumulation vs distribution visible par le volume, volume climax, analyse volume/prix de base.'],
            ['Figures chartistes classiques', 'figures-chartistes-classiques', 'free', 14,
             'Tête-épaules, double top/bottom, triangles (ascendant, descendant, symétrique), drapeaux, fanions, canaux — identification et trading de chaque figure.'],
            ['Retracements de Fibonacci', 'retracements-fibonacci', 'free', 11,
             'Les niveaux 23.6%, 38.2%, 50%, 61.8%, 78.6% et leur logique mathématique. Comment identifier le bon swing pour tracer les retracements. Extensions Fibonacci pour les objectifs.'],
            ['Multi-timeframe analysis — bases', 'multi-timeframe-bases', 'free', 12,
             'Utiliser plusieurs unités de temps pour trader — le timeframe supérieur donne le biais, l\'inférieur donne l\'entrée. Éviter les conflits de structure.'],
        ];

        foreach ($articles1 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat1, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 2 — ICT / SMART MONEY CONCEPTS (gratuit, inscription requise)
        // ══════════════════════════════════════════════════════

        $cat2 = $this->createCategory(
            manager: $manager,
            name: 'ICT — Inner Circle Trader',
            slug: 'ict-inner-circle-trader',
            description: 'La méthodologie ICT complète — de l\'introduction aux concepts avancés de Smart Money.',
            icon: '📐',
            position: 3,
            accessLevel: 'free'
        );

        $articles2 = [
            ['Introduction à ICT et la théorie Smart Money', 'introduction-ict-smart-money', 'free', 8,
             'Qui est Michael Huddleston (ICT), la philosophie Smart Money Concept, pourquoi le retail perd contre les institutions, et comment l\'ICT propose de trader avec les gros acteurs plutôt que contre eux.'],
            ['Structure de marché — HH, HL, LH, LL', 'structure-de-marche-hh-hl', 'free', 13,
             'Higher Highs / Higher Lows pour une tendance haussière, Lower Highs / Lower Lows pour une baissière. Identifier la structure sur plusieurs timeframes. Importance du contexte.'],
            ['Break of Structure (BOS) et Change of Character (CHoCH)', 'bos-choch', 'free', 14,
             'BOS = continuation de tendance. CHoCH = signal de retournement. Comment identifier le swing valide, la différence entre wick et clôture, et comment utiliser BOS/CHoCH pour définir le biais.'],
            ['Order Blocks — zones institutionnelles', 'order-blocks', 'free', 15,
             'Définition et identification d\'un Order Block bullish et bearish. Validation par le sweep de liquidité et le FVG. Mitigation et invalidation. OB vs simple S/R. Breaker Block (OB retourné).'],
            ['Fair Value Gaps — imbalances de prix', 'fair-value-gaps', 'free', 14,
             'Les 3 bougies qui forment un FVG, bullish vs bearish FVG. Consequent Encroachment (50% du FVG). Inversion FVG (IFVG). Comment le marché revient combler les inefficiences.'],
            ['Liquidité — la ressource que chassent les institutions', 'liquidite-chasse-institutions', 'free', 13,
             'Buy-side et sell-side liquidity, equal highs/lows comme pièges, stop hunts et liquidity sweeps. Judas Swing. Comment anticiper les chasses de stops pour entrer dans la direction réelle.'],
            ['Kill Zones — les fenêtres de trading ICT', 'kill-zones', 'free', 9,
             'Asian Range (définition du range nocturne), London Kill Zone (02h-05h EST), New York Kill Zone (07h-10h EST), London Close (10h-12h). Pourquoi ces fenêtres concentrent le volume institutionnel.'],
            ['Premium & Discount — zones d\'intérêt optimal', 'premium-discount', 'free', 10,
             'Equilibrium (50% d\'un range), zones premium (au-dessus du 50% = vendre), zones discount (en-dessous = acheter). Appliquer ce concept à la définition des entrées ICT.'],
        ];

        foreach ($articles2 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat2, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 3 — ICT AVANCÉ (premium)
        // ══════════════════════════════════════════════════════

        $cat3 = $this->createCategory(
            manager: $manager,
            name: 'ICT Avancé — Concepts Experts',
            slug: 'ict-avance-concepts-experts',
            description: 'Les concepts ICT de niveau expert — PO3, Silver Bullet, CISD, Unicorn Model et plus.',
            icon: '🎯',
            position: 4,
            accessLevel: 'premium'
        );

        $articles3 = [
            ['Power of Three (PO3) — Accumulation, Manipulation, Distribution', 'power-of-three-po3', 'premium', 16,
             'Le modèle en 3 phases que suivent les institutions chaque session : accumulation silencieuse, manipulation (Judas Swing) pour piéger le retail, puis distribution dans la vraie direction.'],
            ['CISD — Change in State of Delivery', 'cisd-change-state-delivery', 'premium', 14,
             'Identifier le changement d\'état de livraison de prix au niveau des bougies. Signal de retournement sur timeframe inférieur. Comment utiliser le CISD pour des entrées précises.'],
            ['Silver Bullet Strategy — 10h-11h et 14h-15h EST', 'silver-bullet-strategy', 'premium', 15,
             'Setup ICT basé sur des fenêtres horaires spécifiques. Identification du FVG matinal, retrace pendant la Silver Bullet, entrée en direction de la session. Backtesting sur indices.'],
            ['Inversion Fair Value Gap (IFVG)', 'inversion-fair-value-gap', 'premium', 13,
             'Quand un FVG bullish est comblé, il devient résistance (IFVG bearish) et vice versa. Comment identifier et trader les IFVG avec confluence liquidity sweep.'],
            ['Breaker Blocks et Mitigation Blocks', 'breaker-blocks-mitigation', 'premium', 14,
             'Breaker = OB qui a été cassé et retourné. Mitigation Block = OB partiellement mitigé qui reste valide. Différences pratiques et comment les trader.'],
            ['Consequent Encroachment et Equilibrium', 'consequent-encroachment', 'premium', 12,
             'Le 50% d\'un FVG ou d\'un OB comme zone de réaction optimale. Utilisation du niveau CE comme entrée précise avec stop plus serré.'],
            ['Optimal Trade Entry (OTE) — entrée précise Fibonacci ICT', 'optimal-trade-entry-ote', 'premium', 13,
             'Le retracement 61.8%-79% dans un FVG ou OB = zone OTE. Combinaison Fibonacci + structure + FVG pour des entrées à haute précision avec risque minimal.'],
            ['ICT Unicorn Model — setup complet', 'ict-unicorn-model', 'premium', 16,
             'Le modèle Unicorn complet : structure de marché, liquidity sweep, FVG + OB confluence, entrée OTE. Un des setups ICT les plus rigoureux et les mieux documentés.'],
            ['ICT Monthly/Weekly/Daily Profiles', 'ict-profiles-mwdd', 'premium', 17,
             'Les profils de prix mensuels, hebdomadaires et journaliers. Comment le prix interagit avec les Previous High/Low de chaque timeframe. Expansion et contraction.'],
            ['Dealing Ranges et Nested Ranges', 'dealing-ranges-nested', 'premium', 15,
             'Identifier les ranges actifs sur chaque timeframe, premium/discount dans un range, nested ranges (ranges dans des ranges). Définir avec précision les zones d\'intérêt.'],
        ];

        foreach ($articles3 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat3, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 4 — PRICE ACTION & WYCKOFF (gratuit, inscription requise)
        // ══════════════════════════════════════════════════════

        $cat4 = $this->createCategory(
            manager: $manager,
            name: 'Price Action & Wyckoff',
            slug: 'price-action-wyckoff',
            description: 'La méthode Wyckoff et la lecture avancée du price action — 100 ans de market wisdom.',
            icon: '🔬',
            position: 5,
            accessLevel: 'free'
        );

        $articles4 = [
            ['Introduction à la méthode Wyckoff', 'introduction-methode-wyckoff', 'free', 12,
             'Richard Wyckoff et sa vision des marchés, les 3 lois fondamentales (offre/demande, cause/effet, effort/résultat), le concept de Composite Man (l\'institution personnifiée).'],
            ['Accumulation Wyckoff — Phases A à E', 'accumulation-wyckoff-phases', 'free', 18,
             'Analyse détaillée des 5 phases d\'accumulation : Phase A (arrêt de la baisse), B (construction de cause), C (Spring/Shakeout), D (signal d\'achat), E (markup). Schémas de Wyckoff.'],
            ['Distribution Wyckoff — Phases A à E', 'distribution-wyckoff-phases', 'free', 17,
             'Les 5 phases de distribution Wyckoff avec les événements clés : PSY, BC, AR, ST, UTAD, SOW. Comment identifier quand les institutions vendent leurs positions.'],
            ['Spring et Upthrust — les pièges de Wyckoff', 'spring-upthrust-wyckoff', 'free', 14,
             'Spring (faux breakout baissier en fin d\'accumulation) et Upthrust (faux breakout haussier en fin de distribution) — les mouvements les plus importants de la méthode Wyckoff.'],
            ['Volume Spread Analysis (VSA) — bases', 'volume-spread-analysis-bases', 'free', 13,
             'Analyse barre par barre du volume et du spread (range de la bougie). Stopping Volume, No Supply, No Demand, Effort vs Result. Les signaux VSA les plus fiables.'],
            ['Re-accumulation et Re-distribution', 'reaccumulation-redistribution', 'free', 12,
             'Les structures de re-accumulation (consolidation dans une tendance haussière) et re-distribution (consolidation dans une tendance baissière). Différences avec les tops/bottoms.'],
        ];

        foreach ($articles4 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat4, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 5 — ORDERFLOW & FOOTPRINT (premium)
        // ══════════════════════════════════════════════════════

        $cat5 = $this->createCategory(
            manager: $manager,
            name: 'Orderflow & Footprint',
            slug: 'orderflow-footprint',
            description: 'Lecture du carnet d\'ordres, delta, footprint charts — le trading à partir des flux d\'ordres bruts.',
            icon: '📊',
            position: 6,
            accessLevel: 'premium'
        );

        $articles5 = [
            ['Introduction à l\'Orderflow', 'introduction-orderflow', 'premium', 11,
             'Différence entre analyse graphique et orderflow. Qu\'est-ce qu\'un flux d\'ordres ? Limit orders vs market orders, bid/ask, le carnet d\'ordres (DOM) et comment les institutions cachent leurs ordres.'],
            ['Delta et Delta Cumulé (CVD)', 'delta-delta-cumule-cvd', 'premium', 14,
             'Delta = acheteurs agressifs - vendeurs agressifs. CVD = somme cumulée du delta. Divergences CVD/prix pour anticiper les retournements. Absorption et delta négatif sur hausse.'],
            ['Footprint Charts — lecture avancée', 'footprint-charts-lecture', 'premium', 16,
             'Anatomie d\'un footprint chart (bid/ask par niveau de prix), Imbalances (≥3:1 ratio bid/ask), Stacked Imbalances, Point of Control (POC), High Volume Nodes (HVN) et Low Volume Nodes (LVN).'],
            ['Absorption et épuisement par orderflow', 'absorption-epuisement-orderflow', 'premium', 15,
             'Detecting absorption : gros volume sans mouvement de prix. Climax d\'achat/vente. Comment lire l\'épuisement de tendance sur le footprint avant le retournement.'],
            ['DOM (Depth of Market) — le carnet d\'ordres', 'dom-depth-of-market', 'premium', 13,
             'Lire le DOM en temps réel, identifier les gros blocs d\'ordres, iceberg orders, spoofing, layering. Comment les institutions dissimulent leurs intentions dans le carnet.'],
            ['Volume Profile — POC, VA, VAH, VAL', 'volume-profile-poc-va', 'premium', 15,
             'Construction d\'un Volume Profile, Point of Control (POC), Value Area High/Low (70% du volume). Profils de session, hebdomadaires et mensuels. Zones d\'intérêt pour les entrées.'],
            ['Market Profile et TPO Charts', 'market-profile-tpo', 'premium', 14,
             'TPO (Time Price Opportunity), Initial Balance, Value Area, Single Prints. Comment utiliser le Market Profile pour comprendre la structure journalière et anticiper les extensions.'],
            ['VWAP et VWAP Bands avancés', 'vwap-bands-avances', 'premium', 13,
             'VWAP institutionnel, VWAP anchored, bandes standard de déviation (1σ, 2σ, 3σ). Confluence VWAP + structure ICT. VWAP comme proxy institutionnel pour la fair value.'],
        ];

        foreach ($articles5 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat5, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 6 — QUANTITATIF & STATISTIQUES (premium)
        // ══════════════════════════════════════════════════════

        $cat6 = $this->createCategory(
            manager: $manager,
            name: 'Quantitatif & Statistiques',
            slug: 'quantitatif-statistiques',
            description: 'STDV, distributions, backtesting rigoureux, Monte Carlo — le trading mathématique.',
            icon: '📉',
            position: 7,
            accessLevel: 'premium'
        );

        $articles6 = [
            ['STDV — déviations standards sur les marchés', 'stdv-deviations-standards', 'premium', 15,
             'La déviation standard comme outil de probabilité. STDV journalière pour définir des objectifs de prix. Average True Range (ATR) vs STDV. Comment calculer des cibles probabilistes.'],
            ['Distributions statistiques des rendements', 'distributions-statistiques-rendements', 'premium', 14,
             'Distribution normale vs distribution en queues épaisses (fat tails). Kurtosis et skewness dans les marchés financiers. Pourquoi les modèles gaussiens sous-estiment le risque réel.'],
            ['Backtesting rigoureux — méthodologie complète', 'backtesting-rigoureux-methodologie', 'premium', 18,
             'In-sample vs out-of-sample, walk-forward analysis, overfitting et curve-fitting, statistical significance (minimum 30 trades, idéalement 200+), metrics clés (Sharpe, Sortino, Max DD, Calmar).'],
            ['Monte Carlo — robustesse d\'une stratégie', 'monte-carlo-robustesse', 'premium', 16,
             'Simulation Monte Carlo pour tester la robustesse d\'une stratégie. Variabilité du drawdown selon les runs, probabilité de ruine, expected value. Construire des intervalles de confiance.'],
            ['Kelly Criterion et sizing optimal', 'kelly-criterion-sizing', 'premium', 13,
             'La formule de Kelly pour le sizing optimal, Kelly fractionnel (25% ou 50% Kelly), risk of ruin selon le sizing. Comparaison fixed fractional vs Kelly vs fixed lot.'],
            ['Corrélation et diversification', 'correlation-diversification', 'premium', 12,
             'Matrices de corrélation entre actifs, éviter la fausse diversification (EUR/USD + GBP/USD trop corrélés), construction d\'un portefeuille de stratégies décorrélées.'],
            ['Expected Value (EV) et edge statistique', 'expected-value-edge-statistique', 'premium', 11,
             'Calculer l\'espérance mathématique d\'une stratégie, minimum viable win rate selon le R:R, différence entre EV positive et winning system. Tester statistiquement si votre edge est réel.'],
            ['Ratio de Sharpe, Sortino et autres métriques', 'ratio-sharpe-sortino-metriques', 'premium', 13,
             'Comprendre et calculer le Sharpe Ratio, le Sortino (pénalise uniquement les baisses), le Calmar (rendement/max DD), Profit Factor, Recovery Factor. Lire un rapport de backtest complet.'],
        ];

        foreach ($articles6 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat6, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 7 — GESTION DU RISQUE AVANCÉE (premium)
        // ══════════════════════════════════════════════════════

        $cat7 = $this->createCategory(
            manager: $manager,
            name: 'Gestion du risque avancée',
            slug: 'gestion-risque-avancee',
            description: 'Risk management professionnel — la discipline qui sépare les traders rentables des autres.',
            icon: '🛡️',
            position: 8,
            accessLevel: 'premium'
        );

        $articles7 = [
            ['Position sizing — calculer la taille exacte', 'position-sizing-calcul', 'premium', 12,
             'Formule complète de calcul de position : (Capital × %Risk) / (Distance SL en pips × Valeur du pip). Application sur Forex, indices, crypto. Erreurs communes de sizing.'],
            ['Stop Loss — placement professionnel', 'stop-loss-placement-professionnel', 'premium', 13,
             'Stop loss structurel vs ATR-based vs fixed. Placer le SL derrière un OB, sous un FVG, au-delà d\'un swing. Éviter les SL ronds et les SL trop larges ou trop serrés.'],
            ['Risk/Reward — maximiser l\'espérance', 'risk-reward-maximiser-esperance', 'premium', 11,
             'Pourquoi un win rate de 40% peut être profitable avec un R:R 1:3. Construire un journal de trading avec suivi R:R. Adjusted R:R selon le marché et la stratégie.'],
            ['Gestion de la position ouverte', 'gestion-position-ouverte', 'premium', 14,
             'Trailing stop, scale-out (fermer 50% au 1:1 puis laisser courir), breakeven move, pyramiding (ajouter en position gagnante). Avantages et inconvénients de chaque approche.'],
            ['Drawdown — comprendre et survivre', 'drawdown-comprendre-survivre', 'premium', 13,
             'Max drawdown absolu vs relatif, drawdown duration, psychological drawdown. Comment gérer un drawdown sans over-trade ou changer de stratégie. Critères de circuit-breaker.'],
            ['Journal de trading — système complet', 'journal-trading-systeme', 'premium', 14,
             'Construire un journal de trading efficace : capture du trade (setup, entry, SL, TP), analyse post-trade, tags de setup, suivi des métriques mensuelles. Templates et outils.'],
        ];

        foreach ($articles7 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat7, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 8 — PSYCHOLOGIE & MINDSET (gratuit, inscription requise)
        // ══════════════════════════════════════════════════════

        $cat8 = $this->createCategory(
            manager: $manager,
            name: 'Psychologie & Mindset',
            slug: 'psychologie-mindset',
            description: 'La dimension psychologique du trading — la partie la plus négligée et la plus importante.',
            icon: '🧠',
            position: 9,
            accessLevel: 'free'
        );

        $articles8 = [
            ['Les biais cognitifs du trader', 'biais-cognitifs-trader', 'free', 12,
             'FOMO, confirmation bias, anchoring, loss aversion, recency bias, gambler\'s fallacy — les principaux pièges mentaux et comment les identifier dans votre propre trading.'],
            ['Revenge trading et overtrading', 'revenge-trading-overtrading', 'free', 11,
             'Pourquoi le revenge trading est destructeur, comment reconnaître ses signes avant-coureurs, systèmes de règles pour s\'arrêter. L\'overtrading et la notion de "trop de trades".'],
            ['Construire sa discipline de trading', 'construire-discipline-trading', 'free', 13,
             'Routine pré-marché, plan de trading journalier, règles non-négociables, systèmes de accountability. Comment passer d\'un trading émotionnel à un trading processus.'],
            ['Gérer les pertes et les séries perdantes', 'gerer-pertes-series-perdantes', 'free', 11,
             'Les pertes font partie du trading — accepter l\'incertitude. Gérer une série de 10 pertes consécutives sans déraper. Différence entre loss normale et signal de problème de stratégie.'],
        ];

        foreach ($articles8 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat8, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 9 — TRADING ALGORITHMIQUE & AUTOMATISATION (premium)
        // ══════════════════════════════════════════════════════

        $cat9 = $this->createCategory(
            manager: $manager,
            name: 'Trading Algorithmique',
            slug: 'trading-algorithmique',
            description: 'Automatiser ses stratégies — de PineScript à Python en passant par les algo de trading.',
            icon: '⚙️',
            position: 10,
            accessLevel: 'premium'
        );

        $articles9 = [
            ['Introduction au trading algorithmique', 'introduction-trading-algorithmique', 'premium', 12,
             'Trading discrétionnaire vs algorithmique, avantages et limites des algos, types d\'algos (trend-following, mean-reversion, market-making, arbitrage), infrastructure nécessaire.'],
            ['PineScript — coder ses indicateurs TradingView', 'pinescript-indicateurs-tradingview', 'premium', 16,
             'Bases du PineScript v5, créer un indicateur custom, backtesting natif TradingView, coder un Order Block indicator, FVG detector, BOS/CHoCH alert. Code source commenté.'],
            ['Python pour le trading — bases', 'python-trading-bases', 'premium', 18,
             'Pandas, NumPy pour l\'analyse de données de marché. Télécharger des données (yfinance, CCXT). Calculer des indicateurs custom. Visualiser avec Matplotlib/Plotly.'],
            ['Backtesting en Python — backtrader et vectorbt', 'backtesting-python-vectorbt', 'premium', 17,
             'Construire un backtester propre avec Vectorbt (vectorisé, ultra-rapide) ou Backtrader (orienté objet). Éviter les look-ahead biais. Calculer toutes les métriques.'],
            ['Connexion broker et exécution automatique', 'connexion-broker-execution', 'premium', 15,
             'API Interactive Brokers (IBKR), Binance API, OANDA API. Passer des ordres automatiquement depuis Python. Gestion des erreurs et reconnexions. Infrastructure VPS.'],
            ['Stratégie ICT en Python — implémentation complète', 'strategie-ict-python', 'premium', 20,
             'Implémenter en Python : détection de BOS/CHoCH, identification d\'Order Blocks, Fair Value Gaps, kill zones. Backtesting complet sur 3 ans de données tick. Code complet fourni.'],
        ];

        foreach ($articles9 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat9, $title, $slug, $access, $time, $content);
        }

        // ══════════════════════════════════════════════════════
        // NIVEAU 10 — STRATÉGIES & SETUPS COMPLETS (premium)
        // ══════════════════════════════════════════════════════

        $cat10 = $this->createCategory(
            manager: $manager,
            name: 'Stratégies & Setups complets',
            slug: 'strategies-setups-complets',
            description: 'Des stratégies de trading complètes et backtestées — de l\'entrée à la sortie.',
            icon: '🗺️',
            position: 11,
            accessLevel: 'premium'
        );

        $articles10 = [
            ['Setup ICT Unicorn — guide complet', 'setup-ict-unicorn-guide', 'premium', 20,
             'Setup complet Unicorn : biais HTF → structure LTF → liquidity sweep → BOS/CHoCH → OB+FVG confluence → OTE entry → SL sous OB → TP au prochain liquidity level. 10 exemples réels.'],
            ['Setup Silver Bullet — 10h-11h EST', 'setup-silver-bullet-complet', 'premium', 18,
             'Silver Bullet complet avec règles précises, filtres de validité, gestion de position et journal de 50 trades commentés. Taux de réussite observé et conditions de validité.'],
            ['Trading les indices — NAS100, SP500', 'trading-indices-nas100-sp500', 'premium', 16,
             'Spécificités des indices US, horaires, gaps overnight, corrélation avec les données macro (NFP, CPI, FOMC), strategies ICT adaptées aux indices. Sizing en points.'],
            ['Trading le Forex — EUR/USD, GBP/USD', 'trading-forex-eurusd-gbpusd', 'premium', 15,
             'Spécificités du Forex, sessions et corrélations de paires, ICT sur le Forex (DXY comme biais), gestion du swap overnight, exemples complets sur EUR/USD.'],
            ['Trading la crypto — Bitcoin et altcoins', 'trading-crypto-bitcoin', 'premium', 14,
             'Marchés crypto 24/7 et leurs implications, manipulation spécifique au crypto (whale manipulation), ICT sur BTC, corrélation altcoins/BTC, liquidation cascades.'],
            ['Swing trading vs day trading — quelle approche ?', 'swing-trading-vs-day-trading', 'premium', 13,
             'Comparaison rigoureuse : capital requis, temps disponible, psychologie, frais, performance. Comment choisir selon son profil. Stratégies ICT adaptées au swing.'],
        ];

        foreach ($articles10 as [$title, $slug, $access, $time, $content]) {
            $this->createDocument($manager, $cat10, $title, $slug, $access, $time, $content);
        }
    }

    private function createCategory(
        ObjectManager $manager,
        string $name,
        string $slug,
        string $description,
        string $icon,
        int $position,
        string $accessLevel
    ): DocumentCategory {
        $cat = new DocumentCategory();
        $cat->setName($name);
        $cat->setSlug($slug);
        $cat->setDescription($description);
        $cat->setIcon($icon);
        $cat->setPosition($position);
        $cat->setAccessLevel($accessLevel);
        $manager->persist($cat);
        return $cat;
    }

    private function createDocument(
        ObjectManager $manager,
        DocumentCategory $category,
        string $title,
        string $slug,
        string $accessLevel,
        int $readingTime,
        string $excerpt
    ): void {
        $doc = new Document();
        $doc->setTitle($title);
        $doc->setSlug($slug);
        $doc->setAccessLevel($accessLevel);
        $doc->setReadingTime($readingTime);
        $doc->setExcerpt($excerpt);
        $doc->setContent('<p>' . $excerpt . '</p><p><em>Contenu complet à rédiger.</em></p>');
        $doc->setIsPublished(true);
        $doc->setPublishedAt(new \DateTime());
        $doc->setCreatedAt(new \DateTimeImmutable());
        $doc->setCategory($category);
        $manager->persist($doc);
    }
}