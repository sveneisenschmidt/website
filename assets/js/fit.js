document.addEventListener("DOMContentLoaded", function () {
    // Die Timeline steht neben der Galerie und soll mit ihr zusammen enden. Das
    // Markup liefert mehr Einträge als in die meisten Fenster passen, hier
    // fallen die weg, die unter die Galerie reichen würden. Ohne JavaScript
    // bleiben alle stehen, die Zahl in der Überschrift stimmt dann auch.
    var stream = document.querySelector(".writing[data-fit]");
    if (!stream) return;

    var reference = document.querySelector(stream.dataset.fit);
    var list = stream.querySelector(".writing-list");
    var count = stream.querySelector(".stream-count");
    if (!reference || !list) return;

    // Galerie und Timeline liegen in derselben Grid-Zeile, die Galerie wird
    // also auf die Höhe der längeren Spalte gedehnt. Ihre eigene Unterkante
    // taugt damit nicht als Maß, gemessen wird das letzte Element darin.
    var edge = reference.lastElementChild || reference;

    var items = Array.prototype.slice.call(list.children);
    if (!items.length) return;

    // Unter dieser Breite stehen Galerie und Timeline untereinander, dann gibt
    // es keine gemeinsame Unterkante zum Messen.
    var STACKED = 900;

    function fit() {
        items.forEach(function (item) {
            item.classList.remove("is-trimmed");
        });

        var visible = items.length;

        if (window.innerWidth > STACKED) {
            var bottom = edge.getBoundingClientRect().bottom;
            for (var i = 1; i < items.length; i++) {
                if (items[i].getBoundingClientRect().bottom > bottom) {
                    visible = i;
                    break;
                }
            }
        }

        for (var j = visible; j < items.length; j++) {
            items[j].classList.add("is-trimmed");
        }

        if (count) count.textContent = visible;
    }

    var pending = false;
    function schedule() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(function () {
            pending = false;
            fit();
        });
    }

    fit();
    window.addEventListener("resize", schedule);

    // Die Bildhöhen stehen über width/height schon vor dem Laden fest; nur
    // der Font-Swap kann die Zeilenhöhen noch verschieben. Ein Nachmessen
    // pro geladenem Bild würde Einträge sichtbar verschwinden lassen.
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(schedule);
    }
});
