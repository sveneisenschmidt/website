// Behält die Stelle beim Wechsel des Filters.
//
// Die fünf Filterseiten unterscheiden sich nur darin, welche Beiträge sie zeigen.
// Nach oben zu springen verliert die Stelle, an der man gerade war, und gewinnt
// nichts.
//
// Zwei Stellen, nicht eine: auf der Startseite rollt der Strom in seinem eigenen
// Kasten, und das Fenster steht dabei still; im Register rollt das Fenster. Gemerkt
// wird deshalb beides, und beim Ankommen wird beides gesetzt — was es auf der neuen
// Seite nicht gibt, bleibt einfach ungenutzt.
//
// Ohne dieses Skript verhält sich der Filter wie jeder Link: die neue Seite beginnt
// oben. Das ist kein Fehler, nur weniger angenehm.
//
// Es läuft im Kopf der Seite und muss deshalb warten: zur Laufzeit des Skripts gibt es
// weder die Filterzeile noch den Strom.

document.addEventListener("DOMContentLoaded", function () {
    var KEY = "stream-scroll";

    var nav = document.querySelector(".stream-filter");
    var list = document.querySelector(".writing-list");

    // Der Browser stellt beim Zurückgehen selbst wieder her. Das soll er behalten;
    // hier geht es nur um den Weg vorwärts von einem Filter zum nächsten.
    if (nav) {
        nav.addEventListener("click", function (event) {
            var link = event.target.closest("a");
            if (!link || event.metaKey || event.ctrlKey || event.shiftKey) return;

            // Der Weg nach draussen führt aus dieser Ebene heraus — dort ist die
            // Stelle im Strom nichts wert.
            if (link.closest(".far")) return;

            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    page: window.scrollY,
                    list: list ? list.scrollTop : 0
                }));
            } catch (e) {}
        });
    }

    var stored = null;
    try { stored = sessionStorage.getItem(KEY); } catch (e) {}
    if (stored === null) return;
    try { sessionStorage.removeItem(KEY); } catch (e) {}

    // Nur wiederherstellen, wenn diese Seite selbst eine Filterseite ist — sonst
    // landet man auf einer Beitragsseite mitten im Text.
    if (!nav) return;

    var at;
    try { at = JSON.parse(stored); } catch (e) { return; }
    if (!at) return;

    // Ohne "instant" würde scroll-behavior: smooth die Strecke abfahren, und man sähe
    // die Seite von oben nach unten laufen.
    if (at.page > 0) window.scrollTo({ top: at.page, behavior: "instant" });
    if (list && at.list > 0) list.scrollTop = at.list;
});
