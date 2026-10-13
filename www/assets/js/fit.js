document.addEventListener("DOMContentLoaded", function () {
    // The timeline is beside the pictures and must end where they end. The
    // HTML contains more entries than most windows can show. This script
    // hides the entries that would reach below the pictures and updates the
    // count in the heading. Without JavaScript all entries stay, and the
    // count in the HTML matches them.
    var stream = document.querySelector(".writing[data-fit]");
    if (!stream) return;

    var reference = document.querySelector(stream.dataset.fit);
    var list = stream.querySelector(".writing-list");
    var count = stream.querySelector(".stream-count");
    if (!reference || !list) return;

    // Pictures and timeline are in the same grid row, so the grid stretches
    // the pictures to the height of the longer column. Their bottom edge
    // therefore cannot be the measure; the script measures their last child.
    var edge = reference.lastElementChild || reference;

    var items = Array.prototype.slice.call(list.children);
    if (!items.length) return;

    // At this width and below, the timeline is below the pictures, and there
    // is no shared bottom edge to measure.
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

    // The width and height attributes fix the image heights before the
    // images load. Only the change to the web font can still move line
    // heights, so the script measures again when the fonts are ready. A new
    // measurement after each loaded image would make entries disappear
    // while the visitor watches.
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(schedule);
    }
});
