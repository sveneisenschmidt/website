document.addEventListener("DOMContentLoaded", function () {
    // A spoiler section shows its words on a click, or on Enter or Space
    // while it has the focus, and then stays open. The first click inside a
    // covered section only uncovers it: the section handles the click
    // before a link, an image or copy.js gets it, and stops it there.
    var spoilers = document.querySelectorAll(".spoiler");

    Array.prototype.forEach.call(spoilers, function (spoiler) {
        function open(event) {
            event.preventDefault();
            event.stopPropagation();
            spoiler.classList.add("is-open");
            spoiler.removeAttribute("tabindex");
        }

        spoiler.tabIndex = 0;

        spoiler.addEventListener("click", function (event) {
            if (spoiler.classList.contains("is-open")) return;

            // A click that ends a text selection uncovers nothing: the
            // selection already shows the words.
            var selection = window.getSelection();
            if (selection && selection.toString()) return;

            open(event);
        }, true);

        spoiler.addEventListener("keydown", function (event) {
            if (spoiler.classList.contains("is-open") || event.target !== spoiler) return;
            if (event.key === "Enter" || event.key === " ") open(event);
        });
    });
});
