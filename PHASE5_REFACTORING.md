# evalu-pro · Phase 5 — Dateistruktur-Refactoring
**Ziel:** `tool/index.html` (~5.000 Zeilen) in separate Dateien aufteilen, ohne eine einzige Funktion zu ändern.

---

## Grundprinzip

**Was sich ändert:** Wo der Code liegt.  
**Was sich NICHT ändert:** Was der Code tut. Kein Bugfix, kein neues Feature, keine Umbenennung von Funktionen.

Der Browser lädt JS-Dateien mit `<script src="...">` genauso wie inline-JS. Für den Nutzer ändert sich nichts sichtbares.

---

## Ziel-Dateistruktur

```
tool/
├── index.html        ← Shell (~400 Zeilen): nur HTML-Gerüst + CSS + <script src="..."> Tags
├── shared.js         ← Gemeinsame Funktionen aller Tools
├── tool-sqeg.js      ← SQEG Analyzer (der größte Block)
├── tool-meta.js      ← Meta-Tags Generator
├── tool-eeat.js      ← E-E-A-T Schnellcheck
├── tool-ux.js        ← UX & Conversion Analyzer
├── tool-gap.js       ← Content Gap Analyzer
└── (alle PHP-Dateien bleiben unverändert)
```

---

## Was in welche Datei kommt

### `shared.js` — alles was mehrere Tools nutzen
- `escHtml()`, `ts()`, `log()`, `toolLog()`, `setProgress()`, `toolProgress()`, `charBar()`
- `getActiveUrl()`, `syncGlobalUrl()`, `switchGlobalTab()`, `getCtx()`, `saveCtx()`, `loadCtx()`, `syncCtxToTools()`, `toggleCtxPanel()`
- `switchTool()`, `updateContainerPadding()`
- `fetchGscData()`, `fetchPageSource()`, `cleanHtmlForAnalysis()`
- `callAPI()`, `extractJSON()`
- `saveToArchive()`, `loadArchive()`, `renderArchive()`, `loadArchiveEntry()`
- `startLoader()`, `stopLoader()`, `toggleLogBox()`
- Globale Variablen: `appData`, `loaderTimer`, `LOADER_MSGS`

### `tool-sqeg.js`
- `startAnalysis()`, `reAnalyse()`
- `classifyYMYL()`, `fetchReputationData()`
- `calcWeightedScore()`, `getCriterionWeight()`, `getWeightGroup()`, `getSqegRating()`
- `WEIGHT_MAP`, `EFFORT_MAP`, `MANUAL`
- `renderResults()`, `renderTable()`, `renderManual()`, `renderFinding()`, `renderConfidence()`
- `renderPriorities()`, `togglePrioAll()`
- `renderScoreTrend()`, `renderSerpBenchmark()`
- `setFilter()`, `showManual()`
- `exportHTML()`

### `tool-meta.js`
- `startMeta()`, `renderMetaResults()`, `hideMetaErr()`, `showMetaErr()`

### `tool-eeat.js`
- `startEeat()`, `renderEeatResults()`

### `tool-ux.js`
- `startUx()`, `renderUxResults()`, `onUxScreenshot()`, `clearUxScreenshot()`, `populateUxSqegDropdown()`, `toggleUxOpt()`
- `UX_DIMS`

### `tool-gap.js`
- `startGap()`, `renderGapResults()`

---

## Reihenfolge der `<script>`-Tags in index.html

Die Reihenfolge ist wichtig — `shared.js` muss zuerst geladen sein, weil alle anderen darauf zugreifen:

```html
<script src="shared.js"></script>
<script src="tool-sqeg.js"></script>
<script src="tool-meta.js"></script>
<script src="tool-eeat.js"></script>
<script src="tool-ux.js"></script>
<script src="tool-gap.js"></script>
```

---

## Vorgehensweise im Chat (Schritt für Schritt)

**Wichtig:** Nach JEDEM Schritt Syntax-Check. Kein nächster Schritt ohne grünes Licht.

### Schritt 1 — Vorbereitung & Inventar
- Alle Funktionen und globalen Variablen aus `index.html` auflisten
- Abhängigkeiten prüfen: welche Funktion ruft welche auf?
- Zuweisung zu Zieldatei festlegen (kann von obiger Liste leicht abweichen)

### Schritt 2 — `shared.js` extrahieren
- Alle gemeinsamen Funktionen aus `index.html` in neue Datei `shared.js` ausschneiden
- In `index.html`: `<script src="shared.js"></script>` einsetzen
- **Syntax-Check:** `node --check shared.js` + `node --check` des verbleibenden index-JS

### Schritt 3 — `tool-sqeg.js` extrahieren
- Größter Block — SQEG-spezifische Funktionen ausschneiden
- `<script src="tool-sqeg.js"></script>` in index.html
- **Syntax-Check beider Dateien**

### Schritt 4 — Kleinere Tools extrahieren (einzeln)
- `tool-meta.js` → Check
- `tool-eeat.js` → Check
- `tool-ux.js` → Check
- `tool-gap.js` → Check

### Schritt 5 — index.html aufräumen
- Jetzt enthält index.html nur noch: CSS + HTML-Gerüst + `<script src="...">` Tags
- Leerzeichenbereinigung, überflüssige Kommentare entfernen
- **Finaler Syntax-Check aller Dateien**

### Schritt 6 — Funktionstest-Checkliste
Da du nicht entwickelst, gibt es eine konkrete manuelle Checkliste zum Durchklicken:

1. Seite lädt ohne Fehler (Browser-Konsole: keine roten Meldungen)
2. SQEG Analyse starten → Ergebnisse erscheinen korrekt
3. Log-Toggle funktioniert
4. Archiv-Einträge laden (Sidebar)
5. Meta-Tags Generator starten
6. E-E-A-T Schnellcheck starten
7. UX Analyzer starten
8. Content Gap Analyzer starten
9. Einstellungen → Verbindungstest grün
10. Ausloggen → einloggen → alles noch grün

---

## Risiken und Gegenmaßnahmen

| Risiko | Gegenmaßnahme |
|--------|---------------|
| Funktion in falscher Datei → `undefined`-Fehler | Inventar-Schritt 1 sorgfältig, Syntax-Check nach jedem Schritt |
| Globale Variable in shared.js zu spät deklariert | `var`-Deklarationen immer an Dateianfang |
| `DOMContentLoaded`-Handler in mehreren Dateien | Alle in `shared.js` zusammenfassen |
| PHP-Session-Probleme nach Upload | Einmal ausloggen → einloggen (bekanntes Verhalten, Checkpoint dokumentiert) |
| Partial-Upload (nur manche Dateien hochgeladen) | Alle 7 Dateien auf einmal hochladen, nicht einzeln |

---

## Was PHP-seitig NICHT geändert wird

`api.php`, `fetch.php`, `archive.php`, `settings.php`, `dataforseo.php`, `gsc.php`, `pagespeed.php`, `login.php`, `auth.php` — alle bleiben exakt so wie sie sind. Das Refactoring betrifft ausschließlich die client-seitige HTML/JS-Datei.

---

## Ergebnis nach Phase 5

| Vorher | Nachher |
|--------|---------|
| 1 Datei, ~5.000 Zeilen | 7 Dateien, je ~300–800 Zeilen |
| Jeder Edit = gesamte Datei im Kontext | SQEG-Fix = nur tool-sqeg.js (~800 Z.) nötig |
| Syntax-Fehler betrifft alles | Fehler in einer Datei isoliert |
| Neue Tools schwer integrierbar | Neue tool-X.js einfach anhängen |
