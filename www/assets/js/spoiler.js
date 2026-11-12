document.addEventListener("DOMContentLoaded", function () {
    // A covered spoiler section is one button for the keyboard and for a
    // screen reader. A click, Enter or Space uncovers it for good.
    var spoilers = document.querySelectorAll(".spoiler");

    Array.prototype.forEach.call(spoilers, function (spoiler) {
        // While the section is covered, its links, buttons, images and map
        // are inert: no pointer, key or screen reader reaches them, and an
        // image has no context menu and cannot be dragged out. The Copy
        // button of copy.js is one of the buttons, so copy.js runs first
        // (Layout::scripts()). The words of an inert link cannot be
        // selected.
        function setInert(inert) {
            var elements = spoiler.querySelectorAll("a, button, img, .map");

            Array.prototype.forEach.call(elements, function (element) {
                element.inert = inert;
            });
        }

        function uncover() {
            spoiler.removeEventListener("click", onClick, true);
            spoiler.removeEventListener("keydown", onKey);
            spoiler.classList.add("is-open");
            spoiler.removeAttribute("role");
            spoiler.removeAttribute("aria-label");
            // -1 takes the section out of the Tab order and keeps the
            // focus on it, so the next Tab goes to the first link in it.
            spoiler.tabIndex = -1;
            setInert(false);
        }

        // True while the visitor has selected text of this section: the
        // selection already shows the words.
        function isSelected() {
            var selection = window.getSelection();
            return Boolean(selection) && !selection.isCollapsed && selection.containsNode(spoiler, true);
        }

        // The section takes the click before an element inside gets it, so
        // copy.js copies no covered inline code.
        function onClick(event) {
            event.preventDefault();
            event.stopPropagation();
            if (!isSelected()) uncover();
        }

        // The keys count only on the section itself. A code block that
        // scrolls sideways can have the focus too, and Space scrolls it.
        function onKey(event) {
            if (event.target !== spoiler) return;
            if (event.key !== "Enter" && event.key !== " ") return;
            event.preventDefault();
            uncover();
        }

        spoiler.tabIndex = 0;
        spoiler.setAttribute("role", "button");
        spoiler.setAttribute("aria-label", "Show spoiler");
        setInert(true);
        spoiler.addEventListener("click", onClick, true);
        spoiler.addEventListener("keydown", onKey);
    });
});
