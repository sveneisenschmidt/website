# Assistent im Panel: Design

Stand: 2026-10-12. Entschieden mit Sven im Chat. Grundlage ist der Assistent von concertshotsbyluisa.de (`../concertshotsbyluisa/www/site/plugins/assistant`, Issue #18 dort).

## Ziel

Sven schreibt Beiträge im Panel mit Claude. Der Assistent ersetzt die Claude-Code-Skills `write-post` und `journey-post`. Er schlägt vor, Svens Klick übernimmt.

## Aufgaben

1. **Daily Photo schreiben:** Sven zieht Fotos in den Chat und schreibt ein paar Sätze, meist auf Deutsch. Der Assistent schlägt einen neuen Entwurf vor: Typ, Titel, Slug, Datum, Anriss, Text in Svens Stimme, Alternativtexte, Titelbild.
2. **Reise-Tag mit Karte:** Sven nennt die Orte des Tages. Der Assistent holt die Strecke von OSRM und schlägt Route und Kartenpunkte vor, dazu den Beitrag wie bisher `journey-post`.
3. **Texte überarbeiten:** In einem bestehenden Beitrag schlägt der Assistent Änderungen an Titel, Anriss oder Text vor, bei Formulierungen als A/B/C.
4. **Fahrrad-Runde aus GPX:** Sven zieht eine GPX-Datei in den Chat. Der Assistent macht daraus die Route und schneidet 500 m um Start und Ziel ab, damit Svens Adresse nicht in der Route steht.

## Nicht im Umfang

- Löschen und Veröffentlichen. Beides bleibt im Panel bei Sven.
- Kamera-Daten schreiben. EXIF kommt beim Upload aus der Datei (Plugin `blog`).
- Das Kirby-MCP auf dem Server. Es kommt nur lokal als Dev-Abhängigkeit (`bnomei/kirby-mcp`, `--dev`).

## Aufbau

Übernahme von Luisas Plugin mit umbenannten Präfixen (`cbl_` zu `blog_`, Plugin-ID `sveneisenschmidt/assistant`):

| Datei | Aufgabe | Änderung gegenüber Luisa |
|---|---|---|
| `www/site/plugins/assistant/index.php` | Panel-Bereich „Assistent“ und „Protokoll“, API-Routen, Knopf an Beiträgen | Knopf an den vier Beitrags-Templates statt an Serien |
| `claude.php` | Anfrage an die Anthropic-API über Kirbys `Remote` | unverändert |
| `chat.php` | Chat in der Kirby-Session, eine Runde pro Anfrage, System-Prompt | Prompt kommt aus dem Feld, nicht aus dem Code |
| `tools.php` | Lese- und Vorschlags-Tools | neue Tools, siehe unten |
| `apply.php` | Svens Klick auf eine Karte | neue Kartentypen |
| `log.php` | Protokoll in `content/.assistant/<Jahr>-<Monat>.jsonl` | unverändert |
| `uploads.php` (neu) | Fotos und GPX aus dem Chat, vorübergehend | neu |
| `route.php` (neu) | OSRM-Abfrage, Vereinfachung, GPX-Kürzung | neu, aus `fetch-route.py` und `gpx-to-route.py` |
| `index.js`, `index.css` | Panel-Ansicht | Upload-Feld im Chat, neue Karten |

Der Schlüssel für die Anthropic-API steht als `assistantKey` in `env.php`, gesetzt vom Deploy (Phase 5). Ohne Schlüssel ist der Assistent aus, wie bei Luisa.

## System-Prompt

**Der Prompt liegt nie in Git.** Das Repo ist öffentlich, Sven will den Prompt nicht zeigen (2026-10-12).

- Der ganze Prompt steht im Feld `assistantPrompt` unter Website › Assistent, also in `content/site.txt`. Das ist Zustand: auf dem Server, im Backup, nie in Git. Sven ändert ihn im Panel.
- Inhalt: die Arbeitsweise (nichts ohne Klick speichern, nichts erfinden, Kirby-Format mit `(image: datei.jpg)`, `(map:)`, Feldern `intro` und `text`, Slug-Regeln) und Svens Schreibregeln aus den Skills `write-post` und `journey-post`, umgestellt von Hugo auf Kirby.
- Der Code hängt nur das Datum des Tages (Berliner Zeit) an. Ist das Feld leer, bleibt der Assistent aus und das Panel sagt, dass der Prompt fehlt.
- Öffentlich im Code stehen nur die Tool-Beschreibungen, die die API braucht. Sie sagen, was ein Tool tut, nicht wie Sven schreibt.
- Die Quelle des Prompts liegt nur lokal und nie in Git; `.git/info/exclude` schließt sie auf jedem Branch aus, auch für `make push` auf `main`. Ihr Name steht nirgends im Repo.
- Erste Füllung: Claude schreibt den Prompt aus den Skills in diese lokale Datei. `php bin/assistant-prompt.php <datei>` schreibt sie lokal ins Feld. Auf dem Server fügt Sven sie einmal im Panel ein.
- `make kirby-seed` ersetzt `content/site.txt`. Der Seed übernimmt darum `assistantPrompt` und `assistantModel` aus der alten Datei in die neue.
- Tests nutzen einen Platzhalter-Prompt, nie den echten.

## Tools

Lese-Tools laufen sofort. Vorschlags-Tools ändern nichts, sie erzeugen eine Karte.

| Tool | Art | Eingabe | Ergebnis |
|---|---|---|---|
| `find_posts` | lesen | Typ (oder leer), Suchtext, Zeitraum | höchstens 20 Beiträge mit ID, Titel, Datum, Status |
| `read_post` | lesen | Beitrags-ID | Felder, Dateien mit Alternativtext, Bildunterschrift, Fotograf und EXIF |
| `view_photo` | lesen | Datei-ID oder Upload-ID | Vorschau (lange Kante 1568 px, JPEG) mit Texten |
| `web_search` | lesen | Anthropic-Server-Tool | höchstens 5 pro Anfrage |
| `propose_post` | Karte | Typ, Titel, Slug, Datum, Anriss, Text, Upload-IDs, Alternativtext je Upload, Titelbild, Reise (bei Reisen) | neuer Entwurf |
| `propose_text` | Karte | Beitrags-ID, Feld (`title`, `intro`, `text`), 1 bis 3 Varianten | geänderter Text; bei 2 oder 3 Varianten wählt Sven per Knopf A, B oder C |
| `propose_photo_texts` | Karte | Datei-ID, Alternativtext, Bildunterschrift, Fotograf | geänderte Datei-Felder |
| `propose_route` | Karte | Beitrags-ID, Stationen in Fahrtreihenfolge (`lat`, `lon`, `label`, `marker` ja/nein) | `route.json` und Kartenpunkte im Beitrag |
| `propose_gpx_route` | Karte | Beitrags-ID, Upload-ID der GPX, Marker (`lat`, `lon`, `label`) | gekürzte `route.json` und Kartenpunkte |
| `ask_choice` | Frage | Frage, Optionen | Knöpfe; Svens Wahl kommt als nächste Nachricht |

Regeln für die Tools:
- Ziel sind nur Beiträge (`photo`, `trip`, `note`, `essay`) und About. Schreibbar sind `title`, `intro`, `text`, bei Dateien `alt`, `caption`, `photographer`, bei Reisen `trip`, dazu Route und Kartenpunkte.
- `propose_route` fragt OSRM schon beim Vorschlag ab. Die Karte zeigt km und Fahrzeit (wie `fetch-route.py`); bei einem Fehler bekommt Claude die Meldung zurück, und es entsteht keine Karte.
- Route-Vereinfachung wie `fetch-route.py`: Douglas-Peucker mit 0,0005°, Koordinaten auf 5 Stellen gerundet, GeoJSON `LineString`.
- GPX-Kürzung wie `gpx-to-route.py`: Alle Punkte innerhalb von 500 m um den ersten und den letzten Punkt fallen weg. Auf den Startpunkt kommt nie ein Marker.

## Karten und Klick

Wie bei Luisa: Jede Karte zeigt, was sich ändert. Text-Karten zeigen Vorher und Nachher. Svens Klick führt `apply.php` unter seinem Konto aus, darum steht jede Änderung im Verlauf.

| Karte | Klick bewirkt |
|---|---|
| Neuer Entwurf | Seite als Entwurf im richtigen Bereich (`photos`, `trips`, `notes`, `essays`). Die Uploads werden als Dateien mit Template `image` angelegt, mit Alternativtext; der Hook `file.create:after` liest EXIF. Titelbild gesetzt. |
| Text | Feld gespeichert. Bei A/B/C speichert der Knopf der gewählten Variante. |
| Fototexte | Felder der Datei gespeichert |
| Route | `route.json` als Datei mit Template `route` angelegt oder ersetzt, `route` und `mappoints` gesetzt; fehlt `(map:)` im Text, wird es ans Ende angehängt |

Nach jedem Klick bekommt Claude mit Svens nächster Nachricht eine Zeile, was entschieden wurde (wie bei Luisa).

## Uploads im Chat

- Sven zieht Fotos (JPEG, PNG, WebP) oder eine GPX-Datei in den Chat, höchstens 20 Dateien pro Upload (Server: `max_file_uploads = 20`, `upload_max_filesize = 512M`).
- Der Server legt sie in `site/cache/assistant/uploads/<Session>/` ab, jede mit einer Upload-ID. Das ist erzeugte Datei-Ablage, nie im Backup.
- Claude bekommt von Fotos nur verkleinerte Vorschauen. Die Originale bleiben bis zum Klick liegen.
- Der Klick auf „Neuer Entwurf“ verschiebt die Originale in den Entwurf. „Neuer Chat“ und das Ende der Session löschen den Ordner. Ordner älter als 2 Tage löscht der nächste Upload.

## Fehler

- API-Fehler, Zeitüberschreitung, fehlender Schlüssel: wie bei Luisa, die Panel-Ansicht zeigt die Meldung, der Chat bleibt wie vorher.
- OSRM nicht erreichbar oder ohne Route: Claude bekommt die Meldung als Tool-Ergebnis, es entsteht keine Karte.
- GPX ohne Punkte oder kürzer als 1 km nach der Kürzung: Meldung an Claude, keine Karte.
- Ein Klick auf eine Karte, deren Ziel sich inzwischen geändert hat (Text nicht mehr gleich „Vorher“): Hinweis statt Speichern, wie bei Luisa.

## Tests

- `AssistantTest` von Luisa wird mitportiert. Die Option `blog.assistant.transport` ersetzt Claude, die Tests rufen keine API auf.
- Neue Tests: jede Karte bis zum Klick (Entwurf mit Uploads und EXIF, Text mit A/B/C, Fototexte, Route), OSRM mit einer festen Antwort (Option für den HTTP-Aufruf), Douglas-Peucker, GPX-Kürzung um 500 m, Aufräumen der Uploads.
- Der Restore-Test bleibt grün: `content/.assistant` und das Prompt-Feld sind Zustand und kommen ins Backup.
- Ein Test prüft, dass `bin/seed.php` den Prompt erhält, und `git grep` in `make kirby-lint` prüft, dass kein Prompt-Text aus der lokalen Datei im Repo steht.

## Reihenfolge

Der Assistent kommt nach Phase 2 und vor Phase 3. Er braucht nur das Inhaltsmodell und die Blueprints. Templates (Phase 3) und URLs (Phase 4) betreffen ihn nicht. Verlauf und Statistik (Plugins `changelog` und `analytics` von Luisa) kommen davor, der Verlauf zeigt dann auch die Klicks im Assistenten.
