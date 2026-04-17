# evalu-pro · Checkpoint
**Stand:** 22. März 2026 | **Version:** v4.5 (aktiv)

---

## 🚨 PFLICHTLEKTÜRE FÜR JEDEN NEUEN CHAT

Lies diesen Abschnitt **bevor** du irgendetwas am Code änderst. Die hier beschriebenen Fehler wurden an einem einzigen Tag mehrfach wiederholt und haben jeweils Stunden gekostet.

### Die drei häufigsten Fehler die Claude macht — und wie du sie vermeidest:

**Fehler 1: Parallele PHP-Requests**
Claude neigt dazu, Fetches mit `Promise.all()` oder als nicht-awaitete Hintergrund-Promises zu starten. Auf diesem Server (cPanel/Host-Europe, file-based PHP Sessions) führt das zu 401-Fehlern bei allen nachfolgenden Calls. **Immer strikt sequenziell mit `await`.**

**Fehler 2: JS-Syntaxfehler durch Python-String-Replacement**
Wenn Claude Python-Skripte zum Patchen von HTML-Dateien verwendet und mehrzeilige JS-Strings erzeugt, können echte Newlines in String-Literale eingebaut werden → fataler JS-Syntaxfehler → App komplett tot (kein Button funktioniert). **Nach jedem Patch zwingend `node --check` ausführen.**

**Fehler 3: credentials: 'same-origin' vergessen**
Bei neuen `fetch()`-Calls zu PHP-Dateien fehlt regelmäßig `credentials: 'same-origin'`. Ohne diese Option werden Session-Cookies nicht mitgesendet → 401. **Jeden neuen PHP-Fetch-Call sofort auf credentials prüfen.**

---

## ℹ️ Session-Verhalten nach Datei-Upload

**Symptom:** Nach dem Hochladen einer neuen `index.html` zeigt der Verbindungstest "Nicht autorisiert" für DataForSEO, GSC und PageSpeed.

**Ursache:** Die Keys gehen dabei NICHT verloren. Es ist ausschließlich eine PHP-Session-Instabilität (cPanel/Host-Europe, file-based Session-Locking) nach dem Upload.

**Fix:** Einmal **ausloggen → neu einloggen** → Verbindungstest grün. Fertig.

---

## Projekt
KI-gestütztes SEO-Analyse-Tool auf Basis der Google SQEG (Nov. 2025). Geschlossene Web-App auf cPanel-Server (Host-Europe), Domain: evalupro.de.

- **Stack:** PHP 8.3, Vanilla JS, HTML/CSS (kein Framework)
- **KI:** Claude Sonnet via `api.php` Proxy
- **Datenquellen:** Google Search Console (Service Account), DataForSEO API

---

## Dateistruktur
```
evalupro/
├── index.html        ← Landing Page
├── login.php         ← Session-Login (8h) — ini_set cookie_path MUSS vorhanden sein
├── auth.php          ← Session-Check — ini_set cookie_path MUSS vorhanden sein
├── robots.txt / .htaccess
└── tool/
    ├── index.html    ← Haupt-App v4.5 (aktiv)
    ├── pagespeed.php ← PageSpeed Insights Proxy (Session-Prüfung)
    ├── api.php       ← Anthropic Proxy (Key hardcoded, KEINE Session-Prüfung)
    ├── fetch.php     ← HTML-Fetch Proxy (KEINE Session-Prüfung)
    ├── archive.php   ← Archiv (sqeg_archive.json, max. 12 Einträge, KEINE Session-Prüfung)
    ├── settings.php  ← Einstellungen (Session-Prüfung, schreibt settings.json)
    ├── dataforseo.php← DataForSEO Proxy (Session-Prüfung)
    ├── gsc.php       ← GSC Proxy (Session-Prüfung, Service Account, JWT via PHP/OpenSSL)
    ├── gsc_domains.json / settings.json / sqeg_archive.json
    └── .htaccess
```

---

## Design-System
| Token | Wert |
|-------|------|
| Hintergrund | `#f8f7f5` |
| Akzent | `#4338ca` / Hover `#3730a3` |
| Text | `#1a1917` / Sekundär `#4a4845` |
| Border | `#e3e2df` |
| Fonts | Bricolage Grotesque (Headlines) · DM Sans (Text) · DM Mono (Code) |

---

## Tool-Suite (5 Tools + Settings)
| Tool | Icon | Besonderheiten |
|------|------|----------------|
| SQEG Analyzer | ◆ | 37 Kriterien (29 PQ + 7 PQ-Erweitert + 1 NM), 4-Step, gewichtetes Scoring, SQEG-Skala |
| Meta-Tags Generator | ⟨/⟩ | 3 Varianten, GSC + DataForSEO Volumen/CPC |
| E-E-A-T Schnellcheck | ✦ | 4 Dimensionen, Signals Found/Missing |
| UX & Conversion | ↗ | 7 Dimensionen, Screenshot-Upload |
| Content Gap Analyzer | ⇄ | DataForSEO page_intersection, Claude-Cluster |
| Einstellungen | ⚙ | API-Keys, Passwort, GSC-Domains, DataForSEO, **Verbindungstest** |

---

## Architektur-Details

**Layout:** Sidebar 216px · Top-Bar `height:auto` · Content `padding-top` dynamisch via `updateContainerPadding()`

**Globaler Kontext (v2.0):** Toggle-Button im Header, 3 Felder: Haupt-Keyword, Conversion-Ziel, Zielgruppe. Gespeichert in `sessionStorage` (`ctx_keyword`, `ctx_goal`, `ctx_audience`). Alle Tools lesen via `getCtx()`. **Keine tool-eigenen Kontext-Felder** — alle sind hidden inputs die via `syncCtxToTools()` befüllt werden.

**SQEG-Analyzer Flow (v3.3) — strikt sequenziell:**
1. `await fetchGscData(url)` — GSC-Daten, gecacht in sessionStorage
2. `await fetch(dataforseo serp)` — SERP-Benchmark
3. `await fetch(dataforseo backlinks)` — Backlinks (gibt 40204 wenn Plan nicht aktiv, graceful skip)
4. Step 0: `await classifyYMYL(url, htmlSnippet)` — YMYL-Klassifikation (Sonnet)
5. Step 1: `await fetchPageSource(url)` — HTML via fetch.php
6. Step 2: `await callAPI(sq)` — 29 PQ-Kriterien (A–G) via Claude
7. Step 2b: `await fetchReputationData()` — Externe Reputation via DataForSEO SERP
8. Step 3: `await callAPI(sq3)` — 7 PQ-Erweiterte Kriterien (e1–e7) via Claude
9. Step 4: `await callAPI(nmPrompt)` — 1 Needs-Met-Kriterium (nm1) via Claude

**Kriterien-Struktur (v3.3):**
- `c1–c29` → PQ-Kriterien (Kategorien A–G) → in Haupttabelle
- `e1–e7` → PQ-Erweiterte Kriterien (Reputation, Schäden, Seitentyp) → in Erweiterter-Kriterien-Grid
- `nm1` → Needs Met → eigener NM-Block über der Tabelle
- NM-Kriterium wird beim Score **nicht** mitgerechnet (eigene Skala)

**Score-Berechnung (v3.1–3.3):**
- `calcWeightedScore(criteria)` — gewichtetes Scoring, exkl. nm1
- Gewichte: Trust-Kriterien 3×, E-E-A-T-Kriterien 2×, MC-Kriterien 1.5×, Basis 1×
- `getSqegRating(score)` → mappt auf Lowest / Low / Medium / Medium+ / High / Highest
- Score-Badge zeigt: `High · PQ 74/100`

**SQEG-Gewichtungsmap (WEIGHT_MAP):**
```javascript
// Trust (3×)
c18, c19, c20, c21, c22, c23, c24, e3, e4, e5, e6
// E-E-A-T (2×)
c5, c6, c7, c8, c9, c13, c16, c17, e1, e2
// MC-Qualität (1.5×)
c10, c11, c12, c14, c15, c28, c29
// Basis (1×) — alle anderen
```

**SQEG-Skala:**
| Score | Rating |
|-------|--------|
| 85–100 | Highest |
| 70–84 | High |
| 55–69 | Medium+ |
| 40–54 | Medium |
| 20–39 | Low |
| 0–19 | Lowest |

**Konfidenz-Score (v3.2):**
- Jedes Kriterium gibt `confidence: 0–100` zurück
- 90+ = klarer HTML-Beleg · 70–89 = indirektes Signal · 50–69 = Schlussfolgerung · <50 = wenig Datenbasis
- Darstellung: 3px-Balken unter jedem Finding, grün/amber/rot

**Einstellungen-Panel:** Verbindungstest-Block (oben) prüft alle 5 Services ohne Analyse-Flow: Anthropic (1 Token mit Haiku), DataForSEO (appendix/user_data + Balance), GSC (action=list, zeigt Domain-Anzahl), fetch.php (ping mit ungültiger URL → 400 = Erreichbarkeitsnachweis), PageSpeed Insights (~12s normal).

**URL-Cache (sessionStorage):** Keys: `html`, `htmlSize`, `truncated`, `gsc`, `serp` — invalidiert bei URL-Wechsel.

**GSC:** Service Account JWT in PHP, Domain-Matching mit www-Normalisierung, Top-20 Keywords (28 Tage).

**DataForSEO Actions:** `test` · `serp` · `serp_top10` · `keywords` · `backlinks` · `onpage` · `page_intersection`
Auth: Basic (Base64), location_code: 2276 (DE), language_code: de

**Archiv-Eintrag:** `{id, url, tool, timestamp, score, topic, data}`

**appData (SQEG, seit v4.3):** `{url, pageInfo, criteria, queries, goal, audience, ymyl, nm, serp, serpKeyword}`
- `serp`: Array der organischen SERP-Ergebnisse (aus DataForSEO, max. 10) — für Phase 4.3 SERP-Benchmark
- `serpKeyword`: Keyword das für den SERP-Fetch verwendet wurde

---

## ⚠ KRITISCHE STABILITÄTSREGELN — NIEMALS BRECHEN

### Regel 1: KEIN paralleler PHP-Fetch — NIEMALS
**Grund:** cPanel/Host-Europe verwendet file-based PHP Session-Locking. Gleichzeitige Requests an verschiedene PHP-Dateien korrumpieren die Session → alle nachfolgenden Calls liefern 401.

✅ RICHTIG — immer so:
```javascript
var gscData    = await fetchGscData(url);
var serpResult = await fetch('dataforseo.php?action=serp', { credentials: 'same-origin', ... });
var blResult   = await fetch('dataforseo.php?action=backlinks', { credentials: 'same-origin', ... });
var html       = await fetchPageSource(url);
```

❌ FALSCH — niemals so:
```javascript
// Promise.all ist VERBOTEN
const [gsc, serp] = await Promise.all([fetchGscData(), fetch('dataforseo.php...')])

// Auch VERBOTEN: Promise starten, später awaiten
var serpPromise = fetch('dataforseo.php...')  // startet SOFORT parallel!
var gscData = await fetchGscData()            // läuft gleichzeitig → Session-Konflikt
await serpPromise
```

### Regel 2: loadGscDomains() + loadSettingsPanel() NUR mit setTimeout(800)
**Grund:** Nach Login-Redirect ist die PHP-Session noch nicht stabil. Sofortige Calls → 401 → Session beschädigt.

✅ RICHTIG:
```javascript
setTimeout(function() {
    loadGscDomains();
    loadSettingsPanel();
}, 800);  // 800ms — NICHT entfernen, NICHT reduzieren
```

Diese Calls existieren auch in `switchTool('settings')` — das ist korrekt (Live-Reload beim Panel-Öffnen).

### Regel 3: DOMContentLoaded-Blöcke nicht anfassen
Mehrere separate `DOMContentLoaded`-Listener — NICHT zusammenführen, NICHT umstrukturieren.

### Regel 4: credentials: 'same-origin' bei JEDEM PHP-Fetch-Call
**Gilt ohne Ausnahme für:** `gsc.php`, `dataforseo.php`, `settings.php`
`api.php`, `fetch.php`, `archive.php` haben keine Session-Prüfung — dort optional aber empfohlen.

```javascript
fetch('settings.php', { credentials: 'same-origin' })
fetch('dataforseo.php?action=serp', { method: 'POST', credentials: 'same-origin', headers: {...}, body: ... })
fetch('gsc.php?action=data', { method: 'POST', credentials: 'same-origin', headers: {...}, body: ... })
```

Symptom wenn vergessen: "Fehler beim Laden" oder 401 obwohl Session aktiv ist.

### Regel 5: ini_set('session.cookie_path', '/') in login.php UND auth.php
**Grund:** login.php ohne cookie_path startet Session mit anderem Pfad als Tool-PHP-Dateien → zwei verschiedene Sessions → `$_SESSION['authenticated']` nie gesetzt → dauerhaft 401 überall.

Alle PHP-Dateien die `session_start()` aufrufen MÜSSEN davor haben:
```php
ini_set('session.cookie_path', '/');
session_start();
```
Betrifft: `login.php`, `auth.php`, `gsc.php`, `dataforseo.php`, `settings.php`

### Regel 6: Nach jedem JS-Patch zwingend Syntax-Check
**Grund:** Python-String-Replacement kann echte Newlines in JS-String-Literale einbauen → fataler Syntaxfehler → App komplett tot.

```bash
# Nach JEDEM Patch ausführen:
python3 -c "
import re
content = open('index.html', 'r', encoding='utf-8').read()
scripts = re.findall(r'<script(?![^>]*src)[^>]*>(.*?)</script>', content, re.DOTALL)
open('/tmp/test.js', 'w').write('\n'.join(scripts))
" && node --check /tmp/test.js && echo "Syntax OK"
```

### Regel 7: Keine Tool-eigenen Kontext-Felder
Seit v2.0 gibt es nur das globale Kontext-Panel. Alle Tools lesen via `getCtx()` aus `sessionStorage`. Keine tool-eigenen Eingabefelder für Keyword/Ziel/Zielgruppe anlegen.

### Regel 8: Bestehende Funktionen nicht neu implementieren
Immer zuerst `grep` bevor eine Funktion implementiert wird. Beispiel: GSC-Integration existiert bereits in Meta-Tags Generator — nicht nochmal bauen.

### Regel 9: Nie zwei display-Werte im selben style-Attribut
Fehler: `style="display:none; ... ;display:flex"` → display:flex überschreibt display:none → Element immer sichtbar.
Richtig: Nur `display:none` im HTML, JS setzt `element.style.display = 'flex'` bei Bedarf.

### Regel 10: nm1 immer von Score-Berechnung ausschließen
`calcWeightedScore()` und alle PQ-Filteroperationen schließen `c.id !== 'nm1'` aus. NM hat eine eigene Skala (FullyM–FailsM) und darf den PQ-Score nicht verfälschen.

### Regel 11: renderTable zeigt nur c1–c29, nicht e- oder nm-Kriterien
- `renderTable()` filtert: `id !== 'nm1'` UND `id.charAt(0) !== 'e'`
- `renderManual()` filtert: e-Kriterien, NICHT nm1
- NM hat eigenen Block (`#nm-section`)

---

## SQEG-Analyzer Prompts (v3.3)

### finding-Format (Chain-of-Evidence, seit v2.7)
Jedes `finding`-Feld folgt diesem Pflichtformat:
```
Beleg: [konkretes Signal aus HTML oder Datenpunkt] | Regel: [angewendete WENN-Bedingung] | Bewertung: [Urteil in einem Satz]
```
Darstellung in der UI: Bewertung fett oben, Beleg als blaue Box, Regel kursiv darunter. Funktion: `renderFinding()`.

### JSON-Schema-Felder (alle drei Prompts, seit v3.2)
```json
{
  "id": "c1",
  "category": "A: Seitenzweck",
  "criterion": "Name auf Deutsch",
  "sqeg_ref": "Sek. X.X",
  "status": "green|amber|red",
  "finding": "Beleg: ... | Regel: ... | Bewertung: ...",
  "improvement": "",
  "confidence": 85
}
```
NM-Objekt zusätzlich: `nm_score`, `nm_label`, `nm_intent`, `nm_gap`

### sq-Prompt (29 PQ-Kriterien): Kontext-Reihenfolge
```
1. URL + Seitendaten (JSON aus Step 1)
2. HTML-Quellcode (vollständig, bereinigt)
3. ctxBlock (GSC-Daten + globaler Kontext)
4. ymylBlock (YMYL-Klassifikation + Gate-Instruktionen)
5. serpBlock (SERP-Wettbewerber, wenn vorhanden)
6. backlinkBlock (Domain Rank etc., wenn Plan aktiv)
7. psiBlock (PageSpeed Score + Core Web Vitals)
8. JSON-Schema + CoE-Pflicht-Instruktion + confidence-Erklärung
9. Kriterien A–G (29 Stück) mit Wenn-Dann-Regeln
   HINWEIS am Ende: Needs Met wird NICHT hier bewertet
```

### sq3-Prompt (7 PQ-Erweiterte Kriterien e1–e7): Kontext-Reihenfolge
```
1. URL + Domain + Seitendaten
2. HTML-Quellcode (gekürzt auf 15.000 Zeichen)
3. ctxBlock
4. repBlock (externe Reputationsdaten)
5. ymylBlock
6. backlinkBlock (wenn vorhanden)
7. psiBlock (wenn vorhanden)
8. JSON-Schema + CoE-Pflicht-Instruktion + confidence-Erklärung
9. Kriterien e1–e7 (kein e8 mehr — NM ist Step 4)
   System-Prompt: "Nur PQ bewerten, NICHT Needs Met"
```

### nmPrompt (1 Needs-Met-Kriterium nm1): Kontext-Reihenfolge
```
1. Keyword (primärer Bewertungsanker — Pflicht)
2. GSC-Performance (CTR, Position)
3. serpBlock (SERP-Wettbewerber als Benchmark)
4. URL + Seitentyp + Titel + Meta-Description
5. HTML-Snippet (erste 6.000 Zeichen)
6. ctxBlock
   System-Prompt: "Nur Needs Met bewerten, nicht PQ"
   Output: nm_score (10/40/60/80/100), nm_label, nm_intent, nm_gap, confidence
```

---

## Bekannte Abhängigkeiten zwischen Dateien

| Datei | Session? | Abhängigkeiten |
|-------|----------|----------------|
| `tool/index.html` | nein | api.php, fetch.php, gsc.php, dataforseo.php, archive.php, settings.php |
| `../index.html` | nein | Landing Page (im Root, nicht in tool/) |
| `pagespeed.php` | **ja** | Google PSI API Key (hardcoded: AIzaSyA_jzl0ZZo6mgaz8ug0ssh4hcRbUowLwzA) |
| `api.php` | **nein** | Anthropic API Key (hardcoded) |
| `fetch.php` | **nein** | cURL — Host-Europe kann ausgehende Verbindungen blockieren |
| `archive.php` | **nein** | sqeg_archive.json |
| `gsc.php` | **ja** | gsc_domains.json, OpenSSL für JWT |
| `dataforseo.php` | **ja** | settings.json (credentials) |
| `settings.php` | **ja** | settings.json, schreibt api.php + login.php |
| `login.php` | **ja** | Muss ini_set cookie_path haben |
| `auth.php` | **ja** | Muss ini_set cookie_path haben |

---

## API-Zugangsdaten & Konfiguration

**DataForSEO:**
- Login: `norman.juling@web.de` · Password: in `settings.json`
- Location: 2276 (Deutschland) · Language: de
- Aktive APIs: SERP, Keywords, page_intersection
- **Nicht aktiv (Plan nötig):** `backlinks` → status_code 40204 → https://app.dataforseo.com/backlinks-subscription

**GSC:**
- Service Account: `evalupro@evalupro-seo-tools.iam.gserviceaccount.com`
- Konfigurierte Domains: `whiskywelt.net`, `brettspiele-magazin.de`
- JSON-Schlüssel: in `gsc_domains.json`

**Anthropic:**
- Model für Verbindungstest: `claude-haiku-4-5-20251001`
- Model für Analyse: Claude Sonnet (in api.php konfiguriert)

---

## Roadmap SQEG Analyzer

### ✅ Phase 1.1 — SERP-Top-10 in SQEG-Prompt (21.03.2026)
### ⏸ Phase 1.2 — Backlink-Signale (Code fertig, Plan fehlt)
### ✅ Phase 1.3 — Google PageSpeed Insights API (21.03.2026)
### ✅ Phase 1.4 — GSC-Keyword als Pflicht-Anchor für Needs Met (21.03.2026)
### ✅ Phase 2.1 — Wenn-Dann-Regeln pro Kriterium im Prompt (21.03.2026)
### ✅ Phase 2.2 — Chain-of-Evidence (Belege statt Urteile) (21.03.2026)
### ✅ Phase 2.3 — YMYL-Klassifikation als Gate (22.03.2026)

### ✅ Phase 2.4 — Zwei-Prompt-Architektur PQ + Needs Met (22.03.2026)
- **sq** (Step 2): 29 PQ-Kriterien A–G — Input: HTML + PSI + YMYL-Gate. Fokus: Seitenqualität absolut.
- **sq3** (Step 3): 7 PQ-Erweiterte Kriterien e1–e7 — Input: HTML + Reputation + PSI. Fokus: Reputation, Schäden, Seitentyp.
- **nmPrompt** (Step 4): 1 NM-Kriterium nm1 — Input: **SERP + Keyword als primärer Anker** + HTML-Snippet. Fokus: Suchanfrage-Passung.
- Intent-Klassifikation: Informational / Navigational / Transactional / Commercial Investigation
- NM-Skala: FullyM (100) / HighlyM (80) / ModeratelyM (60) / SlightlyM (40) / FailsM (10)
- NM-Badge + dedizierter NM-Block mit Content-Gap-Anzeige
- PQ-Score exkludiert nm1

### ✅ Phase 3.1 — Gewichtetes Scoring (Trust > E-E-A-T > MC) (22.03.2026)
- `calcWeightedScore()` — Formel: Σ(weight × points) / Σ(weight × 100) × 100
- `WEIGHT_MAP`: Trust 3×, E-E-A-T 2×, MC 1.5×, Basis 1×
- `getWeightGroup()` → Badge pro Tabellenzeile: Trust (rot), E-E-A-T (blau), MC (grün)
- Gewichtungs-Breakdown-Bar unter stat-grid: zeigt Score je Gruppe

### ✅ Phase 3.2 — Konfidenz-Score pro Kriterium (22.03.2026)
- `confidence: 0–100` in allen JSON-Schemata (sq, sq3, nm)
- `renderConfidence()` — 3px-Balken mit 50%-Basis (alles unter 50 = Spekulation, nie angezeigt)
- Skala beginnt bei 50% → 85% vs 95% visuell deutlich unterscheidbar
- 4 Farben: Direkt belegt (grün, 90+) · Indirekt belegt (gelbgrün, 70–89) · Ableitung (amber, 50–69) · Spekulation (rot, <50)
- Label-Text farbig + fett direkt neben dem Balken

### ✅ Phase 3.3 — Rating auf SQEG-Skala (Lowest → Highest) (22.03.2026)
- `getSqegRating(score)` — mappt 0–100 auf 6-stufige SQEG-Skala
- Score-Badge: `High · PQ 74/100` statt `PQ 74/100 — Gut`
- Skalen-Leiste im results-header: alle 6 Stufen, aktive farblich hervorgehoben
- Breakdown-Bar + Log zeigen ebenfalls SQEG-Label

### ✅ Phase 4.1 — Handlungsempfehlungen Impact × Aufwand (22.03.2026)
- `renderPriorities(criteria)` — rein client-side, kein API-Call
- Impact = Gewichtsgruppe (Trust 3× → Hoch, E-E-A-T 2× → Mittel, Rest → Niedrig)
- Aufwand = `EFFORT_MAP` nach Kategorie (Impressum/Kontakt → Gering, MC → Mittel, Reputation/E-E-A-T → Hoch)
- 3 Kacheln: 🔥 Sofort-Wins · ⚡ Quick-Wins · 🎯 Strategisch — max. 4 Items je Kachel
- Restliche Items (geringer Impact) in ausklappbarer Liste darunter
- Nur amber + red Kriterien, ohne nm1; red vor amber, dann nach Gewicht sortiert

### ✅ Phase 4.2 — Score-Trends aus Archiv (22.03.2026)
- `renderScoreTrend(url, score)` — async, liest archive.php nach renderResults
- URL-Normalisierung: https/www stripped, case-insensitive Vergleich
- Sparkline: bis 8 Datenpunkte, letzter Balken farbig (grün/amber/rot nach Score)
- Delta-Badge: +N (grün) / -N (rot) / ±0 (grau) vs. vorletzter Eintrag
- Erscheint nur ab 2 Analysen derselben URL — sonst versteckt
- Position: unter der SQEG-Skalen-Leiste im Schnellüberblick (id: `score-trend`)

### ✅ Phase 4.3 — SQEG-Benchmark gegen SERP-Top-3 (22.03.2026)
- `renderSerpBenchmark(ownScore, ownUrl, serpItems, keyword)` — rein client-side
- `serpOrganic` + `serpKwForFetch` werden in `appData.serp` + `appData.serpKeyword` gespeichert
- SERP-Scores für Top-3: Heuristik Pos.1→82, Pos.2→77, Pos.3→72 (konservative Schätzung)
- Eigene Seite: echter gemessener PQ-Score, farbig hervorgehoben
- Falls eigene URL in Top-3: echter Score ersetzt Schätzwert
- Position: im Schnellüberblick zwischen Needs-Met-Block und Handlungsempfehlungen

### ✅ Phase 4.4 / 4.5 — Zonen-Layout & UX-Verbesserungen (22.03.2026)
- Drei klar getrennte Zonen mit `zone-divider` (Bricolage Grotesque, Trennlinie, Badge)
  - **Analyse-Log** (`zone-log`, bg3) — mit Log-Toggle: nach Abschluss ein-/ausblendbar
  - **Schnellüberblick** (`zone-overview`, bg2, 3px Akzent-Topline) — Score + alle Summary-Blöcke
  - **Detailanalyse** (`zone-detail`, bg3) — Tabelle c1–c29 + PQ-Erweitert e1–e7
- `toggleLogBox()` — zeigt „Log ausblenden/einblenden" Button nach `stopLoader()`; reset bei Re-Analyse
- NM-Tooltip: `?`-Icon neben „Needs Met"-Label, CSS-only Hover/Focus, zeigt vollständige 5-stufige Skala
- NM-Score Zahlenwert entfernt — Badge + Block + Log zeigen nur Label (z.B. SlightlyM)

### ⬜ Phase 5 — Dateistruktur-Refactoring
- Ziel: index.html (~5.000 Zeilen) → Shell (~600 Z.) + tool-sqeg.js + tool-meta.js + tool-eeat.js + tool-ux.js + tool-gap.js + shared.js
- Keine Funktionsänderung — rein mechanische Aufteilung
- Voraussetzung: alle aktiven Feature-Entwicklungen am SQEG Analyzer abgeschlossen

---

## Offene Punkte (unabhängig von Roadmap)
- Landing Page: Impressum + Datenschutz (Seite vorhanden, Links zeigen auf #)
- Sidebar-Logo und Top-Bar Trennlinie (Browser-Test ausstehend)
- Phase 5: Dateistruktur-Refactoring (index.html → Shell + tool-X.js + shared.js)

---

## Nächste Schritte
1. ✅ Phase 1–4.5 abgeschlossen
2. **Phase 5: Dateistruktur-Refactoring** — index.html aufteilen (eigener Chat, keine Funktionsänderung)
3. Phase 1.2: DataForSEO Backlinks-Plan aktivieren (optional, Code steht bereit)
4. Landing Page: Impressum + Datenschutz Seiten anlegen
5. Neue Tool-Ideen / Erweiterungen besprechen

---

## Changelog
| Datum | Version | Änderung |
|-------|---------|----------|
| 22.03.2026 | v4.5 | Zonen-Überarbeitung: Divider-Balance (Bricolage, border2, shadow), Log-Toggle (toggleLogBox), NM-Tooltip CSS-only mit 5-stufiger Skala. |
| 22.03.2026 | v4.4 | Zonen-Layout eingebaut: zone-log · zone-overview · zone-detail. Zone-Divider mit Label+Linie+Badge. |
| 22.03.2026 | v4.3 | Phase 4.3 abgeschlossen. SERP-Benchmark: renderSerpBenchmark(), Heuristik Pos.1→82/2→77/3→72, eigener Score farbig. appData um serp + serpKeyword erweitert. |
| 22.03.2026 | v4.2 | Phase 4.2 abgeschlossen. Score-Trends: renderScoreTrend(), Sparkline 8 Punkte, Delta-Badge, URL-Normalisierung. |
| 22.03.2026 | v4.1 | Phase 4.1 abgeschlossen. Handlungsempfehlungen: renderPriorities(), 3 Kacheln (Sofort/Quick/Strategisch), EFFORT_MAP, client-side. |
| 22.03.2026 | — | Fix: NM-Score Zahlenwert entfernt — Badge + Block + Log zeigen nur noch Label (z.B. SlightlyM). |
| 22.03.2026 | — | Fix: Konfidenz-Balken neu. Skala ab 50%, 4 Farben, Label fett+farbig. |
| 22.03.2026 | v3.3 | Phase 3.3 abgeschlossen. getSqegRating(): Lowest/Low/Medium/Medium+/High/Highest. Score-Badge mit SQEG-Label. Skalen-Leiste im results-header. |
| 22.03.2026 | v3.2 | Phase 3.2 abgeschlossen. confidence: 0–100 in allen JSON-Schemata. renderConfidence() mit 3px-Balken. Konfidenz-Stufen im Prompt. |
| 22.03.2026 | v3.1 | Phase 3.1 abgeschlossen. calcWeightedScore(), WEIGHT_MAP (Trust 3x, E-E-A-T 2x, MC 1.5x). Gewichts-Badge pro Tabellenzeile. Breakdown-Bar. |
| 22.03.2026 | v2.9 | Phase 2.4 abgeschlossen. Drei-Prompt-Architektur: sq (29 PQ), sq3 (7 PQ-Erweitert), nmPrompt (1 NM). NM-Badge + NM-Block. PQ-Score exkl. nm1. |
| 22.03.2026 | v2.8 | Phase 2.3 abgeschlossen. YMYL-Klassifikation als Gate: classifyYMYL() Sonnet-Call als Step 0. ymylBlock in sq + sq3. YMYL-Badge im Ergebnis-Header mit Tooltip. |
| 21.03.2026 | v2.7 | Phase 2.2 abgeschlossen. Chain-of-Evidence: Pflichtformat Beleg+Regel+Bewertung in sq + sq3. renderFinding() mit CoE-Darstellung. |
| 21.03.2026 | v2.6 | Phase 2.1 abgeschlossen. Wenn-Dann-Regeln für alle 37 Kriterien. PSI-Gate in c27. Freshness-Differenzierung in c28. YMYL-Gate in e5. |
| 21.03.2026 | v2.5 | Phase 1.4 abgeschlossen. Keyword-Warnung via sessionStorage. Bugfix: display:none/flex-Konflikt. |
| 21.03.2026 | v2.4 | PageSpeed Insights API (Phase 1.3). pagespeed.php. psiBlock in sq + sq3. |
| 21.03.2026 | v2.3 | Verbindungstest in Einstellungen. login.php + auth.php ini_set-Fix. 9 Stabilitätsregeln dokumentiert. |
| 21.03.2026 | v2.2 | SERP-Benchmark (1.1) + Backlink-Code (1.2, Plan nötig). serpBlock + backlinkBlock. |
| 20.03.2026 | v2.1 | Globaler Kontext. 37 Kriterien. 8 erweiterte Kriterien auto. GSC in Meta-Tags Generator. |
| 20.03.2026 | v2.0 | Globales Kontext-Panel. syncCtxToTools(). Keine tool-eigenen Kontext-Felder. |
