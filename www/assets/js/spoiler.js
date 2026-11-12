document.addEventListener("DOMContentLoaded", function () {
    // A covered spoiler section is one button for the keyboard and for a
    // screen reader. A click, Enter or Space uncovers it for good. Until
    // then the section takes every click and both keys before an element
    // inside gets them, so no covered link opens and copy.js copies no
    // covered code.
    var spoilers = document.querySelectorAll(".spoiler");

    Array.prototype.forEach.call(spoilers, function (spoiler) {
        function uncover() {
            spoiler.removeEventListener("click", onClick, true);
            spoiler.removeEventListener("keydown", onKey, true);
            spoiler.classList.add("is-open");
            spoiler.removeAttribute("role");
            spoiler.removeAttribute("aria-label");
            // -1 takes the section out of the Tab order and keeps the
            // focus on it. Without the attribute the focus would go back
            // to the top of the page.
            spoiler.tabIndex = -1;
        }

        // True while the visitor has selected text of this section: the
        // selection already shows the words.
        function isSelected() {
            var selection = window.getSelection();
            return Boolean(selection) && !selection.isCollapsed && selection.containsNode(spoiler, true);
        }

        function onClick(event) {
            event.preventDefault();
            event.stopPropagation();
            if (!isSelected()) uncover();
        }

        function onKey(event) {
            if (event.key !== "Enter" && event.key !== " ") return;
            event.preventDefault();
            event.stopPropagation();
            uncover();
        }

        spoiler.tabIndex = 0;
        spoiler.setAttribute("role", "button");
        spoiler.setAttribute("aria-label", "Show spoiler");
        spoiler.addEventListener("click", onClick, true);
        spoiler.addEventListener("keydown", onKey, true);
    });
});
