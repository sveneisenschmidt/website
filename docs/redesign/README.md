# Redesign, Zwischenstand 11. September 2026

Die Quellen der zwei Design-Canvases liegen hier, weil der Arbeitsordner einer
Claude-Sitzung nach deren Ende verschwindet. Die Canvases selbst sind veröffentlicht
und bleiben bestehen; dieser Ordner ist die Sicherung.

| Canvas | Inhalt | Adresse |
|---|---|---|
| Website Foundation | Intention, Prinzipien, Inhaltstypen, Architektur, Datenqualität, Taxonomie, nicht-funktionale Anforderungen | https://claude.ai/code/artifact/a31412c4-6da9-429c-a4dc-1efc0021db1d |
| Website Mockups | Artikeltypen, Design System, Entwürfe in zwei Swimlanes | https://claude.ai/code/artifact/628c40e1-ede6-4127-822a-72a4201d0740 |

Der Plan liegt unter `~/.claude/plans/dreamy-waddling-crab.md`.

---

## Vier Artikeltypen

Aus 272 Posts gezählt. Jeder Typ braucht etwas, das der darüber nicht braucht.

| Typ | Posts | Definition | Braucht |
|---|---|---|---|
| Photo | 176 | Ein Foto, Bildunterschrift unter 200 Wörtern | Bild in Spaltenbreite, Kameradaten |
| Gallery | 63 | Ein Tag mit vier oder mehr Bildern, oder mit Route | Bildfolge, Route wo vorhanden |
| Note | 24 | Kurzer Text, unter 700 Wörtern, keine Gliederung | Satzbreite 60 Zeichen |
| Essay | 9 | Langer Text, über 700 Wörter oder mit Gliederung | Abschnittsüberschriften, Lesezeit |

Weder Serie noch Thema sind ein Typ. Ein Reisetag ist eine Gallery in `summer-2026`,
eine Release-Note ist eine Note in `hypo`.

## Drei Felder statt einer Themenliste

- `type` — genau eines der vier. Entscheidet als einziges Feld das Layout.
- `series` — optional eines, im Post deklariert statt aus dem Slug geraten.
  17 Serien über 70 Posts.
- `tags` — beliebig viele, rein beschreibend, führen nirgendwohin.
  family 45, software 28, work 8, games 6, ai 6, cycling 5, music 4, books 1.

`topics` fällt weg. Grund: nimmt man „Daily Photo", „Photography" und „Travel" heraus,
haben 176 von 272 Posts überhaupt kein Thema mehr. Die drei Topics, die fast die ganze
Seite abdecken, sind keine Themen — zwei beschreiben die Seite selbst, eines wiederholt
die Serienzugehörigkeit.

Das Feld heißt `type` und nicht `kind`, weil `kind` in Hugo-Templates mit `.Kind`
kollidiert, der eingebauten Seitenart.

## Design System

**Wird neu gebaut.** Die Werte unten sind der heutige Stand und der Ausgangspunkt, nicht
das System. Die Anforderung steht in Schritt 1 des Plans: drei Ebenen aus Primitiven,
semantischen Marken und Bauteilmarken; jede Marke mit genannter Rolle; keine falsche
Wiederverwendung, also zwei Marken für zwei Rollen auch bei gleichem Wert; Obergrenzen
je Skala; und Prüfungen, die rohe Werte in Regeln finden.

| | heutiger Stand |
|---|---|
| Schrift | Encode Sans, selbst gehostet, 28 KB |
| Größen | 32 Titel, 24 Überschrift, 18 Fließtext, 16 sekundär, 12.8 Label |
| Gewichte | 300 Fließtext, 900 Titel |
| Farben | Tinte #222, Link #00e, Linie #222, Grund #f2f2f2 |
| Abstände | 4, 8, 16, 32, 64 |
| Satzbreiten | Spaltenbreite für Bilder, 60 Zeichen für Text |
| Radius | 4 px, nur Bilder |
| Zeilenhöhe | 1.05 bei Versalien, 1.15 sonst |

Versalien markieren den Titel eines Dings, nicht eine Überschrift darin.

## Layout

Volle Fensterbreite, zwölf Spalten, 32 px Rinne, fluid. Kopf mit Namen links und
Navigation rechts, keine Trennlinie darunter. Fuß: eine Zeile mit dem RSS-Link.

Startseite zweispaltig. Bilder auf acht Spalten mit einem großen Aufmacher und einem
Kontaktbogen darunter, Texte auf vier Spalten als Liste mit eigenem Aufmacher. Beide
Spalten mit Sektionstitel oben und Anzahl plus Older-Link unten.

Die Bildspalte hat ihre volle natürliche Höhe und scrollt nicht. Die Textspalte nimmt
genau diese Höhe an und rollt innen, zwischen festem Sektionstitel und festem Fuß.

Kein Seitenverhältnis erzwingen. Stichprobe von 40 Fotos: 26 in 3:2 quer, 12 hochkant
meist 4:5, 2 in 4:3. Die Spalte gibt die Breite vor, das Bild behält seine Höhe.

---

## Offen

- Lane B hat nur Startseite, Photo und Archive. Gallery, Note, Essay und die
  Handy-Ansicht fehlen.
- Vier Posts sind als Daily Photo markiert, sind aber Texte: `farewell` 568 Wörter,
  `packed` 400, `reading` 358, `parasol` 304.
- Gehört `mayrhofen-at-night` in die Serie `winter-2026`?
- Sollen die 28 Texte eine selbst geschriebene `description` bekommen?

## Nicht committet

`layouts/_partials/search.html` und `assets/css/styles.css`: Pagefind liefert jetzt
einen Textausschnitt mit hervorgehobener Fundstelle. Lokal getestet, nicht live.

## scripts/migrate.py

Ein Trockenlauf, schreibt nichts. Aufruf aus dem Projektwurzelverzeichnis:

    python3 docs/redesign/scripts/migrate.py

**Er leitet noch das alte Modell mit zwei Werten ab und muss auf die vier Typen
umgeschrieben werden.** Was aus ihm gilt: 17 Serien über 70 Posts, 8 Tags auf 94 Posts,
und dass sich 255 der 272 Posts aus vorhandenen Angaben ableiten lassen.
