document.addEventListener("DOMContentLoaded", function () {
    // Der Back-Link zeigt im Markup auf die Startseite und funktioniert so auch
    // ohne JavaScript. Kam der Besucher von einer Seite dieser Site, ist der
    // Verlauf das bessere Ziel. Bei einem Einstieg von außen oder direkt bleibt
    // es bei der Startseite.
    var link = document.querySelector("a[data-back]");
    if (!link) return;

    if (history.length < 2) return;

    var origin = window.location.origin;
    if (
        document.referrer !== origin &&
        document.referrer.indexOf(origin + "/") !== 0
    )
        return;

    link.addEventListener("click", function (event) {
        if (
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        )
            return;

        event.preventDefault();
        history.back();
    });
});
