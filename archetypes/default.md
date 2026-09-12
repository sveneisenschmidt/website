+++
date = '{{ .Date }}'
draft = true
title = '{{ replace .File.ContentBaseName "-" " " | title }}'
# photo, trip, note oder essay. Entscheidet das Layout und den Archivfilter.
type = "note"
# Dateiname des Titelbilds im selben Ordner. Nur ein Beitrag mit cover
# erscheint im Fotostream.
# cover = ""
+++
