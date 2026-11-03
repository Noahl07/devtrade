#!/usr/bin/env python3
"""
Génère les données publiques des graphiques Shiro (page /trading) à partir du journal du testeur.

Usage : python tools/shiro_charts.py chemin/vers/tokyo2016.csv [--out public/data/shiro]

Reprend la méthode de tokyofix_quant.py (filtre/lisseur de Kalman, ruptures, Kelly, Monte Carlo
par blocs), réécrite en Python pur (aucune dépendance) pour être rejouée n'importe où.

Le CSV source n'est PAS publié (il contient les prix d'entrée et de sortie) : seuls des dates et des
valeurs agrégées en R sortent d'ici. Ne jamais ajouter d'horaire, de prix ou de paramètre du robot.
"""
import argparse
import csv
import json
import math
import os
import random

NOMINAL = {5, 10, 15, 20, 25, 30}


def load(path, risk0=0.005, bal0=10000.0):
    rows = list(csv.DictReader(open(path, encoding="latin-1"), delimiter=";"))
    trades = [r for r in rows if r["type"] == "GOTOBI"]
    trades.sort(key=lambda r: r["day"])
    out, bal = [], bal0
    for r in trades:
        money = float(r["money"])
        day = r["day"].replace(".", "-")
        out.append({"day": day, "R": money / (risk0 * bal), "nominal": int(day[-2:]) in NOMINAL})
        bal += money
    return out


def var(y):
    m = sum(y) / len(y)
    return sum((v - m) ** 2 for v in y) / len(y)


def kalman(y, s2, q):
    n = len(y)
    x, P = [0.0] * n, [0.0] * n
    xx, PP, ll = sum(y[:10]) / min(10, n), s2, 0.0
    for t in range(n):
        Pp = PP + q * s2
        F = Pp + s2
        K = Pp / F
        e = y[t] - xx
        ll += -0.5 * (math.log(2 * math.pi * F) + e * e / F)
        xx += K * e
        PP = Pp * (1 - K)
        x[t], P[t] = xx, PP
    return ll, x, P


def smooth(x, P, Q):
    xs, Ps = x[:], P[:]
    for t in range(len(x) - 2, -1, -1):
        Pn = P[t] + Q
        J = P[t] / Pn
        xs[t] = x[t] + J * (xs[t + 1] - x[t])
        Ps[t] = P[t] + J * J * (Ps[t + 1] - Pn)
    return xs, Ps


def fit_level(y):
    s2 = var(y)
    grid = [10 ** (-7 + 6 * i / 60) for i in range(61)]
    best = max(grid, key=lambda q: kalman(y, s2, q)[0])
    _, x, P = kalman(y, s2, best)
    xs, Ps = smooth(x, P, best * s2)
    return {"q": best, "s2": s2, "xf": x, "Pf": P, "xs": xs, "Ps": Ps}


def segments(y, lo=0, hi=None, min_seg=40, pen_mult=2.0, s2=None, out=None):
    if hi is None:
        hi, s2, out = len(y), var(y), []
    seg = y[lo:hi]
    if len(seg) < 2 * min_seg:
        return out

    def sse(a):
        m = sum(a) / len(a)
        return sum((v - m) ** 2 for v in a)

    total, best, kbest = sse(seg), 0.0, None
    for k in range(min_seg, len(seg) - min_seg):
        gain = total - sse(seg[:k]) - sse(seg[k:])
        if gain > best:
            best, kbest = gain, k
    if kbest is not None and best > pen_mult * s2 * math.log(len(y)):
        cut = lo + kbest
        out.append(cut)
        segments(y, lo, cut, min_seg, pen_mult, s2, out)
        segments(y, cut, hi, min_seg, pen_mult, s2, out)
    return out


def growth(R, mu, f):
    m = sum(R) / len(R)
    tot = 0.0
    for r in R:
        z = 1 + f * (r - m + mu)
        tot += math.log(z if z > 1e-9 else 1e-9)
    return tot / len(R)


def percentile(sorted_vals, p):
    k = (len(sorted_vals) - 1) * p / 100
    f, c = math.floor(k), math.ceil(k)
    return sorted_vals[f] if f == c else sorted_vals[f] + (sorted_vals[c] - sorted_vals[f]) * (k - f)


def monte_carlo(trades, mu_mean, mu_sd, fx, fsh, n_trades=65, paths=5000, block=5, seed=7):
    rng = random.Random(seed)
    R = [t["R"] for t in trades]
    nom = [t["nominal"] for t in trades]
    m = sum(R) / len(R)
    Rc = [r - m for r in R]
    n = len(R)
    curves = [[] for _ in range(n_trades)]
    finals = []
    for _ in range(paths):
        mu = rng.gauss(mu_mean, mu_sd)
        eq, k = 1.0, 0
        while k < n_trades:
            start = rng.randrange(n)
            for j in range(block):
                if k >= n_trades:
                    break
                i = (start + j) % n
                eq *= 1 + (fx if nom[i] else fsh) * (Rc[i] + mu)
                curves[k].append(eq)
                k += 1
        finals.append(eq)
    bands = []
    for k, vals in enumerate(curves):
        vals.sort()
        bands.append([k + 1] + [round((percentile(vals, p) - 1) * 100, 2) for p in (5, 25, 50, 75, 95)])
    finals.sort()
    return bands, finals


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("csv")
    ap.add_argument("--out", default="public/data/shiro")
    a = ap.parse_args()
    os.makedirs(a.out, exist_ok=True)

    trades = load(a.csv)
    y = [t["R"] for t in trades]
    n = len(y)
    fit = fit_level(y)
    cuts = sorted(segments(y))

    # 1. R cumulé + ruptures
    cum, total = [], 0.0
    for t in trades:
        total += t["R"]
        cum.append([t["day"], round(total, 2)])
    breaks = [trades[c]["day"] for c in cuts]

    # 2. Avantage lissé (Kalman) avec intervalle à 95 %
    edge = [[t["day"], round(fit["xs"][i], 4), round(fit["xs"][i] - 1.96 * math.sqrt(fit["Ps"][i]), 4),
             round(fit["xs"][i] + 1.96 * math.sqrt(fit["Ps"][i]), 4)] for i, t in enumerate(trades)]

    # 3. Monte Carlo 1 an, scénario récent, réglage 1,5 % / 0,5 %
    xT, PT = fit["xf"][-1], fit["Pf"][-1]
    mu_sd = math.sqrt(PT + fit["q"] * fit["s2"] * 65 / 3)
    bands, finals = monte_carlo(trades, xT, mu_sd, 0.015, 0.005)
    mu_all = sum(y) / n
    _, finals_prudent = monte_carlo(trades, mu_all, max(math.sqrt(var(y) / n), 0.03), 0.015, 0.005)

    # 4. Kelly (bloc « pour les curieux »)
    fs = [0.005 + i * (0.895 / 179) for i in range(180)]
    kelly = {
        "f": [round(f * 100, 2) for f in fs],
        "prudent": [round(growth(y, mu_all, f) * 100, 3) for f in fs],
        "recent": [round(growth(y, xT, f) * 100, 3) for f in fs],
        "degrade": [round(growth(y, 0.05, f) * 100, 3) for f in fs],
    }

    # 5. Résumé annuel (tableau accessible)
    years = {}
    for t in trades:
        yv = years.setdefault(t["day"][:4], {"n": 0, "sum": 0.0})
        yv["n"] += 1
        yv["sum"] += t["R"]
    annual = [{"year": k, "trades": v["n"], "sum_r": round(v["sum"], 1), "mean_r": round(v["sum"] / v["n"], 3)}
              for k, v in sorted(years.items())]

    first, last = trades[0]["day"], trades[-1]["day"]
    meta = {
        "source": "Journal du testeur de stratégie MetaTrader 5, USDJPY (backtest, pas de résultat réel)",
        "period": [first, last],
        "trades": n,
        "unit": "R : gain ou perte d'un trade en multiples du risque pris",
        "edge_now": round(xT, 3),
        "edge_now_sd": round(math.sqrt(PT), 3),
        "mean_r": round(mu_all, 3),
        "breaks": breaks,
    }

    dump = lambda name, data: json.dump(data, open(os.path.join(a.out, name), "w", encoding="utf-8"),
                                        ensure_ascii=False, separators=(",", ":"))
    dump("r-cumule.json", {"meta": meta, "points": cum})
    dump("avantage.json", {"meta": meta, "points": edge})
    dump("monte-carlo.json", {"meta": {**meta, "risk": "1,5 % dates exactes / 0,5 % dates décalées",
                                       "paths": 5000, "horizon_trades": 65,
                                       "median_recent": round((percentile(finals, 50) - 1) * 100, 1),
                                       "median_prudent": round((percentile(finals_prudent, 50) - 1) * 100, 1)},
                              "bands": bands})
    dump("kelly.json", {"meta": meta, **kelly})
    dump("resume.json", {"meta": meta, "annual": annual})

    print(f"{n} trades {first} -> {last} ; avantage actuel {xT:+.3f} R (sd {math.sqrt(PT):.3f}) ; moyenne {mu_all:+.3f} R")
    print("ruptures :", breaks)
    print("Monte Carlo 1 an médiane : récent %+.1f %% ; prudent %+.1f %%" % (
        (percentile(finals, 50) - 1) * 100, (percentile(finals_prudent, 50) - 1) * 100))
    print("par an :", [(r["year"], r["trades"], r["mean_r"]) for r in annual])


if __name__ == "__main__":
    main()
